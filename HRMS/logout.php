<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

// Logging out is a POST with a CSRF token (the Logout button in every page header).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!empty($_SESSION['user_id'])) {
        audit_log('logout', 'user', (int)$_SESSION['user_id']);
    }
    do_logout();
    start_secure_session();
    flash_set('success', 'You have been logged out.');
}
redirect('login.php');
