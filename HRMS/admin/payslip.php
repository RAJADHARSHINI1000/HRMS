<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payroll_lib.php';
require_admin();

$p = payroll_load(db(), get_int('id'));
if (!$p) {
    http_response_code(404);
    exit('Payslip not found.');
}
$pageTitle = 'Payslip';
$pageSub = 'Printable payslip from the saved payroll record.';
$navActive = 'payroll';
include __DIR__ . '/../includes/header.php';
?>
<div class="toolbar no-print" style="justify-content:space-between;margin-bottom:18px">
    <a class="btn outline" href="payroll.php?month=<?= (int)$p['period_month'] ?>&year=<?= (int)$p['period_year'] ?>">← Back to payroll</a>
    <button class="btn" onclick="window.print()">⎙ Print</button>
</div>
<?= render_payslip($p) ?>
<?php include __DIR__ . '/../includes/footer.php';
