<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$me  = require_staff();
$sid = (int)$me['staff_id'];

$status = get_str('status', 10);
$where = 'i.staff_id = :sid';
$params = [':sid' => $sid];
if (in_array($status, ['pending', 'approved', 'rejected'], true)) { $where .= ' AND i.status = :st'; $params[':st'] = $status; }
$list = paginate("SELECT i.id, i.amount, i.reason, i.incentive_date, i.period_month, i.period_year, i.status, i.payment_status, i.document_name
                  FROM incentives i WHERE $where ORDER BY i.incentive_date DESC, i.id DESC", $params, max(1, get_int('page')), 12);

$t = db()->prepare("SELECT COALESCE(SUM(CASE WHEN status='approved' THEN amount END),0) approved,
                           COALESCE(SUM(CASE WHEN status='approved' AND payment_status='paid' THEN amount END),0) paid
                    FROM incentives WHERE staff_id = ?");
$t->execute([$sid]);
$tot = $t->fetch();

$pageTitle = 'My Incentives';
$navActive = 'incentives';
$pageSub = 'View your incentive details and payment status.';
$note = fn(string $t) => '<span class="positive">↗</span> ' . e($t);
include __DIR__ . '/../includes/header.php';
?>
<div class="stats">
    <?= stat_html('Total approved', money($tot['approved']), '％', $note('All time')) ?>
    <?= stat_html('Paid out', money($tot['paid']), '✓', $note('Included in paid payroll')) ?>
</div>

<section class="panel filter-panel">
    <form class="row g-2" method="get">
        <div class="col-8 col-md-4"><select class="form-select" name="status"><option value="">All statuses</option>
            <?php foreach (['pending', 'approved', 'rejected'] as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
        <div class="col-4 col-md-2"><button class="btn btn-primary w-100">Filter</button></div>
    </form>
</section>
<section class="panel"><div class="table-wrap">
    <table class="table table-hover">
        <thead><tr><th>Date</th><th>Reason</th><th>Payroll period</th><th class="text-end">Amount</th><th>Approval</th><th>Payment</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $r): ?>
            <tr><td><?= e(fmt_date($r['incentive_date'])) ?></td><td><?= e($r['reason']) ?></td>
                <td><?= e(MONTH_NAMES[(int)$r['period_month']] . ' ' . $r['period_year']) ?></td><td class="text-end"><?= money($r['amount']) ?></td>
                <td><?= badge($r['status']) ?></td><td><?= badge($r['payment_status']) ?></td>
                <td class="text-end"><?php if ($r['document_name']): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('download.php?type=incentive&id=' . (int)$r['id'])) ?>" title="<?= e($r['document_name']) ?>"><i class="bi bi-paperclip"></i></a><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$list['rows']): ?><tr><td colspan="7"><div class="empty-state"><i class="bi bi-gift"></i>No incentives assigned to you yet.</div></td></tr><?php endif; ?>
        </tbody>
    </table>
</div></section>
<?= render_pagination($list) ?>
<?php include __DIR__ . '/../includes/footer.php';
