<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$me  = require_admin();
$pdo = db();

function staff_validate(array $in, ?int $staffId, ?int $userId, bool $isAdd): ?string
{
    $pdo = db();
    if (mb_strlen($in['full_name']) < 2) return 'Enter the staff member\'s full name.';
    if (!filter_var($in['email'], FILTER_VALIDATE_EMAIL)) return 'Enter a valid email address.';
    if (!preg_match('/^[A-Za-z0-9_-]{2,20}$/', $in['staff_code'])) return 'Staff ID may contain letters, numbers, - and _ (2-20 characters).';
    if ($in['phone'] !== '' && !preg_match('/^[0-9+()\-\s]{6,20}$/', $in['phone'])) return 'Enter a valid phone number.';
    if (!valid_date($in['joining_date'])) return 'Enter a valid joining date.';
    if (!valid_money($in['salary'])) return 'Enter a valid salary amount (up to 2 decimals).';
    if (!in_array($in['role'], ['admin', 'staff'], true)) return 'Select a valid role.';
    if (mb_strlen($in['department']) > 100) return 'Department name is too long (max 100 characters).';
    $c = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?');
    $c->execute([$in['email'], $userId ?? 0]);
    if ((int)$c->fetchColumn()) return 'That email address is already used by another account.';
    $c = $pdo->prepare('SELECT COUNT(*) FROM staff WHERE staff_code = ? AND id <> ?');
    $c->execute([$in['staff_code'], $staffId ?? 0]);
    if ((int)$c->fetchColumn()) return 'That Staff ID already exists.';
    if ($isAdd && strlen($in['password']) < 8) return 'The initial password must be at least 8 characters.';
    if (!$isAdd && $in['password'] !== '' && strlen($in['password']) < 8) return 'The new password must be at least 8 characters.';
    return null;
}

