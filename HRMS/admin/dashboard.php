<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$me = require_admin();

$pdo   = db();
$today = date('Y-m-d');
$m     = (int)date('n');
$y     = (int)date('Y');

$totalStaff   = (int)$pdo->query("SELECT COUNT(*) FROM staff WHERE employment_status = 'active'")->fetchColumn();
$presentToday = (int)$pdo->query("SELECT COUNT(*) FROM attendance a JOIN staff s ON s.id = a.staff_id
                                  WHERE a.att_date = CURDATE() AND s.employment_status = 'active'")->fetchColumn();
$st = $pdo->prepare("SELECT COUNT(DISTINCT l.staff_id) FROM leave_requests l JOIN staff s ON s.id = l.staff_id
                     WHERE l.status = 'approved' AND ? BETWEEN l.from_date AND l.to_date AND s.employment_status = 'active'
                     AND NOT EXISTS (SELECT 1 FROM attendance a WHERE a.staff_id = l.staff_id AND a.att_date = ?)");
$st->execute([$today, $today]);
$onLeave = (int)$st->fetchColumn();
$workingToday = is_working_day($today);
$absentToday  = $workingToday ? max(0, $totalStaff - $presentToday - $onLeave) : 0;

$pendingLeave = (int)$pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'")->fetchColumn();
$pendingPerm  = (int)$pdo->query("SELECT COUNT(*) FROM time_permissions WHERE status = 'pending'")->fetchColumn();
$pendingInc   = (int)$pdo->query("SELECT COUNT(*) FROM incentives WHERE status = 'pending'")->fetchColumn();
$pendingTotal = $pendingLeave + $pendingPerm + $pendingInc;

$st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM incentives WHERE status = 'approved' AND period_month = ? AND period_year = ?");
$st->execute([$m, $y]);
$incentivesMonth = (float)$st->fetchColumn();

$st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM deductions WHERE status = 'active' AND period_month = ? AND period_year = ?");
$st->execute([$m, $y]);
$deductionsMonth = (float)$st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) AS records, COALESCE(SUM(net_salary),0) AS net,
                            SUM(status = 'draft') AS drafts, SUM(status = 'approved') AS approved, SUM(status = 'paid') AS paid
                     FROM payroll WHERE period_month = ? AND period_year = ?");
$st->execute([$m, $y]);
$pay = $st->fetch();

// Charts: attendance of last 7 days, net payroll of last 6 months
$attLabels = $attData = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $c = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE att_date = ?');
    $c->execute([$d]);
    $attLabels[] = date('D d', strtotime($d));
    $attData[]   = (int)$c->fetchColumn();
}
$payLabels = $payData = [];
for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime("first day of -$i month");
    $c = $pdo->prepare('SELECT COALESCE(SUM(net_salary),0) FROM payroll WHERE period_month = ? AND period_year = ?');
    $c->execute([(int)date('n', $ts), (int)date('Y', $ts)]);
    $payLabels[] = date('M Y', $ts);
    $payData[]   = (float)$c->fetchColumn();
}

