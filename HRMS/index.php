<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

$user = current_user();
if ($user !== null) {
    redirect(home_for_role($user['role']));
}

// Fresh install: no admin account yet -> go to the one-time setup page.
try {
    $admins = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
} catch (PDOException $ex) {
    exit('The database tables were not found. Import database/hrms_db.sql in phpMyAdmin first (see README).');
}
redirect($admins === 0 ? 'setup.php' : 'login.php');
