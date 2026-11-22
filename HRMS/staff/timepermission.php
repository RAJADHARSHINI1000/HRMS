<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$me  = require_staff();
$pdo = db();
$sid = (int)$me['staff_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_str('action', 10) === 'submit') {
    $fd = post_str('from_date', 10);
    $ft = normalize_time(post_str('from_time', 8));
    $td = post_str('to_date', 10);
    $tt = normalize_time(post_str('to_time', 8));
    $reason = post_str('reason', 500);
    $maxH = (float)get_setting('permission_max_hours', '4');

    $err = null;
    if ($me['employment_status'] !== 'active') $err = 'Your account is not active.';
    elseif (!valid_date($fd) || !valid_date($td) || $ft === null || $tt === null) $err = 'Enter valid dates and times.';
    elseif ($fd < date('Y-m-d')) $err = 'Permission cannot be requested for a past date.';
    elseif (strtotime("$td $tt") <= strtotime("$fd $ft")) $err = 'The end date/time must be after the start date/time.';
    elseif ((strtotime("$td $tt") - strtotime("$fd $ft")) / 3600 > $maxH) $err = 'A permission request can be at most ' . rtrim(rtrim(number_format($maxH, 1), '0'), '.') . ' hour(s). Use a leave request for longer absences.';
    elseif ($reason === '') $err = 'Enter a reason.';
    else {
        $ov = $pdo->prepare("SELECT COUNT(*) FROM time_permissions WHERE staff_id = ? AND status IN ('pending','approved')
                             AND TIMESTAMP(from_date, from_time) < ? AND TIMESTAMP(to_date, to_time) > ?");
        $ov->execute([$sid, "$td $tt", "$fd $ft"]);
        if ((int)$ov->fetchColumn()) $err = 'You already have a pending or approved permission overlapping this time.';
    }
    if ($err) {
        flash_set('error', $err);
    } else {
        $pdo->prepare("INSERT INTO time_permissions (staff_id, from_date, from_time, to_date, to_time, reason, status) VALUES (?,?,?,?,?,?, 'pending')")
            ->execute([$sid, $fd, $ft, $td, $tt, $reason]);
        audit_log('permission_requested', 'time_permission', (int)$pdo->lastInsertId());
        flash_set('success', 'Permission request submitted.');
    }
    redirect('staff/timepermission.php');
}

$status = get_str('status', 10);
$where = 'staff_id = :sid';
$params = [':sid' => $sid];
if (in_array($status, ['pending', 'approved', 'rejected'], true)) { $where .= ' AND status = :st'; $params[':st'] = $status; }
$list = paginate("SELECT from_date, from_time, to_date, to_time, reason, status, admin_comments FROM time_permissions WHERE $where ORDER BY created_at DESC",
                 $params, max(1, get_int('page')), 10);

$pageTitle = 'My Time Permission';
$navActive = 'timepermission';
$pageSub = 'Request short-time permission and track the decision.';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card"><div class="card-header">Request Permission</div><div class="card-body">
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="submit">
                <div class="row g-2 mb-2">
                    <div class="col-7"><label class="form-label">From date *</label><input type="date" class="form-control" name="from_date" required min="<?= e(date('Y-m-d')) ?>"></div>
                    <div class="col-5"><label class="form-label">From time *</label><input type="time" class="form-control" name="from_time" required></div>
                    <div class="col-7"><label class="form-label">To date *</label><input type="date" class="form-control" name="to_date" required min="<?= e(date('Y-m-d')) ?>"></div>
                    <div class="col-5"><label class="form-label">To time *</label><input type="time" class="form-control" name="to_time" required></div>
                </div>
                <div class="mb-3"><label class="form-label">Reason *</label><textarea class="form-control" name="reason" rows="3" required maxlength="500"></textarea></div>
                <button class="btn btn-primary w-100">Submit request</button>
                <div class="small text-muted mt-2">Maximum <?= e(rtrim(rtrim(number_format((float)get_setting('permission_max_hours', '4'), 1), '0'), '.')) ?> hour(s) per request.</div>
            </form>
        </div></div>
    </div>
    <div class="col-lg-8">
        <section class="panel filter-panel">
            <form class="row g-2" method="get">
                <div class="col-8"><select class="form-select" name="status"><option value="">All statuses</option>
                    <?php foreach (['pending', 'approved', 'rejected'] as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
                <div class="col-4"><button class="btn btn-primary w-100">Filter</button></div>
            </form>
        </div></div>
        <section class="panel"><div class="table-wrap">
            <table class="table table-hover">
                <thead><tr><th>From</th><th>To</th><th>Reason</th><th>Status</th><th>Admin comments</th></tr></thead>
                <tbody>
                <?php foreach ($list['rows'] as $r): ?>
                    <tr><td><?= e(fmt_date($r['from_date'])) ?><div class="text-muted tiny"><?= e(fmt_time($r['from_time'])) ?></div></td>
                        <td><?= e(fmt_date($r['to_date'])) ?><div class="text-muted tiny"><?= e(fmt_time($r['to_time'])) ?></div></td>
                        <td><?= e(mb_strimwidth($r['reason'], 0, 60, '…')) ?></td><td><?= badge($r['status']) ?></td><td><?= e($r['admin_comments'] ?? '—') ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$list['rows']): ?><tr><td colspan="5"><div class="empty-state"><i class="bi bi-clock-history"></i>No permission requests yet.</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div></div>
        <?= render_pagination($list) ?>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php';