$recent = $pdo->query("SELECT a.att_date, a.check_in, a.check_out, a.status, u.full_name, s.staff_code
                       FROM attendance a JOIN staff s ON s.id = a.staff_id JOIN users u ON u.id = s.user_id
                       ORDER BY a.att_date DESC, a.updated_at DESC LIMIT 8")->fetchAll();

$pageTitle = 'Dashboard';
$navActive = 'dashboard';
$heading   = 'Good ' . (date('G') < 12 ? 'morning' : (date('G') < 17 ? 'afternoon' : 'evening')) . ', ' . explode(' ', trim($me['full_name']))[0] . ' 👋';
$pageSub   = 'Here is what is happening across your organisation today.';
include __DIR__ . '/../includes/header.php';
$note = fn(string $t) => '<span class="positive">↗</span> ' . e($t);
?>
<div class="stats">
    <?= stat_html('Total staff', (string)$totalStaff, '♙', $note('Active employees'), url('admin/staff.php')) ?>
    <?= stat_html('Present today', (string)$presentToday, '✓', $note('Checked in today'), url('admin/attendance.php')) ?>
    <?= stat_html('Absent today' . ($workingToday ? '' : ' (weekly off)'), (string)$absentToday, '−', $note('Not checked in, not on leave')) ?>
    <?= stat_html('On leave today', (string)$onLeave, '▤', $note('Approved leave'), url('admin/leave.php?status=approved')) ?>
</div>
<div class="stats">
    <?= stat_html('Pending leave requests', (string)$pendingLeave, '◷', $note('Waiting for review'), url('admin/leave.php?status=pending')) ?>
    <?= stat_html('Pending approvals (all)', (string)$pendingTotal, '☑', $note('Leave, permission, incentives')) ?>
    <?= stat_html('Incentives · ' . date('M Y'), money($incentivesMonth), '％', $note('Approved this month'), url('admin/incentives.php')) ?>
    <?= stat_html('Deductions · ' . date('M Y'), money($deductionsMonth), '−', $note('Active this month'), url('admin/deductions.php')) ?>
</div>

<div class="grid-2">
    <section class="panel">
        <div class="panel-head"><div><h2>Recent attendance</h2><div class="panel-sub">Latest check-ins across the team</div></div>
            <a class="btn outline" href="<?= e(url('admin/attendance.php')) ?>">View all</a></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Employee</th><th>Date</th><th>Check-in</th><th>Check-out</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $r): ?>
                <tr><td><div class="person"><div class="avatar"><?= e(mb_strtoupper(mb_substr($r['full_name'], 0, 1))) ?></div><div><strong><?= e($r['full_name']) ?></strong><small><?= e($r['staff_code']) ?></small></div></div></td>
                    <td><?= e(fmt_date($r['att_date'])) ?></td><td><?= e(fmt_time($r['check_in'])) ?></td><td><?= e(fmt_time($r['check_out'])) ?></td><td><?= badge($r['status']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$recent): ?><tr><td colspan="5"><div class="empty">No attendance recorded yet.</div></td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
    <section class="panel">
        <div class="panel-head"><div><h2>Pending approvals</h2><div class="panel-sub">Items waiting for HR</div></div></div>
        <div class="mini-list">
            <a class="list-item" href="<?= e(url('admin/leave.php?status=pending')) ?>"><div class="avatar">▤</div><div class="grow"><strong>Leave requests</strong><small>Review and approve</small></div><div class="right"><?= $pendingLeave ?></div></a>
            <a class="list-item" href="<?= e(url('admin/timepermission.php?status=pending')) ?>"><div class="avatar">◷</div><div class="grow"><strong>Time permissions</strong><small>Short-time requests</small></div><div class="right"><?= $pendingPerm ?></div></a>
            <a class="list-item" href="<?= e(url('admin/incentives.php?status=pending')) ?>"><div class="avatar">％</div><div class="grow"><strong>Incentives</strong><small>Awaiting approval</small></div><div class="right"><?= $pendingInc ?></div></a>
        </div>
    </section>
</div>

<div class="grid-2">
    <section class="panel">
        <div class="panel-head"><div><h2>Attendance · last 7 days</h2><div class="panel-sub">Staff who checked in each day</div></div></div>
        <?= bar_chart_html(array_map(null, $attLabels, $attData)) ?>
    </section>
    <section class="panel">
        <div class="panel-head"><div><h2>Payroll summary</h2><div class="panel-sub"><?= e(date('F Y')) ?></div></div>
            <a class="btn outline" href="<?= e(url('admin/payroll.php')) ?>">Open payroll</a></div>
        <?php if ((int)$pay['records'] === 0): ?>
            <div class="empty">No payroll generated for this month yet.</div>
        <?php else: ?>
            <div class="detail-row"><span class="k">Records</span><span class="v"><?= (int)$pay['records'] ?></span></div>
            <div class="detail-row"><span class="k">Total net salary</span><span class="v"><?= money($pay['net']) ?></span></div>
            <div class="detail-row"><span class="k">Draft</span><span class="v"><?= (int)$pay['drafts'] ?></span></div>
            <div class="detail-row"><span class="k">Approved</span><span class="v"><?= (int)$pay['approved'] ?></span></div>
            <div class="detail-row"><span class="k">Paid</span><span class="v"><?= (int)$pay['paid'] ?></span></div>
        <?php endif; ?>
    </section>
</div>
<section class="panel">
    <div class="panel-head"><div><h2>Net payroll · last 6 months</h2><div class="panel-sub">Saved payroll records</div></div></div>
    <?= bar_chart_html(array_map(null, $payLabels, $payData), true) ?>
</section>
<?php include __DIR__ . '/../includes/footer.php';
