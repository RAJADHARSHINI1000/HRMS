<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$me  = require_staff();
$pdo = db();
$sid = (int)$me['staff_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_str('action', 20);
    $err = null;
    if ($action === 'contact') {
        // Staff may change ONLY phone and address. Role, salary, department and status are never read from this form.
        $phone = post_str('phone', 20);
        $addr  = post_str('address', 255);
        if ($phone !== '' && !preg_match('/^[0-9+()\-\s]{6,20}$/', $phone)) $err = 'Enter a valid phone number.';
        else {
            $pdo->prepare('UPDATE staff SET phone = ?, address = ? WHERE id = ?')->execute([$phone ?: null, $addr ?: null, $sid]);
            audit_log('profile_updated', 'staff', $sid);
        }
    } elseif ($action === 'password') {
        $err = change_password((int)$me['id'], (string)($_POST['current'] ?? ''), (string)($_POST['new'] ?? ''), (string)($_POST['confirm'] ?? ''));
    } else {
        $err = 'Unknown action.';
    }
    flash_set($err ? 'error' : 'success', $err ?: 'Saved.');
    redirect('staff/profile.php');
}

$st = $pdo->prepare('SELECT s.staff_code, s.phone, s.designation, s.joining_date, s.employment_status, s.address, u.full_name, u.email, d.name AS department
                     FROM staff s JOIN users u ON u.id = s.user_id LEFT JOIN departments d ON d.id = s.department_id WHERE s.id = ?');
$st->execute([$sid]);
$p = $st->fetch();

$pageTitle = 'My Profile';
$navActive = 'profile';
$pageSub = 'Your personal and work information.';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card mb-3"><div class="card-header">Personal Details</div><div class="card-body">
            <?php foreach (['staff_code' => 'Staff ID', 'full_name' => 'Name', 'email' => 'Email', 'department' => 'Department', 'designation' => 'Designation'] as $k => $l): ?>
                <div class="detail-row"><span class="k"><?= e($l) ?></span><span class="v"><?= e($p[$k] ?? '—') ?></span></div>
            <?php endforeach; ?>
            <div class="detail-row"><span class="k">Joined</span><span class="v"><?= e(fmt_date($p['joining_date'])) ?></span></div>
            <div class="detail-row"><span class="k">Status</span><span class="v"><?= badge($p['employment_status']) ?></span></div>
        </div></div>
        <div class="card"><div class="card-header">Contact Information</div><div class="card-body">
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="contact">
                <div class="mb-2"><label class="form-label">Phone</label><input class="form-control" name="phone" maxlength="20" value="<?= e($p['phone'] ?? '') ?>"></div>
                <div class="mb-3"><label class="form-label">Address</label><input class="form-control" name="address" maxlength="255" value="<?= e($p['address'] ?? '') ?>"></div>
                <button class="btn btn-primary">Update contact details</button>
                <div class="small text-muted mt-2">To change your name, email, department or salary, please contact HR.</div>
            </form>
        </div></div>
    </div>
    <div class="col-lg-6">
        <div class="card"><div class="card-header">Change Password</div><div class="card-body">
            <form method="post" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="password">
                <div class="mb-2"><label class="form-label">Current password</label><input type="password" class="form-control" name="current" required autocomplete="current-password"></div>
                <div class="mb-2"><label class="form-label">New password (min 8)</label><input type="password" class="form-control" name="new" required minlength="8" autocomplete="new-password"></div>
                <div class="mb-3"><label class="form-label">Confirm new password</label><input type="password" class="form-control" name="confirm" required minlength="8" autocomplete="new-password"></div>
                <button class="btn btn-primary">Change password</button>
            </form>
        </div></div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php';
