<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$me  = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_str('action', 20);

    if ($action === 'save') {
        $id     = (int)post_str('id', 10);
        $sid    = (int)post_str('staff_id', 10);
        $amount = post_str('amount', 14);
        $reason = post_str('reason', 255);
        $date   = post_str('incentive_date', 10);
        $month  = (int)post_str('period_month', 2);
        $year   = (int)post_str('period_year', 4);
        $status = post_str('status', 10);

        $old = null;
        if ($id > 0) {
            $o = $pdo->prepare('SELECT * FROM incentives WHERE id = ?');
            $o->execute([$id]);
            $old = $o->fetch();
        }
        $chk = $pdo->prepare('SELECT COUNT(*) FROM staff WHERE id = ?');
        $chk->execute([$sid]);

        $err = null;
        if ($id > 0 && !$old) $err = 'Incentive record not found.';
        elseif (!(int)$chk->fetchColumn()) $err = 'Select a staff member.';
        elseif (!valid_money($amount) || (float)$amount <= 0) $err = 'Enter an incentive amount greater than zero.';
        elseif ($reason === '') $err = 'Enter the reason for the incentive.';
        elseif (!valid_date($date)) $err = 'Enter a valid incentive date.';
        elseif ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) $err = 'Select a valid payroll period.';
        elseif (!in_array($status, ['pending', 'approved', 'rejected'], true)) $err = 'Select a valid status.';
        elseif ($old && $old['payment_status'] === 'paid') $err = 'A paid incentive can no longer be edited.';
        elseif (period_locked($sid, $month, $year) || ($old && period_locked((int)$old['staff_id'], (int)$old['period_month'], (int)$old['period_year']))) {
            $err = 'Payroll for that period is already approved or paid, so its incentives are locked.';
        }

        $docName = $docOrig = null;
        if (!$err) {
            $up = store_uploaded_document($_FILES['document'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
            if (is_string($up)) $err = $up;
            elseif (is_array($up)) [$docName, $docOrig] = $up;
        }

        if ($err) {
            flash_set('error', $err);
        } else {
            $amt = number_format((float)$amount, 2, '.', '');
            $approvedBy = $status === 'approved' ? $me['id'] : null;
            if ($id > 0) {
                $sql = 'UPDATE incentives SET staff_id=?, amount=?, reason=?, incentive_date=?, period_month=?, period_year=?, status=?, approved_by=?';
                $args = [$sid, $amt, $reason, $date, $month, $year, $status, $approvedBy];
                if ($docName) {
                    $sql .= ', document_path=?, document_name=?';
                    array_push($args, $docName, $docOrig);
                    if (!empty($old['document_path'])) @unlink(ROOT_PATH . '/uploads/documents/' . basename($old['document_path']));
                }
                $pdo->prepare($sql . ' WHERE id=?')->execute([...$args, $id]);
                audit_log('incentive_updated', 'incentive', $id, ['old_amount' => $old['amount'], 'new_amount' => $amt, 'status' => $status]);
                flash_set('success', 'Incentive updated.');
            } else {
                $pdo->prepare('INSERT INTO incentives (staff_id, amount, reason, incentive_date, period_month, period_year, status, approved_by, document_path, document_name, created_by)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$sid, $amt, $reason, $date, $month, $year, $status, $approvedBy, $docName, $docOrig, $me['id']]);
                audit_log('incentive_created', 'incentive', (int)$pdo->lastInsertId(), ['staff_id' => $sid, 'amount' => $amt]);
                flash_set('success', 'Incentive added.');
            }
        }
    } elseif ($action === 'set_status') {
        $id = (int)post_str('id', 10);
        $new = post_str('status', 10);
        $o = $pdo->prepare('SELECT * FROM incentives WHERE id = ?');
        $o->execute([$id]);
        $old = $o->fetch();
        if (!$old || !in_array($new, ['approved', 'rejected'], true)) {
            flash_set('error', 'Invalid request.');
        } elseif ($old['payment_status'] === 'paid' || period_locked((int)$old['staff_id'], (int)$old['period_month'], (int)$old['period_year'])) {
            flash_set('error', 'This incentive is locked because its payroll is approved or paid.');
        } else {
            $pdo->prepare('UPDATE incentives SET status = ?, approved_by = ? WHERE id = ?')->execute([$new, $new === 'approved' ? $me['id'] : null, $id]);
            audit_log('incentive_' . $new, 'incentive', $id);
            flash_set('success', 'Incentive ' . $new . '.');
        }
    }
    redirect('admin/incentives.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : ''));
}

$q      = get_str('q');
$dept   = get_int('department');
$status = get_str('status', 10);
$pay    = get_str('payment', 10);
$from   = get_str('from', 10);
$to     = get_str('to', 10);
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(u.full_name LIKE :q1 OR s.staff_code LIKE :q2 OR i.reason LIKE :q3)';
    $params[':q1'] = $params[':q2'] = $params[':q3'] = '%' . $q . '%';
}
if ($dept > 0) { $where[] = 's.department_id = :dept'; $params[':dept'] = $dept; }
if (in_array($status, ['pending', 'approved', 'rejected'], true)) { $where[] = 'i.status = :st'; $params[':st'] = $status; }
if (in_array($pay, ['unpaid', 'paid'], true)) { $where[] = 'i.payment_status = :pay'; $params[':pay'] = $pay; }
if (valid_date($from)) { $where[] = 'i.incentive_date >= :from'; $params[':from'] = $from; }
if (valid_date($to)) { $where[] = 'i.incentive_date <= :to'; $params[':to'] = $to; }

$sql = 'SELECT i.id, i.staff_id, i.amount, i.reason, i.incentive_date, i.period_month, i.period_year, i.status, i.payment_status, i.document_name,
               u.full_name, s.staff_code, d.name AS department
        FROM incentives i JOIN staff s ON s.id = i.staff_id JOIN users u ON u.id = s.user_id LEFT JOIN departments d ON d.id = s.department_id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY i.incentive_date DESC, i.id DESC';
$list  = paginate($sql, $params, max(1, get_int('page')), 12);
$depts = departments();
$staff = all_staff_list();

$pageTitle = 'Incentive Management';
$navActive = 'incentives';
$pageSub   = 'Only <strong>approved</strong> incentives are included in payroll for their period.';
$pageAction = modal_btn('incMod' . 'al', '＋ Add incentive', ['id' => '', 'period_month' => (int)date('n'), 'period_year' => (int)date('Y'), 'incentive_date' => date('Y-m-d'), 'status' => 'approved'], 'Add Incentive');
include __DIR__ . '/../includes/header.php';
?>
<section class="panel filter-panel">
    <form class="row g-2" method="get">
        <div class="col-md-4"><input class="form-control" name="q" placeholder="Search staff or reason" value="<?= e($q) ?>"></div>
        <div class="col-md-4"><select class="form-select" name="department"><option value="">All departments</option>
            <?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= $dept === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><select class="form-select" name="status"><option value="">Approval</option>
            <?php foreach (['pending', 'approved', 'rejected'] as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><select class="form-select" name="payment"><option value="">Payment</option>
            <option value="unpaid"<?= $pay === 'unpaid' ? ' selected' : '' ?>>Unpaid</option><option value="paid"<?= $pay === 'paid' ? ' selected' : '' ?>>Paid</option></select></div>
        <div class="col-md-3"><input type="date" class="form-control" name="from" value="<?= e($from) ?>" title="From date"></div>
        <div class="col-md-3"><input type="date" class="form-control" name="to" value="<?= e($to) ?>" title="To date"></div>
        <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary flex-fill">Filter</button><a class="btn btn-outline-primary" href="incentives.php">Reset</a></div>
    </form>
</section>

<section class="panel"><div class="table-wrap">
    <table class="table table-hover">
        <thead><tr><th>Date</th><th>Staff</th><th>Reason</th><th>Period</th><th class="text-end">Amount</th><th>Approval</th><th>Payment</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $r):
            $fill = ['id' => $r['id'], 'staff_id' => $r['staff_id'], 'amount' => $r['amount'], 'reason' => $r['reason'], 'incentive_date' => $r['incentive_date'],
                     'period_month' => $r['period_month'], 'period_year' => $r['period_year'], 'status' => $r['status']];
        ?>
            <tr>
                <td><?= e(fmt_date($r['incentive_date'])) ?></td>
                <td><?= e($r['full_name']) ?> <span class="text-muted tiny"><?= e($r['staff_code']) ?></span></td>
                <td><?= e(mb_strimwidth($r['reason'], 0, 60, '…')) ?></td>
                <td><?= e(MONTH_NAMES[(int)$r['period_month']] . ' ' . $r['period_year']) ?></td>
                <td class="text-end"><?= money($r['amount']) ?></td>
                <td><?= badge($r['status']) ?></td>
                <td><?= badge($r['payment_status']) ?></td>
                <td class="text-end text-nowrap">
                    <?php if ($r['document_name']): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('download.php?type=incentive&id=' . (int)$r['id'])) ?>" title="Document: <?= e($r['document_name']) ?>"><i class="bi bi-paperclip"></i></a><?php endif; ?>
                    <?php if ($r['status'] === 'pending'): ?>
                        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-sm btn-outline-success" name="status" value="approved" title="Approve"><i class="bi bi-check-lg"></i></button>
                            <button class="btn btn-sm btn-outline-danger" name="status" value="rejected" title="Reject"><i class="bi bi-x-lg"></i></button></form>
                    <?php endif; ?>
                    <?php if ($r['payment_status'] !== 'paid'): ?>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#incModal" data-title="Edit Incentive" data-fill='<?= json_attr($fill) ?>' title="Edit"><i class="bi bi-pencil"></i></button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$list['rows']): ?><tr><td colspan="8"><div class="empty-state"><i class="bi bi-gift"></i>No incentives found.</div></td></tr><?php endif; ?>
        </tbody>
    </table>
