<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$me  = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_str('action', 10) === 'save') {
    $id       = (int)post_str('id', 10);
    $sid      = (int)post_str('staff_id', 10);
    $category = post_str('category', 50);
    $amount   = post_str('amount', 14);
    $reason   = post_str('reason', 255);
    $date     = post_str('deduction_date', 10);
    $month    = (int)post_str('period_month', 2);
    $year     = (int)post_str('period_year', 4);
    $status   = post_str('status', 10);

    $old = null;
    if ($id > 0) {
        $o = $pdo->prepare('SELECT * FROM deductions WHERE id = ?');
        $o->execute([$id]);
        $old = $o->fetch();
    }
    $sq = $pdo->prepare('SELECT salary FROM staff WHERE id = ?');
    $sq->execute([$sid]);
    $salary = $sq->fetchColumn();

    $err = null;
    if ($id > 0 && !$old) $err = 'Deduction record not found.';
    elseif ($salary === false) $err = 'Select a staff member.';
    elseif (!in_array($category, DEDUCTION_CATEGORIES, true)) $err = 'Select a valid deduction category.';
    elseif (!valid_money($amount) || (float)$amount <= 0) $err = 'Enter a deduction amount greater than zero.';
    elseif ((float)$amount > (float)$salary) $err = 'The deduction cannot be more than the staff member\'s monthly salary.';
    elseif ($reason === '') $err = 'Enter the reason for the deduction.';
    elseif (!valid_date($date)) $err = 'Enter a valid deduction date.';
    elseif ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) $err = 'Select a valid payroll period.';
    elseif (!in_array($status, ['active', 'cancelled'], true)) $err = 'Select a valid status.';
    elseif (period_locked($sid, $month, $year) || ($old && period_locked((int)$old['staff_id'], (int)$old['period_month'], (int)$old['period_year']))) {
        $err = 'Payroll for that period is already approved or paid, so its deductions are locked.';
    }
    if (!$err) {
        $amt = number_format((float)$amount, 2, '.', '');
        $dup = $pdo->prepare('SELECT COUNT(*) FROM deductions WHERE staff_id = ? AND category = ? AND amount = ? AND deduction_date = ? AND reason = ? AND status = \'active\' AND id <> ?');
        $dup->execute([$sid, $category, $amt, $date, $reason, $id]);
        if ($status === 'active' && (int)$dup->fetchColumn()) {
            $err = 'An identical deduction already exists for this staff member.';
        }
    }

    if ($err) {
        flash_set('error', $err);
    } elseif ($id > 0) {
        $pdo->prepare('UPDATE deductions SET staff_id=?, category=?, amount=?, reason=?, deduction_date=?, period_month=?, period_year=?, status=? WHERE id=?')
            ->execute([$sid, $category, $amt, $reason, $date, $month, $year, $status, $id]);
        audit_log('deduction_updated', 'deduction', $id, ['old_amount' => $old['amount'], 'new_amount' => $amt, 'status' => $status]);
        flash_set('success', 'Deduction updated.');
    } else {
        $pdo->prepare('INSERT INTO deductions (staff_id, category, amount, reason, deduction_date, period_month, period_year, status, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$sid, $category, $amt, $reason, $date, $month, $year, $status, $me['id']]);
        audit_log('deduction_created', 'deduction', (int)$pdo->lastInsertId(), ['staff_id' => $sid, 'amount' => $amt, 'category' => $category]);
        flash_set('success', 'Deduction added.');
    }
    redirect('admin/deductions.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : ''));
}

$q      = get_str('q');
$staffF = get_int('staff');
$cat    = get_str('category', 50);
$status = get_str('status', 10);
$from   = get_str('from', 10);
$to     = get_str('to', 10);
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(u.full_name LIKE :q1 OR s.staff_code LIKE :q2 OR x.reason LIKE :q3)';
    $params[':q1'] = $params[':q2'] = $params[':q3'] = '%' . $q . '%';
}
if ($staffF > 0) { $where[] = 'x.staff_id = :sid'; $params[':sid'] = $staffF; }
if (in_array($cat, DEDUCTION_CATEGORIES, true)) { $where[] = 'x.category = :cat'; $params[':cat'] = $cat; }
if (in_array($status, ['active', 'cancelled'], true)) { $where[] = 'x.status = :st'; $params[':st'] = $status; }
if (valid_date($from)) { $where[] = 'x.deduction_date >= :from'; $params[':from'] = $from; }
if (valid_date($to)) { $where[] = 'x.deduction_date <= :to'; $params[':to'] = $to; }

$sql = 'SELECT x.id, x.staff_id, x.category, x.amount, x.reason, x.deduction_date, x.period_month, x.period_year, x.status, u.full_name, s.staff_code
        FROM deductions x JOIN staff s ON s.id = x.staff_id JOIN users u ON u.id = s.user_id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY x.deduction_date DESC, x.id DESC';
