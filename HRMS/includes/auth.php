<?php
/**
 * Authentication, sessions and role checks.
 * Every protected page starts with:  require_once __DIR__ . '/../includes/auth.php';  require_admin();  (or require_staff();)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('HRMS_SID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

start_secure_session();

/** The logged-in user, always re-read from the database so deactivation/role changes apply immediately. */
function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    if (empty($_SESSION['user_id'])) {
        return $user = null;
    }
    $st = db()->prepare('SELECT u.id, u.full_name, u.email, u.role, u.is_active, s.id AS staff_id, s.staff_code, s.employment_status
                         FROM users u LEFT JOIN staff s ON s.user_id = u.id WHERE u.id = ?');
    $st->execute([(int)$_SESSION['user_id']]);
    $row = $st->fetch();
    if (!$row || !(int)$row['is_active']) {
        return $user = null;
    }
    return $user = $row;
}

function do_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
}

function home_for_role(string $role): string
{
    return $role === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php';
}

/** Requires a logged-in user (optionally with a given role) and verifies CSRF on POST. */
function require_login(?string $role = null): array
{
    $user = current_user();
    if ($user === null) {
        if (!empty($_SESSION['user_id'])) {
            do_logout();
            start_secure_session();
        }
        redirect('login.php');
    }

    $timeout = max(5, (int)get_setting('session_timeout_minutes', '30')) * 60;
    if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > $timeout) {
        do_logout();
        start_secure_session();
        flash_set('warning', 'You were logged out because of inactivity. Please log in again.');
        redirect('login.php');
    }
    $_SESSION['last_activity'] = time();

    if ($role !== null && $user['role'] !== $role) {
        http_response_code(403);
        exit('<!doctype html><meta charset="utf-8"><title>Access denied</title>'
           . '<body style="font-family:sans-serif;padding:3rem"><h2>403 - Access denied</h2>'
           . '<p>You do not have permission to open this page.</p>'
           . '<p><a href="' . e(url(home_for_role($user['role']))) . '">Back to my dashboard</a></p></body>');
    }
    csrf_verify();
    return $user;
}

function require_admin(): array
{
    return require_login('admin');
}

function require_staff(): array
{
    $user = require_login('staff');
    if (empty($user['staff_id'])) {
        http_response_code(403);
        exit('Your account has no staff profile. Please contact HR.');
    }
    return $user;
}

/** Changes a password after checking the current one. Returns an error message or null on success. */
function change_password(int $userId, string $current, string $new, string $confirm): ?string
{
    if (strlen($new) < 8) {
        return 'The new password must be at least 8 characters.';
    }
    if ($new !== $confirm) {
        return 'The new password and its confirmation do not match.';
    }
    $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([$userId]);
    $hash = $st->fetchColumn();
    if (!$hash || !password_verify($current, (string)$hash)) {
        return 'The current password is incorrect.';
    }
    $up = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $up->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
    audit_log('password_changed', 'user', $userId);
    return null;
}
