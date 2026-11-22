<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payroll_lib.php';
$me  = require_staff();
$sid = (int)$me['staff_id'];

// Staff only see payroll that HR has approved or paid, and only their own.
$year = get_int('year');
$where = "p.staff_id = :sid AND p.status IN ('approved','paid')";
$params = [':sid' => $sid];
if ($year >= 2000 && $year <= 2100) { $where .= ' AND p.period_year = :y'; $params[':y'] = $year; }
$list = paginate("SELECT p.id, p.period_month, p.period_year, p.base_salary, p.total_incentives, p.gross_earnings, p.total_deductions, p.net_salary, p.status
                  FROM payroll p WHERE $where ORDER BY p.period_year DESC, p.period_month DESC", $params, max(1, get_int('page')), 12);

$pageTitle = 'My Payroll';
$navActive = 'salary';
$pageSub = 'View salary details and print payslips. Only HR-approved payroll is shown.';
$st = db()->prepare("SELECT COUNT(*) c, COALESCE(SUM(net_salary),0) net, COALESCE(SUM(total_incentives),0) inc, COALESCE(SUM(total_deductions),0) ded
                     FROM payroll WHERE staff_id = ? AND status IN ('approved','paid')" . ($year >= 2000 && $year <= 2100 ? ' AND period_year = ' . (int)$year : ''));
$st->execute([$sid]);
$sum = $st->fetch();
$note = fn(string $t) => '<span class="positive">↗</span> ' . e($t);
include __DIR__ . '/../includes/header.php';
?>
<div class="stats">
    <?= stat_html('Payslips', (string)(int)$sum['c'], '▤', $note('Approved or paid')) ?>
    <?= stat_html('Total net salary', money($sum['net']), '₹', $note('Selected period')) ?>
    <?= stat_html('Incentives', money($sum['inc']), '％', $note('Included')) ?>
    <?= stat_html('Deductions', money($sum['ded']), '−', $note('Applied')) ?>
</div>
<section class="panel filter-panel">
    <form class="row g-2" method="get">
        <div class="col-8 col-md-3"><select class="form-select" name="year"><option value="">All years</option>
            <?php for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 5; $y--): ?><option<?= $year === $y ? ' selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></div>
        <div class="col-4 col-md-2"><button class="btn btn-primary w-100">Filter</button></div>
    </form>
</section>
<section class="panel"><div class="table-wrap">
    <table class="table table-hover">
        <thead><tr><th>Month</th><th class="text-end">Base</th><th class="text-end">Incentives</th><th class="text-end">Gross</th><th class="text-end">Deductions</th><th class="text-end">Net salary</th><th>Status</th><th class="text-end">Payslip</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $r): ?>
            <tr><td><?= e(MONTH_NAMES[(int)$r['period_month']] . ' ' . $r['period_year']) ?></td>
                <td class="text-end"><?= money($r['base_salary']) ?></td><td class="text-end"><?= money($r['total_incentives']) ?></td><td class="text-end"><?= money($r['gross_earnings']) ?></td>
                <td class="text-end"><?= money($r['total_deductions']) ?></td><td class="text-end fw-semibold"><?= money($r['net_salary']) ?></td><td><?= badge($r['status']) ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="payslip.php?id=<?= (int)$r['id'] ?>"><i class="bi bi-receipt me-1"></i>View</a></td></tr>
        <?php endforeach; ?>
        <?php if (!$list['rows']): ?><tr><td colspan="8"><div class="empty-state"><i class="bi bi-cash-stack"></i>No approved payroll yet. Payslips appear here once HR approves them.</div></td></tr><?php endif; ?>
        </tbody>
    </table>
</div></section>
<?= render_pagination($list) ?>
<?php include __DIR__ . '/../includes/footer.php';
