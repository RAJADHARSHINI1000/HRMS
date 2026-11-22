<?php
/**
 * One-time first-Admin creation.
 * - Only works while NO admin account exists, and only from the same computer (localhost).
 * - There is no default password: you choose it here.
 * After the admin is created this page refuses to run. You may also delete this file.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

$title = 'First Admin Setup';
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$message = '';
$done = false;
$blocked = '';

try {
    $adminCount = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
} catch (PDOException $ex) {
    $adminCount = -1;
}

if ($adminCount === -1) {
    $blocked = 'The database tables were not found. Import database/hrms_db.sql in phpMyAdmin first (see README).';
} elseif ($adminCount > 0) {
    $blocked = 'Setup is already completed. An Admin account exists. Please log in.';
} elseif (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    $blocked = 'For security, the first Admin can only be created from the computer that runs XAMPP (http://localhost/).';
}

if ($blocked === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name  = post_str('full_name', 100);
    $email = mb_strtolower(post_str('email', 150));
    $pass  = (string)($_POST['password'] ?? '');
    $conf  = (string)($_POST['confirm'] ?? '');

    if (mb_strlen($name) < 2) {
        $message = 'Enter the admin\'s full name.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Enter a valid email address.';
    } elseif (strlen($pass) < 8) {
        $message = 'The password must be at least 8 characters.';
    } elseif ($pass !== $conf) {
        $message = 'The passwords do not match.';
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $recheck = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' FOR UPDATE")->fetchColumn();
            if ($recheck > 0) {
                throw new RuntimeException('exists');
            }
            $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role, is_active) VALUES (?,?,?,'admin',1)")
                ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
            $uid = (int)$pdo->lastInsertId();
            $hrDept = $pdo->query("SELECT id FROM departments WHERE name = 'Human Resources'")->fetchColumn();
            $pdo->prepare("INSERT INTO staff (user_id, staff_code, department_id, designation, joining_date, employment_status, salary)
                           VALUES (?, 'ADM001', ?, 'HR Administrator', CURDATE(), 'active', 0)")
                ->execute([$uid, $hrDept ?: null]);
            $pdo->commit();
            audit_log('first_admin_created', 'user', $uid);
            $done = true;
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('setup failed: ' . $ex->getMessage());
            $message = $ex->getMessage() === 'exists' ? 'An Admin account already exists.' : 'Could not create the admin account. Check the PHP error log.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($title) ?> | HRMS</title>
    <link href="<?= e(url('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body>
<div class="login-page">
    <section class="login-art">
        <a class="brand" href="<?= e(url('login.php')) ?>"><span class="brand-mark">✦</span> HRMS</a>
        <div>
            <div class="badge" style="background:#ffffff20;color:#e5e0ff;margin-bottom:22px">ONE-TIME SETUP</div>
            <h1>Create the first Admin account.</h1>
            <p>You choose the password. There is no default login. This page stops working as soon as an Admin exists.</p>
        </div>
        <small style="color:#aeb7d8">© <?= date('Y') ?> · HRMS</small>
    </section>
    <section class="login-form-wrap">
        <div class="login-form">
            <h2><?= e($title) ?></h2>
            <p>Admin / HR account for this system.</p>
            <?php if ($blocked !== ''): ?>
                <div class="login-err"><?= e($blocked) ?></div>
                <a class="btn" style="width:100%;padding:13px" href="<?= e(url('login.php')) ?>">Go to login</a>
            <?php elseif ($done): ?>
                <div class="login-ok">Admin account created. For extra safety you may now delete <code>setup.php</code>.</div>
                <a class="btn" style="width:100%;padding:13px" href="<?= e(url('login.php')) ?>">Go to login →</a>
            <?php else: ?>
                <?php if ($message): ?><div class="login-err"><?= e($message) ?></div><?php endif; ?>
                <form method="post" autocomplete="off">
                    <?= csrf_field() ?>
                    <div class="field"><label>Full name</label><input name="full_name" required maxlength="100" value="<?= e($_POST['full_name'] ?? '') ?>"></div>
                    <div class="field"><label>Email (used to log in)</label><input type="email" name="email" required maxlength="150" value="<?= e($_POST['email'] ?? '') ?>"></div>
                    <div class="field"><label>Password (min 8 characters)</label><input type="password" name="password" required minlength="8"></div>
                    <div class="field"><label>Confirm password</label><input type="password" name="confirm" required minlength="8"></div>
                    <button class="btn" style="width:100%;padding:13px" type="submit">Create Admin account</button>
                </form>
            <?php endif; ?>
        </div>
    </section>
</div>
</body>
</html>
