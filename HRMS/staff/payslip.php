<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payroll_lib.php';
$me = require_staff();

$p = payroll_load(db(), get_int('id'));
// Own record only, and only after HR approval. Anything else looks like "not found".
if (!$p || (int)$p['staff_id'] !== (int)$me['staff_id'] || !in_array($p['status'], ['approved', 'paid'], true)) {
    http_response_code(404);
    exit('Payslip not found.');
}
$pageTitle = 'Payslip';
$pageSub = 'Printable payslip from the saved payroll record.';
$navActive = 'salary';
include __DIR__ . '/../includes/header.php';
?>
<div class="toolbar no-print" style="justify-content:space-between;margin-bottom:18px">
    <a class="btn outline" href="salary.php">← Back</a>
    <button class="btn" onclick="window.print()">⎙ Print</button>
</div>
<?= render_payslip($p) ?>
<?php include __DIR__ . '/../includes/footer.php';
