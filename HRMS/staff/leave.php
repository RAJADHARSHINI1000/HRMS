<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$me  = require_staff();
$pdo = db();
$sid = (int)$me['staff_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_str('action', 10) === 'submit') {
    $typeId = (int)post_str('leave_type_id', 10);
    $from   = post_str('from_date', 10);
    $to     = post_str('to_date', 10);
    $reason = post_str('reason', 500);

    $lt = $pdo->prepare('SELECT id, name, days_per_year FROM leave_types WHERE id = ? AND is_active = 1');
    $lt->execute([$typeId]);
    $type = $lt->fetch();

    $err = null;
    if ($me['employment_status'] !== 'active') $err = 'Your account is not active.';
    elseif (!$type) $err = 'Select a leave type.';
    elseif (!valid_date($from) || !valid_date($to)) $err = 'Enter valid start and end dates.';
    elseif ($to < $from) $err = 'The end date cannot be before the start date.';
    elseif ($from < date('Y-m-d', strtotime('-30 days'))) $err = 'Leave cannot start more than 30 days in the past.';
    elseif (substr($from, 0, 4) !== substr($to, 0, 4)) $err = 'A request cannot span two calendar years. Submit them separately.';
    elseif ($reason === '') $err = 'Enter a reason.';
    else {
        $days = count_working_days($from, $to);
        if ($days < 1) {
            $err = 'The selected dates are all weekly off days.';
        } else {
            $ov = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE staff_id = ? AND status IN ('pending','approved') AND from_date <= ? AND to_date >= ?");
            $ov->execute([$sid, $to, $from]);
            if ((int)$ov->fetchColumn()) {
                $err = 'You already have a pending or approved leave request overlapping these dates.';
            } elseif ((int)$type['days_per_year'] > 0) {
                $used = $pdo->prepare("SELECT COALESCE(SUM(days),0) FROM leave_requests WHERE staff_id = ? AND leave_type_id = ? AND status IN ('pending','approved') AND YEAR(from_date) = ?");
                $used->execute([$sid, $typeId, (int)substr($from, 0, 4)]);
                $usedDays = (int)$used->fetchColumn();
                if ($usedDays + $days > (int)$type['days_per_year']) {
                    $err = $type['name'] . ': ' . $days . ' day(s) requested, but only ' . max(0, (int)$type['days_per_year'] - $usedDays) . ' of ' . (int)$type['days_per_year'] . ' days remain this year.';
                }
            }
        }
    }
    if ($err) {
        flash_set('error', $err);
    } else {
        $pdo->prepare("INSERT INTO leave_requests (staff_id, leave_type_id, from_date, to_date, days, reason, status) VALUES (?,?,?,?,?,?, 'pending')")
            ->execute([$sid, $typeId, $from, $to, $days, $reason]);
        audit_log('leave_requested', 'leave_request', (int)$pdo->lastInsertId(), ['days' => $days]);
        flash_set('success', 'Leave request submitted (' . $days . ' working day(s)).');
    }
    redirect('staff/leave.php');
}

$status = get_str('status', 10);
$where = 'l.staff_id = :sid';
$params = [':sid' => $sid];
if (in_array($status, ['pending', 'approved', 'rejected'], true)) { $where .= ' AND l.status = :st'; $params[':st'] = $status; }
$list = paginate("SELECT l.id, l.from_date, l.to_date, l.days, l.reason, l.status, l.admin_comments, l.reviewed_at, l.created_at, lt.name AS leave_type
                  FROM leave_requests l JOIN leave_types lt ON lt.id = l.leave_type_id WHERE $where ORDER BY l.created_at DESC", $params, max(1, get_int('page')), 10);
$types = active_leave_types();

$pageTitle = 'My Leave';
$navActive = 'leave';
$pageSub = 'Submit leave requests and track their status.';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card"><div class="card-header">Request Leave</div><div class="card-body">
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="submit">
                <div class="mb-2"><label class="form-label">Leave type *</label><select class="form-select" name="leave_type_id" required><option value="">Select type</option>
                    <?php foreach ($types as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?><?= (int)$t['days_per_year'] ? ' (' . (int)$t['days_per_year'] . ' days/yr)' : '' ?></option><?php endforeach; ?></select></div>
                <div class="row g-2 mb-2">
                    <div class="col-6"><label class="form-label">From *</label><input type="date" class="form-control" name="from_date" required min="<?= e(date('Y-m-d', strtotime('-30 days'))) ?>"></div>
                    <div class="col-6"><label class="form-label">To *</label><input type="date" class="form-control" name="to_date" required></div>
                </div>
                <div class="mb-3"><label class="form-label">Reason *</label><textarea class="form-control" name="reason" rows="3" required maxlength="500"></textarea></div>
                <button class="btn btn-primary w-100">Submit request</button>
                <div class="small text-muted mt-2">Weekly off days are not counted.</div>
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
                <thead><tr><th>Type</th><th>Dates</th><th>Days</th><th>Status</th><th>Admin comments</th></tr></thead>
                <tbody>
                <?php foreach ($list['rows'] as $r): ?>
                    <tr><td><?= e($r['leave_type']) ?><div class="text-muted tiny"><?= e(mb_strimwidth($r['reason'], 0, 50, '…')) ?></div></td>
                        <td><?= e(fmt_date($r['from_date'])) ?> &ndash; <?= e(fmt_date($r['to_date'])) ?></td><td><?= (int)$r['days'] ?></td>
                        <td><?= badge($r['status']) ?></td><td><?= e($r['admin_comments'] ?? '—') ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$list['rows']): ?><tr><td colspan="5"><div class="empty-state"><i class="bi bi-calendar2-x"></i>No leave requests yet.</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div></div>
        <?= render_pagination($list) ?>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php';
