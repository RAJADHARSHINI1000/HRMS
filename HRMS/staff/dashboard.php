<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$me  = require_staff();
$pdo = db();
$sid = (int)$me['staff_id'];

$prof = $pdo->prepare('SELECT s.staff_code, s.designation, s.joining_date, d.name AS department FROM staff s LEFT JOIN departments d ON d.id = s.department_id WHERE s.id = ?');
$prof->execute([$sid]);
$p = $prof->fetch();

$t = $pdo->prepare('SELECT check_in, check_out, status FROM attendance WHERE staff_id = ? AND att_date = CURDATE()');
$t->execute([$sid]);
$today = $t->fetch();

$m = $pdo->prepare("SELECT COUNT(*) days, COALESCE(SUM(work_minutes),0) mins
                    FROM attendance WHERE staff_id = ? AND MONTH(att_date) = MONTH(CURDATE()) AND YEAR(att_date) = YEAR(CURDATE())");
$m->execute([$sid]);
$month = $m->fetch();

$lc = $pdo->prepare("SELECT status, COUNT(*) c FROM leave_requests WHERE staff_id = ? GROUP BY status");
$lc->execute([$sid]);
$leaveCounts = $lc->fetchAll(PDO::FETCH_KEY_PAIR);

$leaves = $pdo->prepare("SELECT l.from_date, l.to_date, l.days, l.status, lt.name FROM leave_requests l JOIN leave_types lt ON lt.id = l.leave_type_id
                         WHERE l.staff_id = ? ORDER BY l.created_at DESC LIMIT 4");
$leaves->execute([$sid]);
$leaveRows = $leaves->fetchAll();

$inc = $pdo->prepare('SELECT amount, reason, incentive_date, status, payment_status FROM incentives WHERE staff_id = ? ORDER BY incentive_date DESC, id DESC LIMIT 4');
$inc->execute([$sid]);
$incRows = $inc->fetchAll();

$ps = $pdo->prepare("SELECT id, period_month, period_year, net_salary, status FROM payroll WHERE staff_id = ? AND status IN ('approved','paid')
                     ORDER BY period_year DESC, period_month DESC LIMIT 1");
$ps->execute([$sid]);
$latest = $ps->fetch();

$pageTitle = 'Dashboard';
$navActive = 'dashboard';
$heading   = 'Good ' . (date('G') < 12 ? 'morning' : (date('G') < 17 ? 'afternoon' : 'evening')) . ', ' . explode(' ', trim($me['full_name']))[0] . ' 👋';
$pageSub   = e($p['designation'] ?? 'Staff') . ' · ' . e($p['department'] ?? 'No department') . ' · ID ' . e($p['staff_code']);
$pageAction = '<div class="toolbar">'
    . '<form method="post" action="' . e(url('staff/attendance.php')) . '">' . csrf_field() . '<input type="hidden" name="action" value="checkin"><button class="btn"' . ($today ? ' disabled' : '') . '>Check in</button></form>'
    . '<form method="post" action="' . e(url('staff/attendance.php')) . '">' . csrf_field() . '<input type="hidden" name="action" value="checkout"><button class="btn outline"' . ((!$today || $today['check_out'] !== null) ? ' disabled' : '') . '>Check out</button></form></div>';
include __DIR__ . '/../includes/header.php';
$note = fn(string $t) => '<span class="positive">↗</span> ' . e($t);
?>
<div class="stats">
    <?= stat_html('Today', $today ? e(fmt_time($today['check_in'])) : 'Not in', '▣', $today ? $note('Check-in' . ($today['check_out'] ? ' · out ' . fmt_time($today['check_out']) : '')) : e('Check in to start your day')) ?>
    <?= stat_html('Days present', (string)(int)$month['days'], '✓', $note('This month'), url('staff/attendance.php')) ?>
    <?= stat_html('Hours worked', e(fmt_minutes((int)$month['mins'])), '◷', $note('This month')) ?>
    <?= stat_html('Pending leave', (string)(int)($leaveCounts['pending'] ?? 0), '▤', $note('Waiting for HR'), url('staff/leave.php?status=pending')) ?>
</div>

<div class="grid-2">
    <section class="panel">
        <div class="panel-head"><div><h2>Leave status</h2><div class="panel-sub">Your latest requests</div></div>
            <a class="btn outline" href="<?= e(url('staff/leave.php')) ?>">Request leave</a></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Leave type</th><th>From</th><th>To</th><th>Days</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($leaveRows as $l): ?>
                <tr><td><?= e($l['name']) ?></td><td><?= e(fmt_date($l['from_date'])) ?></td><td><?= e(fmt_date($l['to_date'])) ?></td><td><?= (int)$l['days'] ?></td><td><?= badge($l['status']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$leaveRows): ?><tr><td colspan="5"><div class="empty">No leave requests yet.</div></td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
    <section class="panel">
        <div class="panel-head"><div><h2>Latest payslip</h2><div class="panel-sub">Approved by HR</div></div></div>
        <?php if ($latest): ?>
            <div class="panel-sub"><?= e(MONTH_NAMES[(int)$latest['period_month']] . ' ' . $latest['period_year']) ?> <?= badge($latest['status']) ?></div>
            <div class="stat-value"><?= money($latest['net_salary']) ?></div>
            <a class="btn" href="<?= e(url('staff/payslip.php?id=' . (int)$latest['id'])) ?>">View payslip</a>
        <?php else: ?><div class="empty">No approved payslip yet.</div><?php endif; ?>
    </section>
</div>

<section class="panel">
    <div class="panel-head"><div><h2>Recent incentives</h2><div class="panel-sub">Your latest incentive records</div></div>
        <a class="btn outline" href="<?= e(url('staff/incentives.php')) ?>">View all</a></div>
    <div class="table-wrap"><table>
        <thead><tr><th>Date</th><th>Reason</th><th>Amount</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($incRows as $i): ?>
            <tr><td><?= e(fmt_date($i['incentive_date'])) ?></td><td><?= e($i['reason']) ?></td><td><?= money($i['amount']) ?></td><td><?= badge($i['status'] === 'approved' ? $i['payment_status'] : $i['status']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$incRows): ?><tr><td colspan="4"><div class="empty">No incentives yet.</div></td></tr><?php endif; ?>
        </tbody>
    </table></div>
</section>
<?php include __DIR__ . '/../includes/footer.php';