function staff_input(): array
{
    return [
        'full_name' => post_str('full_name', 100), 'email' => mb_strtolower(post_str('email', 150)),
        'staff_code' => post_str('staff_code', 20), 'phone' => post_str('phone', 20),
        'department' => post_str('department', 100), 'designation' => post_str('designation', 100),
        'joining_date' => post_str('joining_date', 10), 'salary' => post_str('salary', 14),
        'role' => post_str('role', 10), 'address' => post_str('address', 255),
        'password' => (string)($_POST['password'] ?? ''),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_str('action', 20);

    if ($action === 'add') {
        $in = staff_input();
        if ($in['staff_code'] === '') {
            $n = (int)$pdo->query('SELECT COALESCE(MAX(id),0) + 1 FROM staff')->fetchColumn();
            do {
                $in['staff_code'] = 'EMP' . str_pad((string)$n++, 3, '0', STR_PAD_LEFT);
                $c = $pdo->prepare('SELECT COUNT(*) FROM staff WHERE staff_code = ?');
                $c->execute([$in['staff_code']]);
            } while ((int)$c->fetchColumn());
        }
        $err = staff_validate($in, null, null, true);
        if ($err) {
            flash_set('error', $err);
        } else {
            try {
                $pdo->beginTransaction();
                $deptId = department_id_for($in['department']);
                $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, is_active) VALUES (?,?,?,?,1)')
                    ->execute([$in['full_name'], $in['email'], password_hash($in['password'], PASSWORD_DEFAULT), $in['role']]);
                $uid = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO staff (user_id, staff_code, phone, department_id, designation, joining_date, employment_status, salary, address)
                               VALUES (?,?,?,?,?,?, 'active', ?, ?)")
                    ->execute([$uid, $in['staff_code'], $in['phone'] ?: null, $deptId, $in['designation'] ?: null,
                               $in['joining_date'], number_format((float)$in['salary'], 2, '.', ''), $in['address'] ?: null]);
                $sid = (int)$pdo->lastInsertId();
                $pdo->commit();
                audit_log('staff_created', 'staff', $sid, ['staff_code' => $in['staff_code'], 'role' => $in['role']]);
                flash_set('success', 'Staff member ' . $in['full_name'] . ' added (Staff ID ' . $in['staff_code'] . ').');
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('staff add failed: ' . $ex->getMessage());
                flash_set('error', 'Could not save the staff member. Email or Staff ID may already exist.');
            }
        }
    } elseif ($action === 'edit') {
        $sid = (int)post_str('id', 10);
        $cur = $pdo->prepare('SELECT s.id, s.user_id, u.role FROM staff s JOIN users u ON u.id = s.user_id WHERE s.id = ?');
        $cur->execute([$sid]);
        $row = $cur->fetch();
        if (!$row) {
            flash_set('error', 'Staff record not found.');
        } else {
            $in = staff_input();
            $err = staff_validate($in, $sid, (int)$row['user_id'], false);
            if (!$err && (int)$row['user_id'] === (int)$me['id'] && $in['role'] !== 'admin') {
                $err = 'You cannot remove your own Admin role.';
            }
            if ($err) {
                flash_set('error', $err);
            } else {
                try {
                    $pdo->beginTransaction();
                    $deptId = department_id_for($in['department']);
                    $pdo->prepare('UPDATE users SET full_name = ?, email = ?, role = ? WHERE id = ?')
                        ->execute([$in['full_name'], $in['email'], $in['role'], $row['user_id']]);
                    if ($in['password'] !== '') {
                        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                            ->execute([password_hash($in['password'], PASSWORD_DEFAULT), $row['user_id']]);
                    }
                    $pdo->prepare('UPDATE staff SET staff_code = ?, phone = ?, department_id = ?, designation = ?, joining_date = ?, salary = ?, address = ? WHERE id = ?')
                        ->execute([$in['staff_code'], $in['phone'] ?: null, $deptId, $in['designation'] ?: null,
                                   $in['joining_date'], number_format((float)$in['salary'], 2, '.', ''), $in['address'] ?: null, $sid]);
                    $pdo->commit();
                    audit_log('staff_updated', 'staff', $sid, ['password_reset' => $in['password'] !== '', 'role' => $in['role'], 'salary' => $in['salary']]);
                    flash_set('success', 'Staff details updated.');
                } catch (PDOException $ex) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('staff edit failed: ' . $ex->getMessage());
                    flash_set('error', 'Could not update the staff member. Email or Staff ID may already exist.');
                }
            }
        }
    } elseif ($action === 'toggle') {
        $sid = (int)post_str('id', 10);
        $cur = $pdo->prepare('SELECT s.id, s.user_id, s.employment_status FROM staff s WHERE s.id = ?');
        $cur->execute([$sid]);
        $row = $cur->fetch();
        if (!$row) {
            flash_set('error', 'Staff record not found.');
        } elseif ((int)$row['user_id'] === (int)$me['id']) {
            flash_set('error', 'You cannot deactivate your own account.');
        } else {
            $new = $row['employment_status'] === 'active' ? 'inactive' : 'active';
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE staff SET employment_status = ? WHERE id = ?')->execute([$new, $sid]);
            $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$new === 'active' ? 1 : 0, $row['user_id']]);
            $pdo->commit();
            audit_log($new === 'active' ? 'staff_reactivated' : 'staff_deactivated', 'staff', $sid);
            flash_set('success', 'Staff member ' . ($new === 'active' ? 'reactivated' : 'deactivated') . '.');
        }
    }
    redirect('admin/staff.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : ''));
}

// ---- list ----
$q = get_str('q');
$dept = get_int('department');
$status = get_str('status', 10);
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(s.staff_code LIKE :q1 OR u.full_name LIKE :q2 OR u.email LIKE :q3)';
    $params[':q1'] = $params[':q2'] = $params[':q3'] = '%' . $q . '%';
}
if ($dept > 0) { $where[] = 's.department_id = :dept'; $params[':dept'] = $dept; }
if (in_array($status, ['active', 'inactive'], true)) { $where[] = 's.employment_status = :st'; $params[':st'] = $status; }

