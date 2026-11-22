<?php
/**
 * Payroll calculation (single place, so Admin and reports agree).
 *
 *   Gross Earnings = Base Salary + approved incentives for the period
 *   Net Salary     = Gross Earnings - active deductions for the period
 *
 * No PF, tax or other statutory deduction is applied automatically.
 * Amounts are DECIMAL(12,2); every figure is rounded half-up to 2 decimals.
 * The calculation is saved to `payroll` + `payroll_items`; payslips read only the saved rows.
 */
declare(strict_types=1);

/**
 * Builds the calculation for one staff member and period (does not save anything).
 * Returns ['error' => string] or the calculation array.
 */
function payroll_calculate(PDO $pdo, int $staffId, int $month, int $year): array
{
    $st = $pdo->prepare('SELECT s.id, s.salary, s.joining_date, s.employment_status, u.full_name FROM staff s JOIN users u ON u.id = s.user_id WHERE s.id = ?');
    $st->execute([$staffId]);
    $staff = $st->fetch();
    if (!$staff) {
        return ['error' => 'Staff member not found.'];
    }
    $periodEnd = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
    if ($staff['joining_date'] > $periodEnd) {
        return ['error' => $staff['full_name'] . ' had not joined by this period.'];
    }
    $base = round((float)$staff['salary'], 2);
    if ($base <= 0) {
        return ['error' => $staff['full_name'] . ' has no monthly salary set.'];
    }

    $earnings = [['label' => 'Base Salary', 'amount' => $base, 'table' => null, 'id' => null]];
    $inc = $pdo->prepare("SELECT id, amount, reason, incentive_date FROM incentives
                          WHERE staff_id = ? AND period_month = ? AND period_year = ? AND status = 'approved' ORDER BY incentive_date, id");
    $inc->execute([$staffId, $month, $year]);
    $totalInc = 0.0;
    foreach ($inc->fetchAll() as $i) {
        $amt = round((float)$i['amount'], 2);
        $totalInc += $amt;
        $earnings[] = ['label' => 'Incentive: ' . $i['reason'], 'amount' => $amt, 'table' => 'incentives', 'id' => (int)$i['id']];
    }

    $ded = $pdo->prepare("SELECT id, category, amount, reason FROM deductions
                          WHERE staff_id = ? AND period_month = ? AND period_year = ? AND status = 'active' ORDER BY deduction_date, id");
    $ded->execute([$staffId, $month, $year]);
    $deductions = [];
    $totalDed = 0.0;
    foreach ($ded->fetchAll() as $d) {
        $amt = round((float)$d['amount'], 2);
        $totalDed += $amt;
        $deductions[] = ['label' => $d['category'] . ': ' . $d['reason'], 'amount' => $amt, 'table' => 'deductions', 'id' => (int)$d['id']];
    }

    $totalInc = round($totalInc, 2);
    $totalDed = round($totalDed, 2);
    $gross    = round($base + $totalInc, 2);
    $net      = round($gross - $totalDed, 2);
    if ($net < 0) {
        return ['error' => $staff['full_name'] . ': deductions (' . number_format($totalDed, 2) . ') are more than gross earnings (' . number_format($gross, 2) . '). Review the deductions.'];
    }
    return [
        'staff_id' => $staffId, 'name' => $staff['full_name'], 'base' => $base, 'incentives' => $totalInc,
        'gross' => $gross, 'deductions' => $totalDed, 'net' => $net, 'earnings' => $earnings, 'deduction_items' => $deductions,
    ];
}

/** Saves the line items for a payroll row (caller wraps this in a transaction). */
function payroll_save_items(PDO $pdo, int $payrollId, array $calc): void
{
    $pdo->prepare('DELETE FROM payroll_items WHERE payroll_id = ?')->execute([$payrollId]);
    $ins = $pdo->prepare('INSERT INTO payroll_items (payroll_id, item_type, label, amount, source_table, source_id) VALUES (?,?,?,?,?,?)');
    foreach ($calc['earnings'] as $i) {
        $ins->execute([$payrollId, 'earning', mb_substr($i['label'], 0, 255), number_format($i['amount'], 2, '.', ''), $i['table'], $i['id']]);
    }
    foreach ($calc['deduction_items'] as $i) {
        $ins->execute([$payrollId, 'deduction', mb_substr($i['label'], 0, 255), number_format($i['amount'], 2, '.', ''), $i['table'], $i['id']]);
    }
}

/** Loads a saved payroll row with staff details, or null. */
function payroll_load(PDO $pdo, int $payrollId): ?array
{
    $st = $pdo->prepare('SELECT p.*, u.full_name, u.email, s.staff_code, s.designation, s.phone, d.name AS department
                         FROM payroll p JOIN staff s ON s.id = p.staff_id JOIN users u ON u.id = s.user_id
                         LEFT JOIN departments d ON d.id = s.department_id WHERE p.id = ?');
    $st->execute([$payrollId]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    $it = $pdo->prepare('SELECT item_type, label, amount FROM payroll_items WHERE payroll_id = ? ORDER BY id');
    $it->execute([$payrollId]);
    $row['items'] = $it->fetchAll();
    return $row;
}

/** Printable payslip, built only from saved payroll rows. */
function render_payslip(array $p): string
{
    $earn = array_filter($p['items'], fn($i) => $i['item_type'] === 'earning');
    $ded  = array_filter($p['items'], fn($i) => $i['item_type'] === 'deduction');
    ob_start();
    ?>
    <div class="payslip">
        <div class="d-flex justify-content-between flex-wrap gap-2 border-bottom pb-3 mb-3">
            <div>
                <h2 class="mb-1"><?= e(get_setting('company_name', 'Company')) ?></h2>
                <div class="text-muted small"><?= e(get_setting('company_address')) ?></div>
                <div class="text-muted small"><?= e(trim(get_setting('company_email') . ' ' . get_setting('company_phone'))) ?></div>
            </div>
            <div class="text-md-end">
                <div class="fw-semibold fs-5">PAYSLIP</div>
                <div><?= e(MONTH_NAMES[(int)$p['period_month']] . ' ' . $p['period_year']) ?></div>
                <div><?= badge($p['status']) ?></div>
            </div>
        </div>
        <div class="row g-2 mb-3">
            <div class="col-sm-6"><div class="text-muted small">Employee</div><div class="fw-medium"><?= e($p['full_name']) ?></div></div>
            <div class="col-sm-6"><div class="text-muted small">Staff ID</div><div class="fw-medium"><?= e($p['staff_code']) ?></div></div>
            <div class="col-sm-6"><div class="text-muted small">Department</div><div class="fw-medium"><?= e($p['department'] ?? '—') ?></div></div>
            <div class="col-sm-6"><div class="text-muted small">Designation</div><div class="fw-medium"><?= e($p['designation'] ?? '—') ?></div></div>
        </div>
        <table class="table">
            <thead><tr><th>Earnings</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($earn as $i): ?><tr><td><?= e($i['label']) ?></td><td class="text-end"><?= money($i['amount']) ?></td></tr><?php endforeach; ?>
            <tr class="fw-semibold"><td>Gross Earnings</td><td class="text-end"><?= money($p['gross_earnings']) ?></td></tr>
            </tbody>
        </table>
        <table class="table">
            <thead><tr><th>Deductions</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($ded as $i): ?><tr><td><?= e($i['label']) ?></td><td class="text-end"><?= money($i['amount']) ?></td></tr><?php endforeach; ?>
            <?php if (!$ded): ?><tr><td class="text-muted">No deductions</td><td class="text-end"><?= money(0) ?></td></tr><?php endif; ?>
            <tr class="fw-semibold"><td>Total Deductions</td><td class="text-end"><?= money($p['total_deductions']) ?></td></tr>
            </tbody>
        </table>
        <div class="d-flex justify-content-between align-items-center p-3 rounded" style="background:#14213D;color:#fff">
            <span class="fs-5">Net Salary</span><span class="fs-4 fw-semibold"><?= money($p['net_salary']) ?></span>
        </div>
        <div class="text-muted small mt-3">
            <?= $p['paid_at'] ? 'Paid on ' . e(fmt_date($p['paid_at'])) . '. ' : '' ?>This is a computer-generated payslip based on the saved payroll record.
        </div>
    </div>
    <?php
    return (string)ob_get_clean();
}
