<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_admin();
$pdo = db();

$reports = [
    'staff' => 'Staff List', 'attendance_summary' => 'Attendance Summary', 'monthly_attendance' => 'Monthly Attendance',
    'leave' => 'Leave History', 'incentives' => 'Incentive Summary', 'deductions' => 'Deduction Summary',
    'payroll' => 'Monthly Payroll', 'departments' => 'Department-wise Staff',
];
// Which filters each report uses. "status:a|b" = status filter with those options.
$filterSpec = [
    'staff' => ['department', 'status:active|inactive'],
    'attendance_summary' => ['range', 'department', 'staff'],
    'monthly_attendance' => ['period', 'department', 'staff'],
    'leave' => ['range', 'department', 'staff', 'status:pending|approved|rejected'],
    'incentives' => ['range', 'department', 'staff'],
    'deductions' => ['range', 'department', 'staff'],
    'payroll' => ['period', 'department', 'staff', 'status:draft|approved|paid'],
    'departments' => [],
];

$type = get_str('report', 30);
if (!isset($reports[$type])) $type = 'staff';
$spec = $filterSpec[$type];

$from = get_str('from', 10);
$to   = get_str('to', 10);
if (!valid_date($from)) $from = date('Y-m-01');
if (!valid_date($to)) $to = date('Y-m-d');
$month = get_int('month') ?: (int)date('n');
$year  = get_int('year') ?: (int)date('Y');
if ($month < 1 || $month > 12) $month = (int)date('n');
$dept   = get_int('department');
$staffF = get_int('staff');
$status = get_str('status', 10);

$statusOptions = [];
foreach ($spec as $s) {
    if (strpos($s, 'status:') === 0) $statusOptions = explode('|', substr($s, 7));
}
if ($statusOptions && !in_array($status, $statusOptions, true)) $status = '';

$cols = [];
$rows = [];