$sql = 'SELECT s.id, s.staff_code, s.phone, s.department_id, d.name AS department, s.designation, s.joining_date,
               s.employment_status, s.salary, s.address, u.full_name, u.email, u.role, u.id AS user_id
        FROM staff s JOIN users u ON u.id = s.user_id LEFT JOIN departments d ON d.id = s.department_id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY s.staff_code';
$list = paginate($sql, $params, max(1, get_int('page')), 10);
$depts = departments();

$pageTitle = 'Staff Management';
$navActive = 'staff';
$pageSub   = 'Manage employee records and team information.';
$pageAction = modal_btn('staffModal', '＋ Add new staff', ['id' => '', 'action' => 'add'], 'Add Staff');
$cnt = $pdo->query("SELECT COUNT(*) total, SUM(employment_status = 'active') active, SUM(joining_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) newm FROM staff")->fetch();
$deptCount = (int)$pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn();
$note = fn(string $t) => '<span class="positive">↗</span> ' . e($t);
include __DIR__ . '/../includes/header.php';
?>
<div class="stats">
    <?= stat_html('Total employees', (string)(int)$cnt['total'], '♙', $note('Updated just now')) ?>
    <?= stat_html('Active employees', (string)(int)$cnt['active'], '✓', $note('Can log in')) ?>
    <?= stat_html('Departments', (string)$deptCount, '▦', $note('Configured')) ?>
    <?= stat_html('New this month', (string)(int)$cnt['newm'], '＋', $note('Joined this month')) ?>
</div>
<section class="panel filter-panel">
    <form class="row g-2" method="get">
        <div class="col-md-4"><input class="form-control" name="q" placeholder="Search Staff ID, name or email" value="<?= e($q) ?>"></div>
        <div class="col-md-3"><select class="form-select" name="department"><option value="">All departments</option>
            <?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= $dept === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><select class="form-select" name="status"><option value="">All statuses</option>
            <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option>
            <option value="inactive"<?= $status === 'inactive' ? ' selected' : '' ?>>Inactive</option></select></div>
        <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary flex-fill">Filter</button><a class="btn btn-outline-primary" href="staff.php">Reset</a></div>
    </form>
</section>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>Staff ID</th><th>Name</th><th>Department</th><th>Designation</th><th>Role</th><th>Joined</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($list['rows'] as $r):
                $fill = [
                    'id' => $r['id'], 'action' => 'edit', 'full_name' => $r['full_name'], 'email' => $r['email'], 'staff_code' => $r['staff_code'],
                    'phone' => $r['phone'], 'department' => $r['department'], 'designation' => $r['designation'], 'joining_date' => $r['joining_date'],
                    'salary' => $r['salary'], 'role' => $r['role'], 'address' => $r['address'], 'password' => '',
                ];
                $view = [
                    'staff_code' => $r['staff_code'], 'full_name' => $r['full_name'], 'email' => $r['email'], 'phone' => $r['phone'],
                    'department' => $r['department'], 'designation' => $r['designation'], 'joining_date' => fmt_date($r['joining_date']),
                    'employment_status' => ucfirst($r['employment_status']), 'role' => ucfirst($r['role']),
                    'salary' => number_format((float)$r['salary'], 2), 'address' => $r['address'],
                ];
            ?>
                <tr>
                    <td class="fw-medium"><?= e($r['staff_code']) ?></td>
                    <td><div class="person"><div class="avatar"><?= e(mb_strtoupper(mb_substr($r['full_name'], 0, 1))) ?></div><div><strong><?= e($r['full_name']) ?></strong><small><?= e($r['email']) ?></small></div></div></td>
                    <td><?= e($r['department'] ?? '—') ?></td>
                    <td><?= e($r['designation'] ?? '—') ?></td>
                    <td><span class="badge <?= $r['role'] === 'admin' ? 'badge-soft-primary' : 'badge-soft-secondary' ?>"><?= $r['role'] === 'admin' ? 'Admin / HR' : 'Staff' ?></span></td>
                    <td><?= e(fmt_date($r['joining_date'])) ?></td>
                    <td><?= badge($r['employment_status']) ?></td>
                    <td class="text-end text-nowrap">
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#viewModal" data-fill='<?= json_attr($view) ?>' title="View"><i class="bi bi-eye"></i></button>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#staffModal" data-title="Edit Staff" data-fill='<?= json_attr($fill) ?>' title="Edit"><i class="bi bi-pencil"></i></button>
                        <?php if ((int)$r['user_id'] !== (int)$me['id']): ?>
                        <form method="post" class="d-inline" data-confirm="<?= $r['employment_status'] === 'active' ? 'Deactivate this staff member? They will no longer be able to log in.' : 'Reactivate this staff member?' ?>">
                            <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-sm <?= $r['employment_status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>" title="<?= $r['employment_status'] === 'active' ? 'Deactivate' : 'Reactivate' ?>">
                                <i class="bi <?= $r['employment_status'] === 'active' ? 'bi-person-dash' : 'bi-person-check' ?>"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$list['rows']): ?><tr><td colspan="8"><div class="empty-state"><i class="bi bi-people"></i>No staff found.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= render_pagination($list) ?>

