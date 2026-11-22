<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$me  = require_staff();
$sid = (int)$me['staff_id'];

$month = get_int('month');
$year  = get_int('year');
$where = "x.staff_id = :sid AND x.status = 'active'";
$params = [':sid' => $sid];
if ($month >= 1 && $month <= 12) { $where .= ' AND x.period_month = :m'; $params[':m'] = $month; }
if ($year >= 2000 && $year <= 2100) { $where .= ' AND x.period_year = :y'; $params[':y'] = $year; }
$list = paginate("SELECT x.category, x.amount, x.reason, x.deduction_date, x.period_month, x.period_year
                  FROM deductions x WHERE $where ORDER BY x.deduction_date DESC, x.id DESC", $params, max(1, get_int('page')), 12);

$t = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM deductions x WHERE $where");
$t->execute($params);
$total = $t->fetchColumn();

$pageTitle = 'My Deductions';
$navActive = 'deductions';
$pageSub = 'Deductions recorded against your salary.';
include __DIR__ . '/../includes/header.php';
?>
<section class="panel filter-panel">
    <form class="row g-2" method="get">
        <div class="col-md-3"><select class="form-select" name="month"><option value="">All months</option>
            <?php foreach (MONTH_NAMES as $n => $l): ?><option value="<?= $n ?>"<?= $month === $n ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><select class="form-select" name="year"><option value="">All years</option>
            <?php for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 5; $y--): ?><option<?= $year === $y ? ' selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></div>
        <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary flex-fill">Filter</button><a class="btn btn-outline-primary" href="deductions.php">Reset</a></div>
        <div class="col-md-3 text-md-end align-self-center">Total: <strong><?= money($total) ?></strong></div>
    </form>
</section>
<section class="panel"><div class="table-wrap">
    <table class="table table-hover">
        <thead><tr><th>Date</th><th>Category</th><th>Reason</th><th>Payroll period</th><th class="text-end">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $r): ?>
            <tr><td><?= e(fmt_date($r['deduction_date'])) ?></td><td><?= e($r['category']) ?></td><td><?= e($r['reason']) ?></td>
                <td><?= e(MONTH_NAMES[(int)$r['period_month']] . ' ' . $r['period_year']) ?></td><td class="text-end"><?= money($r['amount']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$list['rows']): ?><tr><td colspan="5"><div class="empty-state"><i class="bi bi-dash-circle"></i>No deductions recorded for you.</div></td></tr><?php endif; ?>
        </tbody>
    </table>
</div></section>
<?= render_pagination($list) ?>
<?php include __DIR__ . '/../includes/footer.php';