switch ($type) {
    case 'staff':
        $cols = ['staff_code' => ['Staff ID', 'text'], 'full_name' => ['Name', 'text'], 'email' => ['Email', 'text'], 'phone' => ['Phone', 'text'],
                 'department' => ['Department', 'text'], 'designation' => ['Designation', 'text'], 'role' => ['Role', 'label'],
                 'joining_date' => ['Joined', 'date'], 'employment_status' => ['Status', 'label'], 'salary' => ['Salary', 'money']];
        $w = []; $p = [];
        if ($dept) { $w[] = 's.department_id = :d'; $p[':d'] = $dept; }
        if ($status) { $w[] = 's.employment_status = :st'; $p[':st'] = $status; }
        $st = $pdo->prepare('SELECT s.staff_code, u.full_name, u.email, s.phone, d.name AS department, s.designation, u.role, s.joining_date, s.employment_status, s.salary
                             FROM staff s JOIN users u ON u.id = s.user_id LEFT JOIN departments d ON d.id = s.department_id'
                             . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY s.staff_code');
        $st->execute($p);
        $rows = $st->fetchAll();
        break;

    case 'attendance_summary':
        $cols = ['staff_code' => ['Staff ID', 'text'], 'full_name' => ['Name', 'text'], 'department' => ['Department', 'text'], 'days' => ['Days recorded', 'int'],
                 'present' => ['Present', 'int'], 'half' => ['Half day', 'int'], 'mins' => ['Total hours', 'minutes']];
        $w = ["(s.employment_status = 'active' OR a.id IS NOT NULL)"]; $p = [':from' => $from, ':to' => $to];
        if ($dept) { $w[] = 's.department_id = :d'; $p[':d'] = $dept; }
        if ($staffF) { $w[] = 's.id = :s'; $p[':s'] = $staffF; }
        $st = $pdo->prepare("SELECT s.staff_code, u.full_name, d.name AS department, COUNT(a.id) AS days, COALESCE(SUM(a.status IN ('present','late')),0) AS present,
                                    COALESCE(SUM(a.status = 'half_day'),0) AS half, COALESCE(SUM(a.work_minutes),0) AS mins
                             FROM staff s JOIN users u ON u.id = s.user_id LEFT JOIN departments d ON d.id = s.department_id
                             LEFT JOIN attendance a ON a.staff_id = s.id AND a.att_date BETWEEN :from AND :to
                             WHERE " . implode(' AND ', $w) . ' GROUP BY s.id, s.staff_code, u.full_name, d.name ORDER BY u.full_name');
        $st->execute($p);
        $rows = $st->fetchAll();
        break;

    case 'monthly_attendance':
        $cols = ['staff_code' => ['Staff ID', 'text'], 'full_name' => ['Name', 'text'], 'department' => ['Department', 'text'], 'workdays' => ['Working days', 'int'],
                 'present' => ['Present', 'int'], 'half' => ['Half day', 'int'], 'leave' => ['On approved leave', 'int'], 'absent' => ['Absent', 'int']];
        $first = sprintf('%04d-%02d-01', $year, $month);
        $last  = date('Y-m-t', strtotime($first));
        $upto  = min($last, date('Y-m-d'));
        $w = ["s.employment_status = 'active'", 's.joining_date <= :last']; $p = [':last' => $last];
        if ($dept) { $w[] = 's.department_id = :d'; $p[':d'] = $dept; }
        if ($staffF) { $w[] = 's.id = :s'; $p[':s'] = $staffF; }
        $st = $pdo->prepare('SELECT s.id, s.staff_code, s.joining_date, u.full_name, d.name AS department
                             FROM staff s JOIN users u ON u.id = s.user_id LEFT JOIN departments d ON d.id = s.department_id
                             WHERE ' . implode(' AND ', $w) . ' ORDER BY u.full_name');
        $st->execute($p);
        $staffRows = $st->fetchAll();

        $att = [];
        $q = $pdo->prepare('SELECT staff_id, att_date, status FROM attendance WHERE att_date BETWEEN ? AND ?');
        $q->execute([$first, $last]);
        foreach ($q->fetchAll() as $a) $att[$a['staff_id']][$a['att_date']] = $a['status'];
        $leaves = [];
        $q = $pdo->prepare("SELECT staff_id, from_date, to_date FROM leave_requests WHERE status = 'approved' AND from_date <= ? AND to_date >= ?");
        $q->execute([$last, $first]);
        foreach ($q->fetchAll() as $l) $leaves[$l['staff_id']][] = $l;

        foreach ($staffRows as $s) {
            $start = max($first, $s['joining_date']);
            $workdays = $start <= $upto ? count_working_days($start, $upto) : 0;
            $mine = $att[$s['id']] ?? [];
            $present = $half = $attendedWorking = 0;
            foreach ($mine as $d => $stt) {
                if ($d < $start || $d > $upto) continue;
                if ($stt === 'present' || $stt === 'late') $present++;
                else $half++;
                if (is_working_day($d)) $attendedWorking++;
            }
            $leaveDays = 0;
            $seen = [];
            foreach ($leaves[$s['id']] ?? [] as $l) {
                $d = strtotime(max($l['from_date'], $start));
                $end = strtotime(min($l['to_date'], $upto));
                while ($d <= $end) {
                    $ds = date('Y-m-d', $d);
                    if (is_working_day($ds) && !isset($mine[$ds]) && !isset($seen[$ds])) { $leaveDays++; $seen[$ds] = true; }
                    $d = strtotime('+1 day', $d);
                }
            }
            $rows[] = ['staff_code' => $s['staff_code'], 'full_name' => $s['full_name'], 'department' => $s['department'], 'workdays' => $workdays,
                       'present' => $present, 'half' => $half, 'leave' => $leaveDays,
                       'absent' => max(0, $workdays - $attendedWorking - $leaveDays)];
        }
        break;

    case 'leave':
        $cols = ['staff_code' => ['Staff ID', 'text'], 'full_name' => ['Name', 'text'], 'department' => ['Department', 'text'], 'leave_type' => ['Leave type', 'text'],
                 'from_date' => ['From', 'date'], 'to_date' => ['To', 'date'], 'days' => ['Days', 'int'], 'status' => ['Status', 'label'], 'reviewer' => ['Reviewed by', 'text'], 'reason' => ['Reason', 'text']];
        $w = ['l.to_date >= :from', 'l.from_date <= :to']; $p = [':from' => $from, ':to' => $to];
        if ($dept) { $w[] = 's.department_id = :d'; $p[':d'] = $dept; }
        if ($staffF) { $w[] = 'l.staff_id = :s'; $p[':s'] = $staffF; }
        if ($status) { $w[] = 'l.status = :st'; $p[':st'] = $status; }
        $st = $pdo->prepare('SELECT s.staff_code, u.full_name, d.name AS department, lt.name AS leave_type, l.from_date, l.to_date, l.days, l.status, ru.full_name AS reviewer, l.reason
                             FROM leave_requests l JOIN staff s ON s.id = l.staff_id JOIN users u ON u.id = s.user_id JOIN leave_types lt ON lt.id = l.leave_type_id
                             LEFT JOIN departments d ON d.id = s.department_id LEFT JOIN users ru ON ru.id = l.reviewed_by
                             WHERE ' . implode(' AND ', $w) . ' ORDER BY l.from_date DESC');
        $st->execute($p);
        $rows = $st->fetchAll();
        break;

    case 'incentives':
        $cols = ['staff_code' => ['Staff ID', 'text'], 'full_name' => ['Name', 'text'], 'department' => ['Department', 'text'], 'records' => ['Records', 'int'],
                 'pending' => ['Pending', 'money'], 'approved' => ['Approved', 'money'], 'rejected' => ['Rejected', 'money'], 'paid' => ['Paid out', 'money']];
        $w = ['i.incentive_date BETWEEN :from AND :to']; $p = [':from' => $from, ':to' => $to];
        if ($dept) { $w[] = 's.department_id = :d'; $p[':d'] = $dept; }
        if ($staffF) { $w[] = 'i.staff_id = :s'; $p[':s'] = $staffF; }
        $st = $pdo->prepare("SELECT s.staff_code, u.full_name, d.name AS department, COUNT(*) AS records,
                                    SUM(CASE WHEN i.status = 'pending' THEN i.amount ELSE 0 END) AS pending,
                                    SUM(CASE WHEN i.status = 'approved' THEN i.amount ELSE 0 END) AS approved,
                                    SUM(CASE WHEN i.status = 'rejected' THEN i.amount ELSE 0 END) AS rejected,
                                    SUM(CASE WHEN i.payment_status = 'paid' THEN i.amount ELSE 0 END) AS paid
                             FROM incentives i JOIN staff s ON s.id = i.staff_id JOIN users u ON u.id = s.user_id LEFT JOIN departments d ON d.id = s.department_id
                             WHERE " . implode(' AND ', $w) . ' GROUP BY s.id, s.staff_code, u.full_name, d.name ORDER BY u.full_name');
        $st->execute($p);
        $rows = $st->fetchAll();
        break;

    case 'deductions':
        $cols = ['staff_code' => ['Staff ID', 'text'], 'full_name' => ['Name', 'text'], 'department' => ['Department', 'text'], 'records' => ['Active records', 'int']];
        $catSql = '';
        $p = [':from' => $from, ':to' => $to];
        foreach (DEDUCTION_CATEGORIES as $n => $c) {
            $cols['c' . $n] = [$c, 'money'];
            $catSql .= ", SUM(CASE WHEN x.category = :cat$n THEN x.amount ELSE 0 END) AS c$n";
            $p[":cat$n"] = $c;
        }
        $cols['total'] = ['Total', 'money'];
        $w = ["x.status = 'active'", 'x.deduction_date BETWEEN :from AND :to'];
        if ($dept) { $w[] = 's.department_id = :d'; $p[':d'] = $dept; }
        if ($staffF) { $w[] = 'x.staff_id = :s'; $p[':s'] = $staffF; }
        $st = $pdo->prepare("SELECT s.staff_code, u.full_name, d.name AS department, COUNT(*) AS records $catSql, SUM(x.amount) AS total
                             FROM deductions x JOIN staff s ON s.id = x.staff_id JOIN users u ON u.id = s.user_id LEFT JOIN departments d ON d.id = s.department_id
                             WHERE " . implode(' AND ', $w) . ' GROUP BY s.id, s.staff_code, u.full_name, d.name ORDER BY u.full_name');
        $st->execute($p);
        $rows = $st->fetchAll();
        break;

    case 'payroll':
        $cols = ['staff_code' => ['Staff ID', 'text'], 'full_name' => ['Name', 'text'], 'department' => ['Department', 'text'], 'base_salary' => ['Base', 'money'],
                 'total_incentives' => ['Incentives', 'money'], 'gross_earnings' => ['Gross', 'money'], 'total_deductions' => ['Deductions', 'money'],
                 'net_salary' => ['Net salary', 'money'], 'status' => ['Status', 'label']];
        $w = ['p.period_month = :m', 'p.period_year = :y']; $p = [':m' => $month, ':y' => $year];
        if ($dept) { $w[] = 's.department_id = :d'; $p[':d'] = $dept; }
        if ($staffF) { $w[] = 'p.staff_id = :s'; $p[':s'] = $staffF; }
        if ($status) { $w[] = 'p.status = :st'; $p[':st'] = $status; }
        $st = $pdo->prepare('SELECT s.staff_code, u.full_name, d.name AS department, p.base_salary, p.total_incentives, p.gross_earnings, p.total_deductions, p.net_salary, p.status
                             FROM payroll p JOIN staff s ON s.id = p.staff_id JOIN users u ON u.id = s.user_id LEFT JOIN departments d ON d.id = s.department_id
                             WHERE ' . implode(' AND ', $w) . ' ORDER BY u.full_name');
        $st->execute($p);
        $rows = $st->fetchAll();
        break;

    case 'departments':
        $cols = ['department' => ['Department', 'text'], 'total' => ['Total staff', 'int'], 'active' => ['Active', 'int'], 'inactive' => ['Inactive', 'int'],
                 'admins' => ['Admin / HR', 'int'], 'salary' => ['Monthly salary (active)', 'money']];
        $rows = $pdo->query("SELECT COALESCE(d.name, 'No department') AS department, COUNT(*) AS total,
                                    SUM(s.employment_status = 'active') AS active, SUM(s.employment_status = 'inactive') AS inactive,
                                    SUM(u.role = 'admin') AS admins, SUM(CASE WHEN s.employment_status = 'active' THEN s.salary ELSE 0 END) AS salary
                             FROM staff s JOIN users u ON u.id = s.user_id LEFT JOIN departments d ON d.id = s.department_id
                             GROUP BY d.id, d.name ORDER BY department")->fetchAll();
        break;
}

/** Formats a value for HTML (money is already escaped) or for CSV. */
$fmt = function ($v, string $kind, bool $csv): string {
    if ($kind === 'money') return $csv ? number_format((float)$v, 2, '.', '') : money($v);
    if ($kind === 'date') return $csv ? (string)$v : fmt_date($v);
    if ($kind === 'minutes') return fmt_minutes((int)$v);
    if ($kind === 'int') return (string)(int)$v;
    if ($kind === 'label') return ucwords(str_replace('_', ' ', (string)($v ?? '')));
    return (string)($v ?? '');
};

// Totals for money columns
$totals = [];
foreach ($cols as $k => [$label, $kind]) {
    if ($kind === 'money') $totals[$k] = array_sum(array_map(fn($r) => (float)$r[$k], $rows));
}

if (get_str('export', 5) === 'csv') {
    $safe = function (string $s): string {
        return ($s !== '' && strpos("=+-@\t\r", $s[0]) !== false && !is_numeric($s)) ? "'" . $s : $s;
    };
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $type . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map(fn($c) => $c[0], array_values($cols)));
    foreach ($rows as $r) {
        $line = [];
        foreach ($cols as $k => [$label, $kind]) $line[] = $safe((string)$fmt($r[$k] ?? '', $kind, true));
        fputcsv($out, $line);
    }
    if ($totals && $rows) {
        $line = [];
        $first = true;
        foreach ($cols as $k => [$label, $kind]) {
            $line[] = isset($totals[$k]) ? number_format($totals[$k], 2, '.', '') : ($first ? 'Total' : '');
            $first = false;
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

$depts = departments();
$staffAll = all_staff_list();
$pageTitle = 'Reports';
$pageSub = 'Real-time reports from your database. Export to CSV or print.';
$navActive = 'reports';
include __DIR__ . '/../includes/header.php';
$has = fn(string $f) => in_array($f, $spec, true);
?>
<ul class="tab-pills no-print">
    <?php foreach ($reports as $k => $label): ?>
        <li><a class="<?= $type === $k ? ' active' : '' ?>" href="?report=<?= e($k) ?>"><?= e($label) ?></a></li>
    <?php endforeach; ?>
</ul>

<?php if ($spec): ?>
<section class="panel filter-panel no-print">
    <form class="row g-2" method="get">
        <input type="hidden" name="report" value="<?= e($type) ?>">
        <?php if ($has('range')): ?>
            <div class="col-md-2"><label class="form-label">From</label><input type="date" class="form-control" name="from" value="<?= e($from) ?>"></div>
            <div class="col-md-2"><label class="form-label">To</label><input type="date" class="form-control" name="to" value="<?= e($to) ?>"></div>
        <?php endif; ?>
        <?php if ($has('period')): ?>
            <div class="col-md-2"><label class="form-label">Month</label><?= month_select('month', $month) ?></div>
            <div class="col-md-2"><label class="form-label">Year</label><?= year_select('year', $year) ?></div>
        <?php endif; ?>
        <?php if ($has('department')): ?>
            <div class="col-md-3"><label class="form-label">Department</label><select class="form-select" name="department"><option value="">All departments</option>
                <?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= $dept === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <?php if ($has('staff')): ?>
            <div class="col-md-3"><label class="form-label">Staff</label><select class="form-select" name="staff"><option value="">All staff</option>
                <?php foreach ($staffAll as $s): ?><option value="<?= (int)$s['id'] ?>"<?= $staffF === (int)$s['id'] ? ' selected' : '' ?>><?= e($s['full_name'] . ' (' . $s['staff_code'] . ')') ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <?php if ($statusOptions): ?>
            <div class="col-md-2"><label class="form-label">Status</label><select class="form-select" name="status"><option value="">All</option>
                <?php foreach ($statusOptions as $o): ?><option value="<?= e($o) ?>"<?= $status === $o ? ' selected' : '' ?>><?= e(ucfirst($o)) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div class="col-md-2 d-flex align-items-end gap-2"><button class="btn btn-primary flex-fill">Run</button></div>
    </form>
</section>
<?php endif; ?>

<section class="panel">
    <div class="panel-head">
        <div><h2><?= e($reports[$type]) ?></h2>
            <div class="panel-sub"><?php if ($has('range')): ?><?= e(fmt_date($from) . ' to ' . fmt_date($to)) ?><?php endif; ?><?php if ($has('period')): ?><?= e(MONTH_NAMES[$month] . ' ' . $year) ?><?php endif; ?><?= ($has('range') || $has('period')) ? ' · ' : '' ?><?= count($rows) ?> row(s)</div></div>
        <div class="toolbar no-print">
            <a class="btn outline" href="?<?= e(http_build_query(array_merge($_GET, ['report' => $type, 'export' => 'csv']))) ?>">↓ CSV</a>
            <button class="btn" onclick="window.print()">⎙ Print</button>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><?php foreach ($cols as $k => [$label, $kind]): ?><th class="<?= in_array($kind, ['money', 'int', 'minutes'], true) ? 'text-end' : '' ?>"><?= e($label) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr><?php foreach ($cols as $k => [$label, $kind]): ?>
                    <td class="<?= in_array($kind, ['money', 'int', 'minutes'], true) ? 'text-end' : '' ?>"><?php
                        $txt = $fmt($r[$k] ?? '', $kind, false);
                        echo $kind === 'money' ? $txt : e($txt === '' ? '—' : $txt); ?></td>
                <?php endforeach; ?></tr>
            <?php endforeach; ?>
            <?php if ($totals && $rows): ?>
                <tr class="fw-semibold"><?php $first = true; foreach ($cols as $k => [$label, $kind]): ?>
                    <td class="<?= isset($totals[$k]) ? 'text-end' : '' ?>"><?= isset($totals[$k]) ? money($totals[$k]) : ($first ? 'Total' : '') ?></td>
                <?php $first = false; endforeach; ?></tr>
            <?php endif; ?>
            <?php if (!$rows): ?><tr><td colspan="<?= count($cols) ?>"><div class="empty-state"><i class="bi bi-bar-chart-line"></i>No data for these filters.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php include __DIR__ . '/../includes/footer.php';
