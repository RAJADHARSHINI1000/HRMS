<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$me  = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_str('action', 10) === 'review') {
    $id       = (int)post_str('id', 10);
    $decision = post_str('decision', 10);
    $comments = post_str('admin_comments', 500);

    $cur = $pdo->prepare('SELECT id, staff_id, status FROM time_permissions WHERE id = ?');
    $cur->execute([$id]);
    $row = $cur->fetch();
    if (!$row) {
        flash_set('error', 'Permission request not found.');
    } elseif (!in_array($decision, ['approved', 'rejected'], true)) {
        flash_set('error', 'Choose Approve or Reject.');
    } elseif ($row['status'] !== 'pending') {
        flash_set('error', 'This request has already been reviewed.');
    } elseif (!empty($me['staff_id']) && (int)$me['staff_id'] === (int)$row['staff_id']) {
        flash_set('error', 'You cannot review your own request.');
    } else {
        $pdo->prepare('UPDATE time_permissions SET status = ?, reviewed_by = ?, reviewed_at = NOW(), admin_comments = ? WHERE id = ? AND status = \'pending\'')
            ->execute([$decision, $me['id'], $comments ?: null, $id]);
        audit_log('permission_' . $decision, 'time_permission', $id, ['comments' => $comments]);
        flash_set('success', 'Permission request ' . $decision . '.');
    }
    redirect('admin/timepermission.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : ''));
}

$q      = get_str('q');
$dept   = get_int('department');
$status = get_str('status', 10);
$fromD  = get_str('from_date', 10);
$fromT  = get_str('from_time', 5);
$toD    = get_str('to_date', 10);
$toT    = get_str('to_time', 5);

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(u.full_name LIKE :q1 OR s.staff_code LIKE :q2 OR t.reason LIKE :q3)';
    $params[':q1'] = $params[':q2'] = $params[':q3'] = '%' . $q . '%';
}
if ($dept > 0) { $where[] = 's.department_id = :dept'; $params[':dept'] = $dept; }
if (in_array($status, ['pending', 'approved', 'rejected'], true)) { $where[] = 't.status = :st'; $params[':st'] = $status; }
// Date + time range filters (requests that start on/after the From point and end on/before the To point)
if (valid_date($fromD)) {
    $ft = normalize_time($fromT) ?? '00:00:00';
    $where[] = "TIMESTAMP(t.from_date, t.from_time) >= :fdt";
    $params[':fdt'] = $fromD . ' ' . $ft;
}
if (valid_date($toD)) {
    $tt = normalize_time($toT) ?? '23:59:59';
    $where[] = "TIMESTAMP(t.to_date, t.to_time) <= :tdt";
    $params[':tdt'] = $toD . ' ' . $tt;
}

$sql = 'SELECT t.id, t.staff_id, t.from_date, t.from_time, t.to_date, t.to_time, t.reason, t.status, t.reviewed_at, t.admin_comments, t.created_at,
               u.full_name, s.staff_code, d.name AS department, ru.full_name AS reviewer
        FROM time_permissions t JOIN staff s ON s.id = t.staff_id JOIN users u ON u.id = s.user_id
        LEFT JOIN departments d ON d.id = s.department_id LEFT JOIN users ru ON ru.id = t.reviewed_by'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY (t.status = \'pending\') DESC, t.created_at DESC';
$list  = paginate($sql, $params, max(1, get_int('page')), 12);
$depts = departments();

