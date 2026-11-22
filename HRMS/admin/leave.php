<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$me  = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_str('action', 10) === 'review') {
    $id       = (int)post_str('id', 10);
    $decision = post_str('decision', 10);
    $comments = post_str('admin_comments', 500);

    $cur = $pdo->prepare('SELECT id, staff_id, status FROM leave_requests WHERE id = ?');
    $cur->execute([$id]);
    $row = $cur->fetch();
    if (!$row) {
        flash_set('error', 'Leave request not found.');
    } elseif (!in_array($decision, ['approved', 'rejected'], true)) {
        flash_set('error', 'Choose Approve or Reject.');
    } elseif ($row['status'] !== 'pending') {
        flash_set('error', 'This request has already been reviewed.');
    } elseif (!empty($me['staff_id']) && (int)$me['staff_id'] === (int)$row['staff_id']) {
        flash_set('error', 'You cannot review your own request.');
    } else {
        $pdo->prepare('UPDATE leave_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW(), admin_comments = ? WHERE id = ? AND status = \'pending\'')
            ->execute([$decision, $me['id'], $comments ?: null, $id]);
        audit_log('leave_' . $decision, 'leave_request', $id, ['comments' => $comments]);
        flash_set('success', 'Leave request ' . $decision . '.');
    }
    redirect('admin/leave.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : ''));
}

$q      = get_str('q');
$staffF = get_int('staff');
$dept   = get_int('department');
$status = get_str('status', 10);
$from   = get_str('from', 10);
$to     = get_str('to', 10);

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(u.full_name LIKE :q1 OR s.staff_code LIKE :q2 OR l.reason LIKE :q3)';
    $params[':q1'] = $params[':q2'] = $params[':q3'] = '%' . $q . '%';
}
if ($staffF > 0) { $where[] = 'l.staff_id = :sid'; $params[':sid'] = $staffF; }
if ($dept > 0) { $where[] = 's.department_id = :dept'; $params[':dept'] = $dept; }
if (in_array($status, ['pending', 'approved', 'rejected'], true)) { $where[] = 'l.status = :st'; $params[':st'] = $status; }
if (valid_date($from)) { $where[] = 'l.to_date >= :from'; $params[':from'] = $from; }
if (valid_date($to)) { $where[] = 'l.from_date <= :to'; $params[':to'] = $to; }

$sql = 'SELECT l.id, l.staff_id, l.from_date, l.to_date, l.days, l.reason, l.status, l.reviewed_at, l.admin_comments, l.created_at,
               lt.name AS leave_type, u.full_name, s.staff_code, d.name AS department, ru.full_name AS reviewer
        FROM leave_requests l JOIN staff s ON s.id = l.staff_id JOIN users u ON u.id = s.user_id
        JOIN leave_types lt ON lt.id = l.leave_type_id LEFT JOIN departments d ON d.id = s.department_id
        LEFT JOIN users ru ON ru.id = l.reviewed_by'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY (l.status = \'pending\') DESC, l.created_at DESC';
$list  = paginate($sql, $params, max(1, get_int('page')), 12);
$depts = departments();
$staff = all_staff_list();

