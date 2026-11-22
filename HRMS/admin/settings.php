<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$me  = require_admin();
$pdo = db();

$tabs = ['general' => 'Company', 'attendance' => 'Attendance & Workdays', 'payroll' => 'Payroll', 'leave' => 'Leave Types',
         'permission' => 'Permission Rules', 'security' => 'Security', 'account' => 'My Account'];
$tab = get_str('tab', 20);
if (!isset($tabs[$tab])) $tab = 'general';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_str('action', 30);
    $tab = post_str('tab', 20);
    if (!isset($tabs[$tab])) $tab = 'general';
    $err = null;

    if ($action === 'save_general') {
        $name = post_str('company_name', 100);
        $email = post_str('company_email', 150);
        if ($name === '') $err = 'Enter the company name.';
        elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid company email.';
        else {
            save_setting('company_name', $name);
            save_setting('company_address', post_str('company_address', 255));
            save_setting('company_email', $email);
            save_setting('company_phone', post_str('company_phone', 30));
            audit_log('settings_updated', 'settings', null, ['section' => 'company']);
        }
    } elseif ($action === 'save_attendance') {
        $half  = post_str('half_day_hours', 5);
        $off   = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['weekly_off'] ?? [])), fn($d) => $d >= 1 && $d <= 7)));
        if (!is_numeric($half) || (float)$half < 1 || (float)$half > 12) $err = 'Half-day threshold must be between 1 and 12 hours.';
        elseif (count($off) >= 7) $err = 'At least one day of the week must be a working day.';
        else {
            save_setting('half_day_hours', (string)(float)$half);
            save_setting('weekly_off_days', implode(',', $off));
            audit_log('settings_updated', 'settings', null, ['section' => 'attendance']);
        }
    } elseif ($action === 'save_payroll') {
        $day = post_str('payroll_pay_day', 2);
        $cur = post_str('currency_symbol', 8);
        if (!ctype_digit($day) || (int)$day < 1 || (int)$day > 31) $err = 'Pay day must be between 1 and 31.';
        elseif ($cur === '') $err = 'Enter a currency symbol or code.';
        else {
            save_setting('payroll_pay_day', $day);
            save_setting('currency_symbol', $cur);
            audit_log('settings_updated', 'settings', null, ['section' => 'payroll']);
        }
    } elseif ($action === 'save_permission') {
        $h = post_str('permission_max_hours', 5);
        if (!is_numeric($h) || (float)$h < 0.5 || (float)$h > 12) $err = 'Maximum permission length must be between 0.5 and 12 hours.';
        else {
            save_setting('permission_max_hours', (string)(float)$h);
            audit_log('settings_updated', 'settings', null, ['section' => 'permission']);
        }
    } elseif ($action === 'save_security') {
        $t = post_str('session_timeout_minutes', 4);
        $a = post_str('max_login_attempts', 3);
        if (!ctype_digit($t) || (int)$t < 5 || (int)$t > 480) $err = 'Session timeout must be between 5 and 480 minutes.';
        elseif (!ctype_digit($a) || (int)$a < 3 || (int)$a > 20) $err = 'Allowed login attempts must be between 3 and 20.';
        else {
            save_setting('session_timeout_minutes', $t);
            save_setting('max_login_attempts', $a);
            audit_log('settings_updated', 'settings', null, ['section' => 'security']);
        }
    } elseif ($action === 'leave_type_save') {
        $id = (int)post_str('id', 10);
        $name = post_str('name', 60);
        $days = post_str('days_per_year', 3);
        if ($name === '') $err = 'Enter the leave type name.';
        elseif (!ctype_digit($days) || (int)$days > 365) $err = 'Days per year must be 0 to 365 (0 = no limit).';
        else {
            $dup = $pdo->prepare('SELECT COUNT(*) FROM leave_types WHERE name = ? AND id <> ?');
            $dup->execute([$name, $id]);
            if ((int)$dup->fetchColumn()) $err = 'A leave type with that name already exists.';
            elseif ($id > 0) {
                $pdo->prepare('UPDATE leave_types SET name = ?, days_per_year = ? WHERE id = ?')->execute([$name, (int)$days, $id]);
                audit_log('leave_type_updated', 'leave_type', $id);
            } else {
                $pdo->prepare('INSERT INTO leave_types (name, days_per_year) VALUES (?, ?)')->execute([$name, (int)$days]);
                audit_log('leave_type_created', 'leave_type', (int)$pdo->lastInsertId());
            }
        }
    } elseif ($action === 'leave_type_toggle') {
        $id = (int)post_str('id', 10);
        $pdo->prepare('UPDATE leave_types SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        audit_log('leave_type_toggled', 'leave_type', $id);
    } elseif ($action === 'account_name') {
        $name = post_str('full_name', 100);
        if (mb_strlen($name) < 2) $err = 'Enter your full name.';
        else {
            $pdo->prepare('UPDATE users SET full_name = ? WHERE id = ?')->execute([$name, $me['id']]);
            audit_log('profile_updated', 'user', (int)$me['id']);
        }
    } elseif ($action === 'account_password') {
        $err = change_password((int)$me['id'], (string)($_POST['current'] ?? ''), (string)($_POST['new'] ?? ''), (string)($_POST['confirm'] ?? ''));
    } else {
        $err = 'Unknown action.';
    }

    flash_set($err ? 'error' : 'success', $err ?: 'Saved.');
    redirect('admin/settings.php?tab=' . urlencode($tab));
}

