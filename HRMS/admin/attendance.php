<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$me  = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_str('action', 20);

    if ($action === 'manual') {
        $sid  = (int)post_str('staff_id', 10);
        $date = post_str('att_date', 10);
        $type = post_str('att_type', 10);
        $time = normalize_time(post_str('att_time', 8));
        $note = post_str('notes', 255);

        $chk = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE id = ? AND employment_status = 'active'");
        $chk->execute([$sid]);
        if (!(int)$chk->fetchColumn()) {
            flash_set('error', 'Select an active staff member.');
        } elseif (!valid_date($date) || $date > date('Y-m-d')) {
            flash_set('error', 'Enter a valid attendance date (not in the future).');
        } elseif ($time === null) {
            flash_set('error', 'Enter a valid time.');
        } elseif ($date === date('Y-m-d') && $time > date('H:i:s')) {
            flash_set('error', 'The time cannot be in the future.');
        } elseif (!in_array($type, ['in', 'out'], true)) {
            flash_set('error', 'Select Check In or Check Out.');
        } else {
            $ex = $pdo->prepare('SELECT id, check_in, check_out FROM attendance WHERE staff_id = ? AND att_date = ?');
            $ex->execute([$sid, $date]);
            $row = $ex->fetch();
            if ($type === 'in') {
                if ($row && $row['check_in'] !== null) {
                    flash_set('error', 'This staff member already has a check-in for that date. Use the Correct button to change it.');
                } else {
                    $status = attendance_status($time, null);
                    $pdo->prepare("INSERT INTO attendance (staff_id, att_date, check_in, status, source, notes, created_by) VALUES (?,?,?,?, 'manual', ?, ?)")
                        ->execute([$sid, $date, $time, $status, $note ?: null, $me['id']]);
                    audit_log('attendance_manual_in', 'attendance', (int)$pdo->lastInsertId(), ['staff_id' => $sid, 'date' => $date, 'time' => $time, 'notes' => $note]);
                    flash_set('success', 'Check-in recorded.');
                }
            } else {
                if (!$row || $row['check_in'] === null) {
                    flash_set('error', 'There is no check-in for that date. Record the check-in first.');
                } elseif ($row['check_out'] !== null) {
                    flash_set('error', 'This staff member already has a check-out for that date. Use the Correct button to change it.');
                } elseif ($time <= $row['check_in']) {
                    flash_set('error', 'Check-out time must be after the check-in time (' . substr($row['check_in'], 0, 5) . ').');
                } else {
                    $mins = work_minutes($row['check_in'], $time);
                    $pdo->prepare('UPDATE attendance SET check_out = ?, work_minutes = ?, status = ?, notes = COALESCE(?, notes), updated_by = ? WHERE id = ?')
                        ->execute([$time, $mins, attendance_status($row['check_in'], $mins), $note ?: null, $me['id'], $row['id']]);
                    audit_log('attendance_manual_out', 'attendance', (int)$row['id'], ['time' => $time, 'notes' => $note]);
                    flash_set('success', 'Check-out recorded.');
                }
            }
        }
    } elseif ($action === 'correct') {
        $id     = (int)post_str('id', 10);
        $in     = post_str('check_in', 8);
        $out    = post_str('check_out', 8);
        $reason = post_str('reason', 255);

        $cur = $pdo->prepare('SELECT * FROM attendance WHERE id = ?');
        $cur->execute([$id]);
        $row = $cur->fetch();
        $inT  = normalize_time($in);
        $outT = $out === '' ? null : normalize_time($out);
        if (!$row) {
            flash_set('error', 'Attendance record not found.');
        } elseif ($inT === null) {
            flash_set('error', 'Enter a valid check-in time.');
        } elseif ($out !== '' && $outT === null) {
            flash_set('error', 'Enter a valid check-out time.');
        } elseif ($outT !== null && $outT <= $inT) {
            flash_set('error', 'Check-out must be after check-in.');
        } elseif ($reason === '') {
            flash_set('error', 'A reason is required for every correction.');
        } else {
            $mins = $outT !== null ? work_minutes($inT, $outT) : null;
            $pdo->prepare("UPDATE attendance SET check_in = ?, check_out = ?, work_minutes = ?, status = ?, source = 'manual', notes = ?, updated_by = ? WHERE id = ?")
                ->execute([$inT, $outT, $mins, attendance_status($inT, $mins), $reason, $me['id'], $id]);
            audit_log('attendance_corrected', 'attendance', $id, [
                'old' => ['check_in' => $row['check_in'], 'check_out' => $row['check_out']],
                'new' => ['check_in' => $inT, 'check_out' => $outT], 'reason' => $reason,
            ]);
            flash_set('success', 'Attendance corrected and logged.');
        }
    }
    redirect('admin/attendance.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : ''));
}

