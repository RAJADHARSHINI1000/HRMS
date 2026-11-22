<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

if (current_user() !== null) {
    redirect(home_for_role(current_user()['role']));
}

$error = '';
$maxAttempts = max(3, (int)get_setting('max_login_attempts', '5'));
$lockSeconds = 300;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = mb_strtolower(post_str('email', 150));
    $pass  = (string)($_POST['password'] ?? '');

    if (($_SESSION['lock_until'] ?? 0) > time()) {
        $error = 'Too many failed attempts. Please wait a few minutes and try again.';
    } else {
        $st = db()->prepare('SELECT id, password_hash, role, is_active FROM users WHERE email = ?');
        $st->execute([$email]);
        $u = $st->fetch();
        // Always run password_verify so response time does not reveal whether the email exists.
        $hash = $u['password_hash'] ?? password_hash('dummy-password', PASSWORD_DEFAULT);
        // The panel is chosen by the account's real role (Admin/HR or Staff), so a wrong button choice can never block a login.
        $ok = password_verify($pass, $hash) && $u && (int)$u['is_active'] === 1;

        if ($ok) {
            session_regenerate_id(true);
            $_SESSION = ['user_id' => (int)$u['id'], 'last_activity' => time()];
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
            }
            db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
            audit_log('login', 'user', (int)$u['id']);
            redirect(home_for_role($u['role']));
        }

        $_SESSION['login_fail'] = (int)($_SESSION['login_fail'] ?? 0) + 1;
        if ($_SESSION['login_fail'] >= $maxAttempts) {
            $_SESSION['lock_until'] = time() + $lockSeconds;
            $_SESSION['login_fail'] = 0;
        }
        $error = 'Invalid email or password, or the account is inactive. Ask HR to check your account.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Login | <?= e(get_setting('company_name', 'HRMS')) ?></title>
    <link href="<?= e(url('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body>
<div class="login-page">
    <section class="login-art">
        <a class="brand" href="<?= e(url('login.php')) ?>"><span class="brand-mark">✦</span> <?= e(get_setting('company_name', 'HRMS')) ?></a>
        <div>
            <div class="badge" style="background:#ffffff20;color:#e5e0ff;margin-bottom:22px">PEOPLE OPERATIONS, SIMPLIFIED</div>
            <h1>Great teams start with better people management.</h1>
            <p>One thoughtful workspace for attendance, time off, incentives and payroll — built to make everyday work feel simpler.</p>
        </div>
        <small style="color:#aeb7d8">© <?= date('Y') ?> · <?= e(get_setting('company_name', 'HRMS')) ?></small>
    </section>
    <section class="login-form-wrap">
        <form class="login-form" method="post" autocomplete="off">
            <?= csrf_field() ?>
            <div class="brand" style="color:var(--navy);padding:0 0 25px"><span class="brand-mark">✦</span> <?= e(get_setting('company_name', 'HRMS')) ?></div>
            <h2>Welcome back</h2>
            <p>Choose your workspace to continue.</p>
            <?= flash_render() ?>
            <?php if ($error): ?><div class="login-err"><?= e($error) ?></div><?php endif; ?>
            <?php $picked = (($_POST['role'] ?? 'admin') === 'staff') ? 'staff' : 'admin'; ?>
            <label style="font-size:12px;font-weight:700">Sign in as</label>
            <input type="hidden" name="role" id="roleInput" value="<?= e($picked) ?>">
            <div class="role-pick">
                <button class="<?= $picked === 'admin' ? 'selected' : '' ?>" data-role="admin" type="button">♙ &nbsp;Admin / HR</button>
                <button class="<?= $picked === 'staff' ? 'selected' : '' ?>" data-role="staff" type="button">♧ &nbsp;Staff</button>
            </div>
            <div class="field"><label for="email">Email address</label>
                <input id="email" name="email" placeholder="you@company.com" required type="email" maxlength="150" autofocus value="<?= e($_POST['email'] ?? '') ?>"></div>
            <div class="field"><label for="password">Password</label>
                <input id="password" name="password" placeholder="Enter your password" required type="password"></div>
            <button class="btn" style="width:100%;padding:13px" type="submit">Continue to workspace →</button>
        </form>
    </section>
</div>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