$s = fn(string $k) => get_setting($k);
$off = weekly_off_days();
$leaveTypes = $pdo->query('SELECT id, name, days_per_year, is_active FROM leave_types ORDER BY name')->fetchAll();
$dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

$pageTitle = 'Settings';
$pageSub = 'Company, attendance, payroll, leave and security configuration.';
$navActive = 'settings';
include __DIR__ . '/../includes/header.php';
?>
<ul class="tab-pills">
    <?php foreach ($tabs as $k => $label): ?>
        <li><a class="<?= $tab === $k ? ' active' : '' ?>" href="?tab=<?= e($k) ?>"><?= e($label) ?></a></li>
    <?php endforeach; ?>
</ul>

<section class="panel" style="max-width:820px">
<?php if ($tab === 'general'): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_general"><input type="hidden" name="tab" value="general">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Company name *</label><input class="form-control" name="company_name" required maxlength="100" value="<?= e($s('company_name')) ?>"></div>
            <div class="col-md-6"><label class="form-label">Company email</label><input type="email" class="form-control" name="company_email" maxlength="150" value="<?= e($s('company_email')) ?>"></div>
            <div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="company_phone" maxlength="30" value="<?= e($s('company_phone')) ?>"></div>
            <div class="col-md-6"><label class="form-label">Address</label><input class="form-control" name="company_address" maxlength="255" value="<?= e($s('company_address')) ?>"></div>
        </div>
        <button class="btn btn-primary mt-3">Save</button>
    </form>

<?php elseif ($tab === 'attendance'): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_attendance"><input type="hidden" name="tab" value="attendance">
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Half-day below (hours)</label><input type="number" step="0.5" min="1" max="12" class="form-control" name="half_day_hours" required value="<?= e($s('half_day_hours')) ?>"></div>
            <div class="col-12"><label class="form-label d-block">Weekly off days</label>
                <?php foreach ($dayNames as $n => $label): ?>
                    <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="weekly_off[]" id="off<?= $n ?>" value="<?= $n ?>"<?= in_array($n, $off, true) ? ' checked' : '' ?>>
                        <label class="form-check-label" for="off<?= $n ?>"><?= $label ?></label></div>
                <?php endforeach; ?>
            </div>
            <div class="col-12 small text-muted">Staff can work in different time slots, so there is no Late status. A day with fewer worked hours than the half-day limit is marked <strong>Half day</strong>. Weekly off days are not counted as absent.</div>
        </div>
        <button class="btn btn-primary mt-3">Save</button>
    </form>

<?php elseif ($tab === 'payroll'): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_payroll"><input type="hidden" name="tab" value="payroll">
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Payroll period</label><input class="form-control" value="Calendar month (1st to last day)" disabled></div>
            <div class="col-md-4"><label class="form-label">Salary pay day (day of month) *</label><input type="number" min="1" max="31" class="form-control" name="payroll_pay_day" required value="<?= e($s('payroll_pay_day')) ?>"></div>
            <div class="col-md-4"><label class="form-label">Currency symbol *</label><input class="form-control" name="currency_symbol" required maxlength="8" value="<?= e($s('currency_symbol')) ?>"></div>
            <div class="col-12 small text-muted">Gross = Base salary + approved incentives. Net = Gross &minus; active deductions. No PF or tax is deducted automatically. Amounts are rounded half-up to 2 decimals.</div>
        </div>
        <button class="btn btn-primary mt-3">Save</button>
    </form>