$counts = $pdo->query("SELECT status, COUNT(*) c FROM leave_requests GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'Leave Management';
$navActive = 'leave';
$pageSub = 'Review, approve or reject leave requests.';
$note = fn(string $t) => '<span class="positive">↗</span> ' . e($t);
include __DIR__ . '/../includes/header.php';
?>
<div class="stats">
    <?= stat_html('Pending', (string)(int)($counts['pending'] ?? 0), '◷', $note('Waiting for review'), '?status=pending') ?>
    <?= stat_html('Approved', (string)(int)($counts['approved'] ?? 0), '✓', $note('All time'), '?status=approved') ?>
    <?= stat_html('Rejected', (string)(int)($counts['rejected'] ?? 0), '−', $note('All time'), '?status=rejected') ?>
    <?= stat_html('Total requests', (string)array_sum(array_map('intval', $counts)), '▤', $note('All time'), 'leave.php') ?>
</div>
<section class="panel filter-panel">
    <form class="row g-2" method="get">
        <div class="col-md-4"><input class="form-control" name="q" placeholder="Search name, Staff ID or reason" value="<?= e($q) ?>"></div>
        <div class="col-md-4"><select class="form-select" name="staff"><option value="">All staff</option>
            <?php foreach ($staff as $s): ?><option value="<?= (int)$s['id'] ?>"<?= $staffF === (int)$s['id'] ? ' selected' : '' ?>><?= e($s['full_name'] . ' (' . $s['staff_code'] . ')') ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><select class="form-select" name="department"><option value="">All departments</option>
            <?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= $dept === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><input type="date" class="form-control" name="from" value="<?= e($from) ?>" title="Leave on/after"></div>
        <div class="col-md-3"><input type="date" class="form-control" name="to" value="<?= e($to) ?>" title="Leave on/before"></div>
        <div class="col-md-3"><select class="form-select" name="status"><option value="">All statuses</option>
            <?php foreach (['pending', 'approved', 'rejected'] as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary flex-fill">Filter</button><a class="btn btn-outline-primary" href="leave.php">Reset</a></div>
    </form>
</section>

<section class="panel"><div class="table-wrap">
    <table class="table table-hover">
        <thead><tr><th>Staff</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Requested</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $r):
            $view = [
                'full_name' => $r['full_name'] . ' (' . $r['staff_code'] . ')', 'department' => $r['department'], 'leave_type' => $r['leave_type'],
                'period' => fmt_date($r['from_date']) . ' to ' . fmt_date($r['to_date']), 'days' => (string)$r['days'], 'reason' => $r['reason'],
                'created_at' => fmt_datetime($r['created_at']), 'status' => ucfirst($r['status']), 'reviewer' => $r['reviewer'],
                'reviewed_at' => $r['reviewed_at'] ? fmt_datetime($r['reviewed_at']) : null, 'admin_comments' => $r['admin_comments'],
            ];
        ?>
            <tr>
                <td><?= e($r['full_name']) ?> <span class="text-muted tiny"><?= e($r['staff_code']) ?></span></td>
                <td><?= e($r['leave_type']) ?></td>
                <td><?= e(fmt_date($r['from_date'])) ?></td><td><?= e(fmt_date($r['to_date'])) ?></td>
                <td><?= (int)$r['days'] ?></td>
                <td><?= e(fmt_date($r['created_at'])) ?></td>
                <td><?= badge($r['status']) ?></td>
                <td class="text-end text-nowrap">
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#viewModal" data-fill='<?= json_attr($view) ?>' title="View"><i class="bi bi-eye"></i></button>
                    <?php if ($r['status'] === 'pending' && (int)($me['staff_id'] ?? 0) !== (int)$r['staff_id']): ?>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#reviewModal" data-fill='<?= json_attr(['id' => $r['id'], 'admin_comments' => '']) ?>'>Review</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$list['rows']): ?><tr><td colspan="8"><div class="empty-state"><i class="bi bi-calendar2-x"></i>No leave requests found.</div></td></tr><?php endif; ?>
        </tbody>
    </table>
</div></section>
<?= render_pagination($list) ?>

<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Leave Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <?php foreach (['full_name' => 'Staff', 'department' => 'Department', 'leave_type' => 'Leave type', 'period' => 'Period', 'days' => 'Days', 'reason' => 'Reason',
                        'created_at' => 'Requested on', 'status' => 'Status', 'reviewer' => 'Reviewed by', 'reviewed_at' => 'Reviewed on', 'admin_comments' => 'Admin comments'] as $k => $label): ?>
            <div class="detail-row"><span class="k"><?= e($label) ?></span><span class="v" data-view="<?= e($k) ?>">—</span></div>
        <?php endforeach; ?>
    </div>
</div></div></div>

<div class="modal fade" id="reviewModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="review"><input type="hidden" name="id" value="">
        <div class="modal-header"><h5 class="modal-title">Review Leave Request</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><label class="form-label">Comments (optional)</label><textarea class="form-control" name="admin_comments" rows="3" maxlength="500"></textarea></div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-danger" type="submit" name="decision" value="rejected">Reject</button>
            <button class="btn btn-success" type="submit" name="decision" value="approved">Approve</button>
        </div>
    </form>
</div></div></div>
<?php include __DIR__ . '/../includes/footer.php';
