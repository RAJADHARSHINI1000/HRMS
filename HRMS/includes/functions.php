<?php
/**
 * Shared helper functions.
 */
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

// Work out the web path of the project (e.g. "/HRMS") so links work in any htdocs subfolder.
if (!defined('BASE_URL')) {
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string)$_SERVER['DOCUMENT_ROOT']) : false;
    $docRoot = $docRoot ? rtrim(str_replace('\\', '/', $docRoot), '/') : '';
    $root    = rtrim(str_replace('\\', '/', ROOT_PATH), '/');
    if ($docRoot !== '' && strpos(strtolower($root), strtolower($docRoot)) === 0) {
        define('BASE_URL', substr($root, strlen($docRoot)));
    } else {
        define('BASE_URL', '/HRMS');
    }
}

const DEDUCTION_CATEGORIES = ['Late Arrival', 'Absence', 'Uniform / Damage', 'Other'];
const MONTH_NAMES = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

/* ---------- output / urls ---------- */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

/** JSON for use inside an HTML attribute. */
function json_attr($data): string
{
    return e(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/* ---------- flash messages ---------- */
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $message];
}

function flash_render(): string
{
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as $f) {
        $cls = $f['type'] === 'error' ? 'danger' : $f['type'];
        $out .= '<div class="alert alert-' . e($cls) . ' alert-dismissible fade show" role="alert">'
              . e($f['msg'])
              . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

/* ---------- settings ---------- */
function setting_defaults(): array
{
    return [
        'company_name' => 'My Company', 'company_address' => '', 'company_email' => '', 'company_phone' => '',
        'currency_symbol' => 'Rs.', 'work_start' => '09:30', 'work_end' => '18:00', 'late_grace_minutes' => '10',
        'half_day_hours' => '4', 'weekly_off_days' => '7', 'permission_max_hours' => '4', 'payroll_pay_day' => '5',
        'session_timeout_minutes' => '30', 'max_login_attempts' => '5',
    ];
}

function get_setting(string $key, ?string $default = null): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = setting_defaults();
        try {
            foreach (db()->query('SELECT setting_key, setting_value FROM system_settings') as $row) {
                $cache[$row['setting_key']] = (string)$row['setting_value'];
            }
        } catch (PDOException $ex) {
            error_log('settings load failed: ' . $ex->getMessage());
        }
    }
    return $cache[$key] ?? ($default ?? '');
}

function save_setting(string $key, string $value): void
{
    $st = db()->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $st->execute([$key, $value]);
}

/* ---------- request input ---------- */
function post_str(string $key, int $max = 255): string
{
    $v = $_POST[$key] ?? '';
    if (!is_string($v)) {
        return '';
    }
    return mb_substr(trim($v), 0, $max);
}

function get_str(string $key, int $max = 100): string
{
    $v = $_GET[$key] ?? '';
    if (!is_string($v)) {
        return '';
    }
    return mb_substr(trim($v), 0, $max);
}

function get_int(string $key): int
{
    return (int)get_str($key, 12);
}

function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}

/** Accepts HH:MM or HH:MM:SS and returns HH:MM:SS, or null if invalid. */
function normalize_time(string $t): ?string
{
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $t, $m)) {
        return null;
    }
    return sprintf('%s:%s:%s', $m[1], $m[2], $m[3] ?? '00');
}

function valid_money(string $v): bool
{
    return (bool)preg_match('/^\d{1,10}(\.\d{1,2})?$/', $v);
}

/* ---------- formatting ---------- */
function money($n): string
{
    return e(get_setting('currency_symbol', 'Rs.')) . ' ' . number_format((float)$n, 2);
}

function fmt_date(?string $d): string
{
    if (!$d || $d === '0000-00-00') {
        return '—';
    }
    $ts = strtotime($d);
    return $ts ? date('d M Y', $ts) : '—';
}

function fmt_time(?string $t): string
{
    if (!$t) {
        return '—';
    }
    $ts = strtotime($t);
    return $ts ? date('h:i A', $ts) : '—';
}

function fmt_datetime(?string $d): string
{
    if (!$d) {
        return '—';
    }
    $ts = strtotime($d);
    return $ts ? date('d M Y, h:i A', $ts) : '—';
}

function fmt_minutes(?int $m): string
{
    if ($m === null) {
        return '—';
    }
    return sprintf('%dh %02dm', intdiv($m, 60), $m % 60);
}

