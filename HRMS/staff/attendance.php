<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$me  = require_staff();
$pdo = db();
$sid = (int)$me['staff_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_str('action', 10);
    $today  = date('Y-m-d');
    $now    = date('H:i:s');

    if ($me['employment_status'] !== 'active') {
        flash_set('error', 'Your account is not active.');
    } elseif ($action === 'checkin') {
        $ex = $pdo->prepare('SELECT id FROM attendance WHERE staff_id = ? AND att_date = ?');
        $ex->execute([$sid, $today]);
        if ($ex->fetch()) {
            flash_set('error', 'You have already checked in today.');
        } else {
            try {
                $pdo->prepare("INSERT INTO attendance (staff_id, att_date, check_in, status, source, created_by) VALUES (?,?,?,?, 'self', ?)")
                    ->execute([$sid, $today, $now, attendance_status($now, null), $me['id']]);
                flash_set('success', 'Checked in at ' . fmt_time($now) . '.');
            } catch (PDOException $ex2) {
                flash_set('error', 'You have already checked in today.'); // unique (staff, date) guard
            }
        }
    } elseif ($action === 'checkout') {
        $ex = $pdo->prepare('SELECT id, check_in, check_out FROM attendance WHERE staff_id = ? AND att_date = ?');
        $ex->execute([$sid, $today]);
        $row = $ex->fetch();
        if (!$row || $row['check_in'] === null) {
            flash_set('error', 'You have not checked in today.');
        } elseif ($row['check_out'] !== null) {
            flash_set('error', 'You have already checked out today.');
        } elseif ($now <= $row['check_in']) {
            flash_set('error', 'Check-out must be later than your check-in.');
        } else {
            $mins = work_minutes($row['check_in'], $now);
            $pdo->prepare('UPDATE attendance SET check_out = ?, work_minutes = ?, status = ? WHERE id = ? AND check_out IS NULL')
                ->execute([$now, $mins, attendance_status($row['check_in'], $mins), $row['id']]);
            flash_set('success', 'Checked out at ' . fmt_time($now) . '. Worked ' . fmt_minutes($mins) . '.');
        }
    }
    redirect('staff/attendance.php');
}

$t = $pdo->prepare('SELECT check_in, check_out, status, work_minutes FROM attendance WHERE staff_id = ? AND att_date = CURDATE()');
$t->execute([$sid]);
$todayRow = $t->fetch();

$from = get_str('from', 10) ?: date('Y-m-01');
$to   = get_str('to', 10) ?: date('Y-m-d');
if (!valid_date($from)) $from = date('Y-m-01');
if (!valid_date($to)) $to = date('Y-m-d');
$list = paginate('SELECT att_date, check_in, check_out, work_minutes, status, source, notes FROM attendance
                  WHERE staff_id = :sid AND att_date BETWEEN :from AND :to ORDER BY att_date DESC',
                 [':sid' => $sid, ':from' => $from, ':to' => $to], max(1, get_int('page')), 15);

$sum = $pdo->prepare("SELECT COUNT(*) days, COALESCE(SUM(status='half_day'),0) half, COALESCE(SUM(work_minutes),0) mins
                      FROM attendance WHERE staff_id = ? AND att_date BETWEEN ? AND ?");
$sum->execute([$sid, $from, $to]);
$summary = $sum->fetch();

$pageTitle = 'My Attendance';
$navActive = 'attendance';
$pageSub = 'Track attendance and keep daily records up to date.';
$note = fn(string $t) => '<span class="positive">↗</span> ' . e($t);
include __DIR__ . '/../includes/header.php';
?>
<section class="panel"><div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <div class="text-muted small">Today &mdash; <?= e(date('l, d M Y')) ?></div>
        <?php if (!$todayRow): ?>
            <div class="fs-5 fw-medium">Not checked in yet</div>
        <?php else: ?>
            <div class="fs-5 fw-medium">In: <?= e(fmt_time($todayRow['check_in'])) ?> &nbsp;|&nbsp; Out: <?= e(fmt_time($todayRow['check_out'])) ?> <?= badge($todayRow['status']) ?></div>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="checkin">
            <button class="btn" <?= $todayRow ? 'disabled' : '' ?>>Check in</button></form>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="checkout">
            <button class="btn danger" <?= (!$todayRow || $todayRow['check_out'] !== null) ? 'disabled' : '' ?>>Check out</button></form>
    </div>
</div></section>

<div class="stats">
    <?= stat_html('Days present', (string)(int)$summary['days'], '✓', $note('In selected range')) ?>
    <?= stat_html('Half days', (string)(int)$summary['half'], '−', $note('In selected range')) ?>
    <?= stat_html('Hours worked', e(fmt_minutes((int)$summary['mins'])), '▦', $note('In selected range')) ?>
</div>

<section class="panel filter-panel">
    <form class="row g-2" method="get">
        <div class="col-md-4"><label class="form-label">From</label><input type="date" class="form-control" name="from" value="<?= e($from) ?>"></div>
        <div class="col-md-4"><label class="form-label">To</label><input type="date" class="form-control" name="to" value="<?= e($to) ?>"></div>
        <div class="col-md-4 d-flex align-items-end gap-2"><button class="btn btn-primary flex-fill">Filter</button><a class="btn btn-outline-primary" href="attendance.php">Reset</a></div>
    </form>
</section>

<section class="panel"><div class="table-wrap">
    <table class="table table-hover">
        <thead><tr><th>Date</th><th>Check In</th><th>Check Out</th><th>Hours</th><th>Status</th><th>Notes</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $r): ?>
            <tr><td><?= e(fmt_date($r['att_date'])) ?></td><td><?= e(fmt_time($r['check_in'])) ?></td><td><?= e(fmt_time($r['check_out'])) ?></td>
                <td><?= e(fmt_minutes($r['work_minutes'] !== null ? (int)$r['work_minutes'] : null)) ?></td><td><?= badge($r['status']) ?></td><td><?= e($r['notes'] ?? '') ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$list['rows']): ?><tr><td colspan="6"><div class="empty-state"><i class="bi bi-calendar-check"></i>No attendance records in this range.</div></td></tr><?php endif; ?>
        </tbody>
    </table>
</div></section>
<?= render_pagination($list) ?>
<?php include __DIR__ . '/../includes/footer.php';
