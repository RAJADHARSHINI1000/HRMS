<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payroll_lib.php';
$me  = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_str('action', 20);

    if ($action === 'generate') {
        $month = (int)post_str('month', 2);
        $year  = (int)post_str('year', 4);
        $sid   = (int)post_str('staff_id', 10);
        $thisPeriod = (int)date('Y') * 12 + (int)date('n');

        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            flash_set('error', 'Select a valid month and year.');
        } elseif ($year * 12 + $month > $thisPeriod) {
            flash_set('error', 'Payroll cannot be generated for a future month.');
        } else {
            if ($sid > 0) {
                $targets = [$sid];
            } else {
                $periodEnd = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
                $t = $pdo->prepare("SELECT id FROM staff WHERE employment_status = 'active' AND joining_date <= ? ORDER BY id");
                $t->execute([$periodEnd]);
                $targets = array_map('intval', $t->fetchAll(PDO::FETCH_COLUMN));
            }
            $created = 0;
            $skipped = [];
            foreach ($targets as $id) {
                $ex = $pdo->prepare('SELECT COUNT(*) FROM payroll WHERE staff_id = ? AND period_month = ? AND period_year = ?');
                $ex->execute([$id, $month, $year]);
                if ((int)$ex->fetchColumn()) {
                    if ($sid > 0) $skipped[] = 'Payroll already exists for this staff member and period.';
                    continue; // never create a duplicate
                }
                $calc = payroll_calculate($pdo, $id, $month, $year);
                if (isset($calc['error'])) {
                    // In bulk mode, staff with no salary set (e.g. the HR admin account) are skipped quietly.
                    if ($sid > 0 || strpos($calc['error'], 'no monthly salary') === false) {
                        $skipped[] = $calc['error'];
                    }
                    continue;
                }
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare("INSERT INTO payroll (staff_id, period_month, period_year, base_salary, total_incentives, gross_earnings, total_deductions, net_salary, status, generated_by)
                                   VALUES (?,?,?,?,?,?,?,?, 'draft', ?)")
                        ->execute([$id, $month, $year, $calc['base'], $calc['incentives'], $calc['gross'], $calc['deductions'], $calc['net'], $me['id']]);
                    $pid = (int)$pdo->lastInsertId();
                    payroll_save_items($pdo, $pid, $calc);
                    $pdo->commit();
                    audit_log('payroll_generated', 'payroll', $pid, ['staff_id' => $id, 'net' => $calc['net']]);
                    $created++;
                } catch (PDOException $ex) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('payroll generate failed: ' . $ex->getMessage());
                    $skipped[] = ($calc['name'] ?? 'A staff member') . ': could not be saved (a record may already exist).';
                }
            }
            if ($created > 0) flash_set('success', $created . ' payroll record(s) generated as Draft for ' . MONTH_NAMES[$month] . ' ' . $year . '.');
            foreach (array_slice($skipped, 0, 5) as $s) flash_set('warning', $s);
            if (count($skipped) > 5) flash_set('warning', (count($skipped) - 5) . ' more record(s) were skipped.');
            if ($created === 0 && !$skipped) flash_set('info', 'Nothing to generate: payroll already exists for every eligible staff member.');
        }
        $_GET['month'] = $month; $_GET['year'] = $year;
    } else {
        $pid = (int)post_str('id', 10);
        $row = $pdo->prepare('SELECT * FROM payroll WHERE id = ?');
        $row->execute([$pid]);
        $p = $row->fetch();
        if (!$p) {
            flash_set('error', 'Payroll record not found.');
        } elseif ($action === 'recalculate') {
            if ($p['status'] !== 'draft') {
                flash_set('error', 'Only Draft payroll can be recalculated.');
            } else {
                $calc = payroll_calculate($pdo, (int)$p['staff_id'], (int)$p['period_month'], (int)$p['period_year']);
                if (isset($calc['error'])) {
                    flash_set('error', $calc['error']);
                } else {
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE payroll SET base_salary=?, total_incentives=?, gross_earnings=?, total_deductions=?, net_salary=? WHERE id=?')
                        ->execute([$calc['base'], $calc['incentives'], $calc['gross'], $calc['deductions'], $calc['net'], $pid]);
                    payroll_save_items($pdo, $pid, $calc);
                    $pdo->commit();
                    audit_log('payroll_recalculated', 'payroll', $pid, ['old_net' => $p['net_salary'], 'new_net' => $calc['net']]);
                    flash_set('success', 'Payroll recalculated from the current salary, incentives and deductions.');
                }
            }
        } elseif ($action === 'approve') {
            if ($p['status'] !== 'draft') {
                flash_set('error', 'Only Draft payroll can be approved.');
            } else {
                $pdo->prepare("UPDATE payroll SET status='approved', approved_by=?, approved_at=NOW() WHERE id=? AND status='draft'")->execute([$me['id'], $pid]);
                audit_log('payroll_approved', 'payroll', $pid);
                flash_set('success', 'Payroll approved. Staff can now see this payslip.');
            }
        } elseif ($action === 'pay') {
            if ($p['status'] !== 'approved') {
                flash_set('error', 'Only Approved payroll can be marked as paid.');
            } else {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare("UPDATE payroll SET status='paid', paid_at=NOW() WHERE id=? AND status='approved'")->execute([$pid]);
                    $pdo->prepare("UPDATE incentives SET payment_status='paid' WHERE id IN (SELECT source_id FROM (SELECT source_id FROM payroll_items WHERE payroll_id = ? AND source_table = 'incentives') x)")
                        ->execute([$pid]);
                    $pdo->commit();
                    audit_log('payroll_paid', 'payroll', $pid, ['net' => $p['net_salary']]);
                    flash_set('success', 'Payroll marked as paid.');
                } catch (PDOException $ex) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('payroll pay failed: ' . $ex->getMessage());
                    flash_set('error', 'Could not mark the payroll as paid.');
                }
            }
        } elseif ($action === 'delete') {
            if ($p['status'] !== 'draft') {
                flash_set('error', 'Only Draft payroll can be deleted.');
            } else {
                $pdo->prepare("DELETE FROM payroll WHERE id = ? AND status = 'draft'")->execute([$pid]);
                audit_log('payroll_deleted', 'payroll', $pid, ['staff_id' => $p['staff_id'], 'period' => $p['period_month'] . '/' . $p['period_year']]);
                flash_set('success', 'Draft payroll deleted.');
            }
        }
        $_GET['month'] = $p['period_month'] ?? ($_GET['month'] ?? null);
        $_GET['year']  = $p['period_year'] ?? ($_GET['year'] ?? null);
    }
    $keep = array_filter(['month' => $_GET['month'] ?? '', 'year' => $_GET['year'] ?? '', 'status' => $_GET['status'] ?? '', 'q' => $_GET['q'] ?? '']);
    redirect('admin/payroll.php' . ($keep ? '?' . http_build_query($keep) : ''));
}