// ---- filters ----
$from = get_str('from', 10) ?: date('Y-m-d', strtotime('-30 days'));
$to   = get_str('to', 10) ?: date('Y-m-d');
if (!valid_date($from)) $from = date('Y-m-d', strtotime('-30 days'));
if (!valid_date($to)) $to = date('Y-m-d');
$staffF = get_int('staff');
$dept   = get_int('department');
$status = get_str('status', 10);
$q      = get_str('q');

$where = ['a.att_date BETWEEN :from AND :to'];
$params = [':from' => $from, ':to' => $to];
if ($staffF > 0) { $where[] = 'a.staff_id = :sid'; $params[':sid'] = $staffF; }
if ($dept > 0) { $where[] = 's.department_id = :dept'; $params[':dept'] = $dept; }
if ($status === 'present') { $where[] = "a.status IN ('present','late')"; }
elseif ($status === 'half_day') { $where[] = "a.status = 'half_day'"; }
if ($q !== '') {
    $where[] = '(u.full_name LIKE :q1 OR s.staff_code LIKE :q2 OR a.notes LIKE :q3)';
    $params[':q1'] = $params[':q2'] = $params[':q3'] = '%' . $q . '%';
}
$sql = 'SELECT a.id, a.att_date, a.check_in, a.check_out, a.work_minutes, a.status, a.source, a.notes,
               u.full_name, s.staff_code, d.name AS department
        FROM attendance a JOIN staff s ON s.id = a.staff_id JOIN users u ON u.id = s.user_id
        LEFT JOIN departments d ON d.id = s.department_id WHERE ' . implode(' AND ', $where)
     . ' ORDER BY a.att_date DESC, u.full_name';
$list  = paginate($sql, $params, max(1, get_int('page')), 15);
$depts = departments();
$staff = all_staff_list();
$activeStaff = active_staff_list();

