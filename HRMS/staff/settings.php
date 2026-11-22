<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$me = require_staff();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_str('action', 20) === 'password') {
    $err = change_password((int)$me['id'], (string)($_POST['current'] ?? ''), (string)($_POST['new'] ?? ''), (string)($_POST['confirm'] ?? ''));
    flash_set($err ? 'error' : 'success', $err ?: 'Password changed.');
    redirect('staff/settings.php');
}

$pageTitle = 'Settings';
$navActive = 'settings';
$pageSub   = 'Manage your account security.';
include __DIR__ . '/../includes/header.php';
?>
<section class="panel" style="max-width:560px">
    <div class="panel-head"><div><h2>Change password</h2><div class="panel-sub">Use at least 8 characters</div></div></div>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="action" value="password">
        <div class="field"><label>Current password</label><input type="password" name="current" required autocomplete="current-password"></div>
        <div class="field"><label>New password</label><input type="password" name="new" required minlength="8" autocomplete="new-password"></div>
        <div class="field"><label>Confirm new password</label><input type="password" name="confirm" required minlength="8" autocomplete="new-password"></div>
        <button class="btn" type="submit">Update password</button>
    </form>
</section>
<section class="panel" style="max-width:560px">
    <div class="panel-head"><div><h2>Sign out</h2><div class="panel-sub">End this session on this device</div></div></div>
    <form method="post" action="<?= e(url('logout.php')) ?>"><?= csrf_field() ?><button class="btn outline" type="submit" data-keep>Sign out</button></form>
</section>
<?php include __DIR__ . '/../includes/footer.php';