// ---- list ----
$month  = get_int('month') ?: (int)date('n');
$year   = get_int('year') ?: (int)date('Y');
$status = get_str('status', 10);
$q      = get_str('q');
$dept   = get_int('department');
$where  = ['p.period_month = :m', 'p.period_year = :y'];
$params = [':m' => $month, ':y' => $year];
if (in_array($status, ['draft', 'approved', 'paid'], true)) { $where[] = 'p.status = :st'; $params[':st'] = $status; }
if ($dept > 0) { $where[] = 's.department_id = :dept'; $params[':dept'] = $dept; }
if ($q !== '') {
    $where[] = '(u.full_name LIKE :q1 OR s.staff_code LIKE :q2)';
    $params[':q1'] = $params[':q2'] = '%' . $q . '%';
}
$sql = 'SELECT p.id, p.base_salary, p.total_incentives, p.gross_earnings, p.total_deductions, p.net_salary, p.status, u.full_name, s.staff_code, d.name AS department
        FROM payroll p JOIN staff s ON s.id = p.staff_id JOIN users u ON u.id = s.user_id LEFT JOIN departments d ON d.id = s.department_id
        WHERE ' . implode(' AND ', $where) . ' ORDER BY u.full_name';
$list = paginate($sql, $params, max(1, get_int('page')), 15);