$pageTitle = 'Time Permission';
$navActive = 'timepermission';
$pageSub = 'Review short-time permission requests.';
include __DIR__ . '/../includes/header.php';
?>
<section class="panel filter-panel">
    <form class="row g-2" method="get">
        <div class="col-md-4"><label class="form-label">Search</label><input class="form-control" name="q" placeholder="Name, Staff ID or reason" value="<?= e($q) ?>"></div>
        <div class="col-md-4"><label class="form-label">Department</label><select class="form-select" name="department"><option value="">All departments</option>
            <?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= $dept === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="status"><option value="">All statuses</option>
            <?php foreach (['pending', 'approved', 'rejected'] as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label">From date</label><input type="date" class="form-control" name="from_date" value="<?= e($fromD) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label">From time</label><input type="time" class="form-control" name="from_time" value="<?= e($fromT) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label">To date</label><input type="date" class="form-control" name="to_date" value="<?= e($toD) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label">To time</label><input type="time" class="form-control" name="to_time" value="<?= e($toT) ?>"></div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-primary">Filter</button><a class="btn btn-outline-primary" href="timepermission.php">Reset</a></div>
    </form>
</section>

<section class="panel"><div class="table-wrap">
    <table class="table table-hover">
        <thead><tr><th>Staff</th><th>From</th><th>To</th><th>Reason</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $r):
            $view = [
                'full_name' => $r['full_name'] . ' (' . $r['staff_code'] . ')', 'department' => $r['department'],
                'from' => fmt_date($r['from_date']) . ', ' . fmt_time($r['from_time']), 'to' => fmt_date($r['to_date']) . ', ' . fmt_time($r['to_time']),
                'reason' => $r['reason'], 'created_at' => fmt_datetime($r['created_at']), 'status' => ucfirst($r['status']),
                'reviewer' => $r['reviewer'], 'reviewed_at' => $r['reviewed_at'] ? fmt_datetime($r['reviewed_at']) : null, 'admin_comments' => $r['admin_comments'],
            ];
        ?>
            <tr>
                <td><?= e($r['full_name']) ?> <span class="text-muted tiny"><?= e($r['staff_code']) ?></span></td>
                <td><?= e(fmt_date($r['from_date'])) ?><div class="text-muted tiny"><?= e(fmt_time($r['from_time'])) ?></div></td>
                <td><?= e(fmt_date($r['to_date'])) ?><div class="text-muted tiny"><?= e(fmt_time($r['to_time'])) ?></div></td>
                <td style="max-width:260px"><?= e(mb_strimwidth($r['reason'], 0, 70, '…')) ?></td>
                <td><?= badge($r['status']) ?></td>
                <td class="text-end text-nowrap">
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#viewModal" data-fill='<?= json_attr($view) ?>' title="View"><i class="bi bi-eye"></i></button>
                    <?php if ($r['status'] === 'pending' && (int)($me['staff_id'] ?? 0) !== (int)$r['staff_id']): ?>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#reviewModal" data-fill='<?= json_attr(['id' => $r['id'], 'admin_comments' => '']) ?>'>Review</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$list['rows']): ?><tr><td colspan="6"><div class="empty-state"><i class="bi bi-clock-history"></i>No permission requests found.</div></td></tr><?php endif; ?>
        </tbody>
    </table>
</div></section>
<?= render_pagination($list) ?>

<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Permission Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <?php foreach (['full_name' => 'Staff', 'department' => 'Department', 'from' => 'From', 'to' => 'To', 'reason' => 'Reason', 'created_at' => 'Requested on',
                        'status' => 'Status', 'reviewer' => 'Reviewed by', 'reviewed_at' => 'Reviewed on', 'admin_comments' => 'Admin comments'] as $k => $label): ?>
            <div class="detail-row"><span class="k"><?= e($label) ?></span><span class="v" data-view="<?= e($k) ?>">—</span></div>
        <?php endforeach; ?>
    </div>
</div></div></div>

<div class="modal fade" id="reviewModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="review"><input type="hidden" name="id" value="">
        <div class="modal-header"><h5 class="modal-title">Review Permission Request</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><label class="form-label">Comments (optional)</label><textarea class="form-control" name="admin_comments" rows="3" maxlength="500"></textarea></div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-danger" type="submit" name="decision" value="rejected">Reject</button>
            <button class="btn btn-success" type="submit" name="decision" value="approved">Approve</button>
        </div>
    </form>
</div></div></div>
<?php include __DIR__ . '/../includes/footer.php';