$pageTitle = 'Attendance Management';
$navActive = 'attendance';
$pageSub   = 'All staff attendance. Corrections are recorded in the audit log.';
$pageAction = modal_btn('manualModal', '＋ Manual Attendance', [], '', 'btn manual-btn');
$sum = $pdo->prepare("SELECT COUNT(*) rec, COALESCE(SUM(a.status IN ('present','late')),0) pres, COALESCE(SUM(a.status='half_day'),0) half
                      FROM attendance a WHERE a.att_date = CURDATE()");
$sum->execute();
$td = $sum->fetch();
$note = fn(string $t) => '<span class="positive">↗</span> ' . e($t);
include __DIR__ . '/../includes/header.php';
?>
<div class="stats">
    <?= stat_html('Checked in today', (string)(int)$td['rec'], '✓', $note('Today')) ?>
    <?= stat_html('Present', (string)(int)$td['pres'], '▣', $note('Today')) ?>
    <?= stat_html('Half days', (string)(int)$td['half'], '−', $note('Today')) ?>
</div>
<section class="panel filter-panel">
    <form class="row g-2" method="get">
        <div class="col-md-2"><label class="form-label">From</label><input type="date" class="form-control" name="from" value="<?= e($from) ?>"></div>
        <div class="col-md-2"><label class="form-label">To</label><input type="date" class="form-control" name="to" value="<?= e($to) ?>"></div>
        <div class="col-md-3"><label class="form-label">Staff</label><select class="form-select" name="staff"><option value="">All staff</option>
            <?php foreach ($staff as $s): ?><option value="<?= (int)$s['id'] ?>"<?= $staffF === (int)$s['id'] ? ' selected' : '' ?>><?= e($s['full_name'] . ' (' . $s['staff_code'] . ')') ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">Department</label><select class="form-select" name="department"><option value="">All departments</option>
            <?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= $dept === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Status</label><select class="form-select" name="status"><option value="">All</option>
            <?php foreach (['present' => 'Present', 'half_day' => 'Half day'] as $k => $v): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
        <div class="col-md-8"><input class="form-control" name="q" placeholder="Search name, Staff ID or notes" value="<?= e($q) ?>"></div>
        <div class="col-md-4 d-flex gap-2"><button class="btn btn-primary flex-fill">Filter</button><a class="btn btn-outline-primary" href="attendance.php">Reset</a></div>
    </form>
</section>

<section class="panel"><div class="table-wrap">
    <table class="table table-hover">
        <thead><tr><th>Date</th><th>Staff</th><th>Department</th><th>Check In</th><th>Check Out</th><th>Hours</th><th>Status</th><th>Source</th><th class="text-end">Action</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $r): ?>
            <tr>
                <td><?= e(fmt_date($r['att_date'])) ?></td>
                <td><?= e($r['full_name']) ?> <span class="text-muted tiny"><?= e($r['staff_code']) ?></span><?php if ($r['notes']): ?><div class="text-muted tiny"><?= e($r['notes']) ?></div><?php endif; ?></td>
                <td><?= e($r['department'] ?? '—') ?></td>
                <td><?= e(fmt_time($r['check_in'])) ?></td>
                <td><?= e(fmt_time($r['check_out'])) ?></td>
                <td><?= e(fmt_minutes($r['work_minutes'] !== null ? (int)$r['work_minutes'] : null)) ?></td>
                <td><?= badge($r['status']) ?></td>
                <td><?= badge($r['source']) ?></td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#correctModal"
                        data-fill='<?= json_attr(['id' => $r['id'], 'check_in' => substr((string)$r['check_in'], 0, 5), 'check_out' => substr((string)$r['check_out'], 0, 5), 'reason' => '']) ?>'
                        title="Correct"><i class="bi bi-pencil-square"></i></button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$list['rows']): ?><tr><td colspan="9"><div class="empty-state"><i class="bi bi-calendar-check"></i>No attendance records for these filters.</div></td></tr><?php endif; ?>
        </tbody>
    </table>
</div></section>
<?= render_pagination($list) ?>

<!-- Manual attendance -->
<div class="modal fade" id="manualModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="manual">
        <div class="modal-header"><h5 class="modal-title">Manual Attendance</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-12"><label class="form-label">Staff member *</label><select class="form-select" name="staff_id" required><option value="">Select staff</option>
                <?php foreach ($activeStaff as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['full_name'] . ' (' . $s['staff_code'] . ')') ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label">Attendance date *</label><input type="date" class="form-control" name="att_date" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>"></div>
            <div class="col-md-6"><label class="form-label">Attendance type *</label><select class="form-select" name="att_type" required><option value="in">Check In</option><option value="out">Check Out</option></select></div>
            <div class="col-md-6"><label class="form-label">Time *</label><input type="time" class="form-control" name="att_time" required></div>
            <div class="col-12"><label class="form-label">Reason / notes</label><input class="form-control" name="notes" maxlength="255"></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
    </form>
</div></div></div>

<!-- Correction -->
<div class="modal fade" id="correctModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="correct"><input type="hidden" name="id" value="">
        <div class="modal-header"><h5 class="modal-title">Correct Attendance</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-md-6"><label class="form-label">Check In *</label><input type="time" class="form-control" name="check_in" required></div>
            <div class="col-md-6"><label class="form-label">Check Out</label><input type="time" class="form-control" name="check_out"></div>
            <div class="col-12"><label class="form-label">Reason for correction *</label><input class="form-control" name="reason" required maxlength="255"></div>
            <div class="col-12 small text-muted">The old and new times are saved in the audit log.</div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save correction</button></div>
    </form>
</div></div></div>
 <?php include __DIR__ . '/../includes/footer.php';