</div></section>
<?= render_pagination($list) ?>

<div class="modal fade" id="incModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="">
        <div class="modal-header"><h5 class="modal-title">Add Incentive</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-md-6"><label class="form-label">Staff member *</label><select class="form-select" name="staff_id" required><option value="">Select staff</option>
                <?php foreach ($staff as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['full_name'] . ' (' . $s['staff_code'] . ')') ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">Amount *</label><input type="number" step="0.01" min="0.01" class="form-control" name="amount" required></div>
            <div class="col-md-3"><label class="form-label">Date *</label><input type="date" class="form-control" name="incentive_date" required></div>
            <div class="col-12"><label class="form-label">Reason *</label><input class="form-control" name="reason" required maxlength="255"></div>
            <div class="col-md-3"><label class="form-label">Payroll month *</label><?= month_select('period_month', (int)date('n')) ?></div>
            <div class="col-md-3"><label class="form-label">Payroll year *</label><?= year_select('period_year', (int)date('Y')) ?></div>
            <div class="col-md-3"><label class="form-label">Approval *</label><select class="form-select" name="status" required>
                <option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option></select></div>
            <div class="col-md-3"><label class="form-label">Document <span class="text-muted">(optional)</span></label><input type="file" class="form-control" name="document" accept=".pdf,.jpg,.jpeg,.png"></div>
            <div class="col-12 small text-muted">Documents: PDF, JPG or PNG, up to 2 MB. Uploading a new file replaces the old one.</div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
    </form>
</div></div></div>
<?php include __DIR__ . '/../includes/footer.php';