<!-- Add / Edit modal -->
<div class="modal fade" id="staffModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <form method="post" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="id" value="">
        <div class="modal-header"><h5 class="modal-title">Add Staff</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-md-6"><label class="form-label">Full name *</label><input class="form-control" name="full_name" required maxlength="100"></div>
            <div class="col-md-6"><label class="form-label">Email (login) *</label><input type="email" class="form-control" name="email" required maxlength="150" autocomplete="off"></div>
            <div class="col-md-4"><label class="form-label">Staff ID <span class="text-muted">(blank = auto)</span></label><input class="form-control" name="staff_code" maxlength="20"></div>
            <div class="col-md-4"><label class="form-label">Phone</label><input class="form-control" name="phone" maxlength="20"></div>
            <div class="col-md-4"><label class="form-label">Joining date *</label><input type="date" class="form-control" name="joining_date" required value="<?= e(date('Y-m-d')) ?>"></div>
            <div class="col-md-4"><label class="form-label">Department</label><input class="form-control" name="department" list="deptList" maxlength="100" placeholder="Type or pick a department" autocomplete="off">
                <datalist id="deptList"><?php foreach ($depts as $d): ?><option value="<?= e($d['name']) ?>"><?php endforeach; ?></datalist></div>
            <div class="col-md-4"><label class="form-label">Designation</label><input class="form-control" name="designation" maxlength="100"></div>
            <div class="col-md-4"><label class="form-label">Role *</label><select class="form-select" name="role" required><option value="staff">Staff</option><option value="admin">Admin / HR</option></select></div>
            <div class="col-md-6"><label class="form-label">Monthly salary *</label><input type="number" step="0.01" min="0" class="form-control" name="salary" required value="0"></div>
            <div class="col-md-6"><label class="form-label">Password <span class="text-muted">(required for new staff; blank = keep when editing)</span></label><input type="password" class="form-control" name="password" minlength="8" autocomplete="new-password"></div>
            <div class="col-12"><label class="form-label">Address</label><input class="form-control" name="address" maxlength="255"></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
    </form>
</div></div></div>

<!-- View modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Staff Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <?php foreach (['staff_code' => 'Staff ID', 'full_name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'department' => 'Department',
                        'designation' => 'Designation', 'role' => 'Role', 'joining_date' => 'Joining date', 'employment_status' => 'Status',
                        'salary' => 'Monthly salary', 'address' => 'Address'] as $k => $label): ?>
            <div class="detail-row"><span class="k"><?= e($label) ?></span><span class="v" data-view="<?= e($k) ?>">—</span></div>
        <?php endforeach; ?>
    </div>
</div></div></div>
<?php include __DIR__ . '/../includes/footer.php';