$list = paginate($sql, $params, max(1, get_int('page')), 12);
$staffAll = all_staff_list();
$staffActive = active_staff_list();

$pageTitle = 'Deductions Management';
$navActive = 'deductions';
$pageSub   = 'Active deductions are subtracted in payroll for their period.';
$pageAction = modal_btn('dedModal', '＋ Add deduction', ['id' => '', 'period_month' => (int)date('n'), 'period_year' => (int)date('Y'), 'deduction_date' => date('Y-m-d')], 'Add Deduction');
include __DIR__ . '/../includes/header.php';
?>
<section class="panel filter-panel">
    <form class="row g-2" method="get">
        <div class="col-md-3"><input class="form-control" name="q" placeholder="Search staff or reason" value="<?= e($q) ?>"></div>
        <div class="col-md-3"><select class="form-select" name="staff"><option value="">All staff</option>
            <?php foreach ($staffAll as $s): ?><option value="<?= (int)$s['id'] ?>"<?= $staffF === (int)$s['id'] ? ' selected' : '' ?>><?= e($s['full_name'] . ' (' . $s['staff_code'] . ')') ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><select class="form-select" name="category"><option value="">All categories</option>
            <?php foreach (DEDUCTION_CATEGORIES as $c): ?><option<?= $cat === $c ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><select class="form-select" name="status"><option value="">All statuses</option>
            <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option><option value="cancelled"<?= $status === 'cancelled' ? ' selected' : '' ?>>Cancelled</option></select></div>
        <div class="col-md-3"><input type="date" class="form-control" name="from" value="<?= e($from) ?>" title="From date"></div>
        <div class="col-md-3"><input type="date" class="form-control" name="to" value="<?= e($to) ?>" title="To date"></div>
        <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary flex-fill">Filter</button><a class="btn btn-outline-primary" href="deductions.php">Reset</a></div>
    </form>
</section>

<section class="panel"><div class="table-wrap">
    <table class="table table-hover">
        <thead><tr><th>Date</th><th>Staff</th><th>Category</th><th>Reason</th><th>Period</th><th class="text-end">Amount</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $r):
            $fill = ['id' => $r['id'], 'staff_id' => $r['staff_id'], 'category' => $r['category'], 'amount' => $r['amount'], 'reason' => $r['reason'],
                     'deduction_date' => $r['deduction_date'], 'period_month' => $r['period_month'], 'period_year' => $r['period_year'], 'status' => $r['status']];
        ?>
            <tr>
                <td><?= e(fmt_date($r['deduction_date'])) ?></td>
                <td><?= e($r['full_name']) ?> <span class="text-muted tiny"><?= e($r['staff_code']) ?></span></td>
                <td><?= e($r['category']) ?></td>
                <td><?= e(mb_strimwidth($r['reason'], 0, 60, '…')) ?></td>
                <td><?= e(MONTH_NAMES[(int)$r['period_month']] . ' ' . $r['period_year']) ?></td>
                <td class="text-end"><?= money($r['amount']) ?></td>
                <td><?= badge($r['status']) ?></td>
                <td class="text-end"><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#dedModal" data-title="Edit Deduction" data-fill='<?= json_attr($fill) ?>' title="Edit"><i class="bi bi-pencil"></i></button></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$list['rows']): ?><tr><td colspan="8"><div class="empty-state"><i class="bi bi-dash-circle"></i>No deductions found.</div></td></tr><?php endif; ?>
        </tbody>
    </table>
</div></section>
<?= render_pagination($list) ?>

<div class="modal fade" id="dedModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="">
        <div class="modal-header"><h5 class="modal-title">Add Deduction</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-md-6"><label class="form-label">Staff member *</label><select class="form-select" name="staff_id" required><option value="">Select staff</option>
                <?php foreach ($staffAll as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['full_name'] . ' (' . $s['staff_code'] . ')') ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">Category *</label><select class="form-select" name="category" required>
                <?php foreach (DEDUCTION_CATEGORIES as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">Amount *</label><input type="number" step="0.01" min="0.01" class="form-control" name="amount" required></div>
            <div class="col-12"><label class="form-label">Reason *</label><input class="form-control" name="reason" required maxlength="255"></div>
            <div class="col-md-3"><label class="form-label">Date *</label><input type="date" class="form-control" name="deduction_date" required></div>
            <div class="col-md-3"><label class="form-label">Payroll month *</label><?= month_select('period_month', (int)date('n')) ?></div>
            <div class="col-md-3"><label class="form-label">Payroll year *</label><?= year_select('period_year', (int)date('Y')) ?></div>
            <div class="col-md-3"><label class="form-label">Status *</label><select class="form-select" name="status" required><option value="active">Active</option><option value="cancelled">Cancelled</option></select></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
    </form>
</div></div></div>
<?php include __DIR__ . '/../includes/footer.php';