function badge(string $status): string
{
    if ($status === 'late') {
        $status = 'present'; // old records saved before "Late" was removed
    }
    $map = [
        'pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'active' => 'success',
        'inactive' => 'secondary', 'cancelled' => 'secondary', 'draft' => 'warning', 'paid' => 'success',
        'unpaid' => 'warning', 'present' => 'success', 'late' => 'warning', 'half_day' => 'info',
        'self' => 'secondary', 'manual' => 'primary',
    ];
    $cls = $map[$status] ?? 'secondary';
    return '<span class="badge badge-soft-' . $cls . '">' . e(ucwords(str_replace('_', ' ', $status))) . '</span>';
}

/* ---------- audit ---------- */
function audit_log(string $action, string $entity, ?int $entityId = null, array $details = []): void
{
    try {
        $st = db()->prepare('INSERT INTO audit_logs (user_id, action, entity, entity_id, details, ip_address) VALUES (?,?,?,?,?,?)');
        $st->execute([
            $_SESSION['user_id'] ?? null, $action, $entity, $entityId,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);
    } catch (PDOException $ex) {
        error_log('audit_log failed: ' . $ex->getMessage());
    }
}

/* ---------- pagination ---------- */
function paginate(string $sql, array $params, int $page, int $perPage = 10): array
{
    $pdo = db();
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM ($sql) t");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = min(max(1, $page), $pages);
    $off   = ($page - 1) * $perPage;
    $st = $pdo->prepare($sql . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$off);
    $st->execute($params);
    return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $perPage];
}

function render_pagination(array $p): string
{
    if ($p['pages'] <= 1) {
        return '<div class="small text-muted mt-2">' . (int)$p['total'] . ' record(s)</div>';
    }
    $q = $_GET;
    $link = function (int $pg, string $label, bool $disabled = false, bool $active = false) use ($q) {
        $q['page'] = $pg;
        $href = '?' . http_build_query($q);
        return '<li class="page-item' . ($disabled ? ' disabled' : '') . ($active ? ' active' : '') . '">'
             . '<a class="page-link" href="' . e($href) . '">' . $label . '</a></li>';
    };
    $out = '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">'
         . '<div class="small text-muted">' . (int)$p['total'] . ' record(s) &middot; page ' . (int)$p['page'] . ' of ' . (int)$p['pages'] . '</div>'
         . '<nav><ul class="pagination pagination-sm mb-0">';
    $out .= $link($p['page'] - 1, '&laquo;', $p['page'] <= 1);
    $start = max(1, $p['page'] - 2);
    $end   = min($p['pages'], $p['page'] + 2);
    for ($i = $start; $i <= $end; $i++) {
        $out .= $link($i, (string)$i, false, $i === $p['page']);
    }
    $out .= $link($p['page'] + 1, '&raquo;', $p['page'] >= $p['pages']);
    return $out . '</ul></nav></div>';
}

/* ---------- lookups ---------- */
function departments(): array
{
    return db()->query('SELECT id, name FROM departments ORDER BY name')->fetchAll();
}

/** Finds a department by name (case-insensitive) or creates it. Empty name = no department. */
function department_id_for(string $name): ?int
{
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name === '') {
        return null;
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM departments WHERE name = ?');
    $st->execute([$name]);
    $id = $st->fetchColumn();
    if ($id) {
        return (int)$id;
    }
    $pdo->prepare('INSERT INTO departments (name) VALUES (?)')->execute([$name]);
    return (int)$pdo->lastInsertId();
}

function active_staff_list(): array
{
    return db()->query("SELECT s.id, s.staff_code, u.full_name FROM staff s JOIN users u ON u.id = s.user_id
                        WHERE s.employment_status = 'active' ORDER BY u.full_name")->fetchAll();
}

function all_staff_list(): array
{
    return db()->query('SELECT s.id, s.staff_code, u.full_name FROM staff s JOIN users u ON u.id = s.user_id
                        ORDER BY u.full_name')->fetchAll();
}

function active_leave_types(): array
{
    return db()->query('SELECT id, name, days_per_year FROM leave_types WHERE is_active = 1 ORDER BY name')->fetchAll();
}

/* ---------- work-day / attendance rules ---------- */
function weekly_off_days(): array
{
    $v = get_setting('weekly_off_days', '7');
    $days = array_filter(array_map('intval', explode(',', $v)), fn($d) => $d >= 1 && $d <= 7);
    return array_values($days);
}

function is_working_day(string $date): bool
{
    return !in_array((int)date('N', strtotime($date)), weekly_off_days(), true);
}

function count_working_days(string $from, string $to): int
{
    $n = 0;
    $d = strtotime($from);
    $end = strtotime($to);
    while ($d <= $end) {
        if (!in_array((int)date('N', $d), weekly_off_days(), true)) {
            $n++;
        }
        $d = strtotime('+1 day', $d);
    }
    return $n;
}

function time_to_min(string $t): int
{
    $p = explode(':', $t);
    return ((int)$p[0]) * 60 + (int)($p[1] ?? 0);
}

function work_minutes(string $in, string $out): int
{
    return max(0, time_to_min($out) - time_to_min($in));
}

/** present / half_day. Staff work in different time slots, so there is no "Late" status. */
function attendance_status(string $checkIn, ?int $workMinutes): string
{
    $status = 'present';
    if ($workMinutes !== null && $workMinutes < (float)get_setting('half_day_hours', '4') * 60) {
        $status = 'half_day';
    }
    return $status;
}

/* ---------- uploads ---------- */
/** Validates and stores an uploaded supporting document. Returns [storedName, originalName] or an error string. */
function store_uploaded_document(array $file)
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return 'The document could not be uploaded.';
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        return 'The document must be 2 MB or smaller.';
    }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $allowed = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    if (!isset($allowed[$ext])) {
        return 'Only PDF, JPG or PNG documents are allowed.';
    }
    $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : $allowed[$ext];
    if ($mime !== $allowed[$ext]) {
        return 'The file content does not match its extension.';
    }
    $dir = ROOT_PATH . '/uploads/documents';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return 'The upload folder is missing.';
    }
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
        return 'The document could not be saved.';
    }
    $orig = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename((string)$file['name']));
    return [$stored, mb_substr($orig, 0, 150)];
}