$tot = $pdo->prepare('SELECT COALESCE(SUM(gross_earnings),0) g, COALESCE(SUM(total_incentives),0) i, COALESCE(SUM(total_deductions),0) d, COALESCE(SUM(net_salary),0) n
                      FROM payroll WHERE period_month = ? AND period_year = ?');
$tot->execute([$month, $year]);
$totals = $tot->fetch();
$staffActive = active_staff_list();
$depts = departments();

$pageTitle = 'Payroll Management';
$navActive = 'payroll';
$pageSub = 'Calculate, approve and pay monthly salaries.';
$note = fn(string $t) => '<span class="positive">↗</span> ' . e($t);
include __DIR__ . '/../includes/header.php';
?>
<section class="panel">
    <div class="panel-head"><div><h2>Generate payroll</h2><div class="panel-sub">Existing records are never overwritten</div></div></div>
    <form method="post" class="row g-2 align-items-end" data-confirm="Generate payroll for the selected month? Existing records are never overwritten.">
        <?= csrf_field() ?><input type="hidden" name="action" value="generate">
        <div class="col-md-3"><label class="form-label">Month</label><?= month_select('month', $month) ?></div>
        <div class="col-md-2"><label class="form-label">Year</label><?= year_select('year', $year) ?></div>
        <div class="col-md-4"><label class="form-label">Staff</label><select class="form-select" name="staff_id"><option value="0">All eligible active staff</option>
            <?php foreach ($staffActive as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['full_name'] . ' (' . $s['staff_code'] . ')') ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><button class="btn w-100" type="submit">Generate payroll</button></div>
    </form>
    <div class="notice" style="margin-top:14px">Gross = Base salary + approved incentives. Net = Gross &minus; active deductions. No PF/tax is deducted automatically. Amounts are rounded to 2 decimals.</div>
</section>

<div class="stats">
    <?= stat_html('Gross earnings', money($totals['g']), '₹', $note(MONTH_NAMES[$month] . ' ' . $year)) ?>
    <?= stat_html('Incentives', money($totals['i']), '％', $note('Approved')) ?>
    <?= stat_html('Deductions', money($totals['d']), '−', $note('Active')) ?>
    <?= stat_html('Net salary', money($totals['n']), '✓', $note('To be paid')) ?>
</div>

<section class="panel filter-panel">
    <form class="row g-2" method="get">
        <div class="col-md-2"><?= month_select('month', $month) ?></div>
        <div class="col-md-2"><?= year_select('year', $year) ?></div>
        <div class="col-md-2"><select class="form-select" name="status"><option value="">All statuses</option>
            <?php foreach (['draft', 'approved', 'paid'] as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><select class="form-select" name="department"><option value="">All depts</option>
            <?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= $dept === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><input class="form-control" name="q" placeholder="Search staff" value="<?= e($q) ?>"></div>
        <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary flex-fill">Filter</button><a class="btn btn-outline-primary" href="payroll.php">Reset</a></div>
    </form>
</section>

<section class="panel"><div class="table-wrap">
    <table class="table table-hover">
        <thead><tr><th>Staff</th><th>Department</th><th class="text-end">Base</th><th class="text-end">Incentives</th><th class="text-end">Gross</th><th class="text-end">Deductions</th><th class="text-end">Net</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $r): ?>
            <tr>
                <td><?= e($r['full_name']) ?> <span class="text-muted tiny"><?= e($r['staff_code']) ?></span></td>
                <td><?= e($r['department'] ?? '—') ?></td>
                <td class="text-end"><?= money($r['base_salary']) ?></td>
                <td class="text-end"><?= money($r['total_incentives']) ?></td>
                <td class="text-end"><?= money($r['gross_earnings']) ?></td>
                <td class="text-end"><?= money($r['total_deductions']) ?></td>
                <td class="text-end fw-semibold"><?= money($r['net_salary']) ?></td>
                <td><?= badge($r['status']) ?></td>
                <td class="text-end text-nowrap">
                    <a class="btn btn-sm btn-outline-primary" href="payslip.php?id=<?= (int)$r['id'] ?>" title="Payslip"><i class="bi bi-receipt"></i></a>
                    <?php if ($r['status'] === 'draft'): ?>
                        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="recalculate"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-sm btn-outline-primary" title="Recalculate"><i class="bi bi-arrow-repeat"></i></button></form>
                        <form method="post" class="d-inline" data-confirm="Approve this payroll? It can no longer be recalculated or deleted."><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-sm btn-outline-success" title="Approve"><i class="bi bi-check-lg"></i></button></form>
                        <form method="post" class="d-inline" data-confirm="Delete this draft payroll?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" title="Delete draft"><i class="bi bi-trash"></i></button></form>
                    <?php elseif ($r['status'] === 'approved'): ?>
                        <form method="post" class="d-inline" data-confirm="Mark this payroll as paid?"><?= csrf_field() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-sm btn-success" title="Mark paid">Mark paid</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$list['rows']): ?><tr><td colspan="9"><div class="empty-state"><i class="bi bi-cash-stack"></i>No payroll for <?= e(MONTH_NAMES[$month] . ' ' . $year) ?> yet. Use Generate Payroll above.</div></td></tr><?php endif; ?>
        </tbody>
    </table>
</div></section>
<?= render_pagination($list) ?>
<?php include __DIR__ . '/../includes/footer.php';