<?php elseif ($tab === 'leave'): ?>
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="text-muted small">Days per year: 0 means no yearly limit. Deactivated types cannot be requested.</div>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#ltModal" data-reset data-title="Add Leave Type" data-fill='{"id":"","days_per_year":"0"}'><i class="bi bi-plus-lg me-1"></i>Add</button>
    </div>
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Name</th><th>Days / year</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($leaveTypes as $lt): ?>
            <tr><td><?= e($lt['name']) ?></td><td><?= (int)$lt['days_per_year'] === 0 ? 'No limit' : (int)$lt['days_per_year'] ?></td><td><?= badge($lt['is_active'] ? 'active' : 'inactive') ?></td>
                <td class="text-end text-nowrap">
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#ltModal" data-title="Edit Leave Type" data-fill='<?= json_attr(['id' => $lt['id'], 'name' => $lt['name'], 'days_per_year' => $lt['days_per_year']]) ?>'><i class="bi bi-pencil"></i></button>
                    <form method="post" class="d-inline" data-confirm="<?= $lt['is_active'] ? 'Deactivate this leave type?' : 'Activate this leave type?' ?>"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="leave_type_toggle"><input type="hidden" name="tab" value="leave"><input type="hidden" name="id" value="<?= (int)$lt['id'] ?>">
                        <button class="btn btn-sm <?= $lt['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>"><i class="bi <?= $lt['is_active'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i></button></form>
                </td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div class="modal fade" id="ltModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="leave_type_save"><input type="hidden" name="tab" value="leave"><input type="hidden" name="id" value="">
            <div class="modal-header"><h5 class="modal-title">Leave Type</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><div class="row g-3">
                <div class="col-md-8"><label class="form-label">Name *</label><input class="form-control" name="name" required maxlength="60"></div>
                <div class="col-md-4"><label class="form-label">Days / year *</label><input type="number" min="0" max="365" class="form-control" name="days_per_year" required></div>
            </div></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
        </form>
    </div></div></div>

<?php elseif ($tab === 'permission'): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_permission"><input type="hidden" name="tab" value="permission">
        <div class="row g-3"><div class="col-md-5"><label class="form-label">Maximum length of one permission request (hours) *</label>
            <input type="number" step="0.5" min="0.5" max="12" class="form-control" name="permission_max_hours" required value="<?= e($s('permission_max_hours')) ?>"></div></div>
        <button class="btn btn-primary mt-3">Save</button>
    </form>

<?php elseif ($tab === 'security'): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_security"><input type="hidden" name="tab" value="security">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Session timeout (minutes of inactivity) *</label><input type="number" min="5" max="480" class="form-control" name="session_timeout_minutes" required value="<?= e($s('session_timeout_minutes')) ?>"></div>
            <div class="col-md-6"><label class="form-label">Failed logins before a 5-minute lock *</label><input type="number" min="3" max="20" class="form-control" name="max_login_attempts" required value="<?= e($s('max_login_attempts')) ?>"></div>
        </div>
        <button class="btn btn-primary mt-3">Save</button>
    </form>

<?php else: ?>
    <div class="row g-4">
        <div class="col-md-6">
            <h6 class="mb-3">Profile</h6>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="account_name"><input type="hidden" name="tab" value="account">
                <div class="mb-2"><label class="form-label">Full name</label><input class="form-control" name="full_name" required maxlength="100" value="<?= e($me['full_name']) ?>"></div>
                <div class="mb-3"><label class="form-label">Email</label><input class="form-control" value="<?= e($me['email']) ?>" disabled></div>
                <button class="btn btn-primary">Update profile</button>
            </form>
        </div>
        <div class="col-md-6">
            <h6 class="mb-3">Change password</h6>
            <form method="post" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="account_password"><input type="hidden" name="tab" value="account">
                <div class="mb-2"><label class="form-label">Current password</label><input type="password" class="form-control" name="current" required autocomplete="current-password"></div>
                <div class="mb-2"><label class="form-label">New password (min 8)</label><input type="password" class="form-control" name="new" required minlength="8" autocomplete="new-password"></div>
                <div class="mb-3"><label class="form-label">Confirm new password</label><input type="password" class="form-control" name="confirm" required minlength="8" autocomplete="new-password"></div>
                <button class="btn btn-primary">Change password</button>
            </form>
        </div>
    </div>
<?php endif; ?>
</section>
<?php include __DIR__ . '/../includes/footer.php';