/* ---------- payroll period helpers ---------- */
/** True when an approved/paid payroll exists, so the month's incentives/deductions can no longer change. */
function period_locked(int $staffId, int $month, int $year): bool
{
    $st = db()->prepare("SELECT COUNT(*) FROM payroll WHERE staff_id = ? AND period_month = ? AND period_year = ? AND status IN ('approved','paid')");
    $st->execute([$staffId, $month, $year]);
    return (int)$st->fetchColumn() > 0;
}

function month_select(string $name, int $selected, bool $required = true): string
{
    $o = '<select name="' . e($name) . '" class="form-select"' . ($required ? ' required' : '') . '>';
    foreach (MONTH_NAMES as $n => $label) {
        $o .= '<option value="' . $n . '"' . ($n === $selected ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $o . '</select>';
}

function year_select(string $name, int $selected): string
{
    $cur = (int)date('Y');
    $o = '<select name="' . e($name) . '" class="form-select" required>';
    for ($y = $cur + 1; $y >= $cur - 5; $y--) {
        $o .= '<option value="' . $y . '"' . ($y === $selected ? ' selected' : '') . '>' . $y . '</option>';
    }
    return $o . '</select>';
}

/* ---------- PeopleFlow UI helpers ---------- */
/** One statistic card (.stat). $value and $note are already-safe HTML/text built by the caller. */
function stat_html(string $label, string $value, string $glyph = '▦', string $note = '', string $href = ''): string
{
    $tag = $href !== '' ? 'a href="' . e($href) . '"' : 'div';
    $end = $href !== '' ? 'a' : 'div';
    return '<' . $tag . ' class="stat"><div class="stat-head">' . e($label) . '<span class="stat-icon">' . e($glyph) . '</span></div>'
         . '<div class="stat-value">' . $value . '</div>'
         . ($note !== '' ? '<div class="stat-note">' . $note . '</div>' : '') . '</' . $end . '>';
}

/** A button that opens a modal (works with assets/js/app.js). */
function modal_btn(string $target, string $label, array $fill = [], string $title = '', string $class = 'btn', bool $reset = true): string
{
    return '<button type="button" class="' . e($class) . '" data-bs-toggle="modal" data-bs-target="#' . e($target) . '"'
         . ($reset ? ' data-reset' : '')
         . ($title !== '' ? ' data-title="' . e($title) . '"' : '')
         . ($fill ? ' data-fill=\'' . json_attr($fill) . '\'' : '')
         . '>' . $label . '</button>';
}

/** Vertical bar chart using the theme's CSS (no JS library). $series = [[label, value], ...] */
function bar_chart_html(array $series, bool $money = false): string
{
    $max = 0.0;
    foreach ($series as [$l, $v]) $max = max($max, (float)$v);
    $out = '<div class="bar-chart">';
    foreach ($series as [$l, $v]) {
        $h = $max > 0 ? max(3, (int)round(((float)$v / $max) * 100)) : 3;
        $tip = $money ? strip_tags(money($v)) : (string)(int)$v;
        $out .= '<div class="bar-group" title="' . e($l . ': ' . $tip) . '"><div class="bars"><div class="bar primary" style="height:' . $h . '%"></div></div>'
              . '<span>' . e($l) . '</span><strong style="color:var(--ink);font-size:10px">' . ($money ? e(number_format((float)$v, 0)) : (int)$v) . '</strong></div>';
    }
    return $out . '</div>';
}
