<?php
/**
 * Common page header: PeopleFlow sidebar + topbar + page heading.
 * Pages set before including:  $pageTitle, $navActive  and optionally  $pageSub, $pageAction, $heading, $noWelcome.
 */
declare(strict_types=1);

$authUser  = current_user();
$isAdmin   = $authUser && $authUser['role'] === 'admin';
$pageTitle = $pageTitle ?? 'HRMS';
$navActive = $navActive ?? '';

if ($isAdmin) {
    $navItems = [
        'dashboard'      => ['Dashboard', '◈'],
        'staff'          => ['Staff Management', '♙'],
        'attendance'     => ['Attendance Management', '▣'],
        'leave'          => ['Leave Management', '▤'],
        'timepermission' => ['Time Permission', '◷'],
        'incentives'     => ['Incentive Management', '％'],
        'deductions'     => ['Deductions Management', '−'],
        'payroll'        => ['Payroll Management', '▤'],
        'reports'        => ['Reports', '▥'],
        'settings'       => ['Settings', '⚙'],
    ];
    $navBase   = url('admin/');
    $workspace = 'Admin workspace';
    $roleLabel = 'Admin account';
} else {
    $navItems = [
        'dashboard'      => ['Dashboard', '◈'],
        'profile'        => ['My Profile', '♙'],
        'attendance'     => ['Attendance', '▣'],
        'leave'          => ['Leave Requests', '▤'],
        'timepermission' => ['Time Permission', '◷'],
        'incentives'     => ['My Incentives', '％'],
        'deductions'     => ['My Deductions', '−'],
        'salary'         => ['My Salary & Payslip', '▤'],
        'settings'       => ['Settings', '⚙'],
    ];
    $navBase   = url('staff/');
    $workspace = 'Staff workspace';
    $roleLabel = 'Employee account';
}
$initial = mb_strtoupper(mb_substr((string)($authUser['full_name'] ?? 'U'), 0, 1));
$crumbTitle = $pageTitle;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e(get_setting('company_name', 'HRMS')) ?></title>
    <link href="<?= e(url('assets/vendor/bootstrap-grid.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(url('assets/vendor/bootstrap-utilities.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(url('assets/vendor/bootstrap-icons.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(url('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body>
<div class="app">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="<?= e($navBase . 'dashboard.php') ?>">
            <span class="brand-mark">✦</span>
            <?= e(mb_strimwidth(get_setting('company_name', 'HRMS'), 0, 18, '…')) ?>
        </a>
        <div class="nav-label"><?= e($workspace) ?></div>
        <nav class="nav">
            <?php foreach ($navItems as $key => [$label, $glyph]): ?>
                <a class="<?= $navActive === $key ? 'active' : '' ?>" href="<?= e($navBase . $key . '.php') ?>">
                    <span><?= e($glyph) ?></span><span><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-bottom">
            <div class="user-mini">
                <div class="avatar"><?= e($initial) ?></div>
                <div>
                    <strong><?= e($authUser['full_name'] ?? '') ?></strong>
                    <small><?= e($roleLabel) ?></small>
                </div>
            </div>
        </div>
    </aside>

    <main class="main">
        <header class="topbar no-print">
            <div style="display:flex;align-items:center;gap:12px">
                <button aria-label="Open navigation" class="icon-btn mobile-menu" id="sidebarToggle" type="button">☰</button>
                <div>
                    <div class="crumb"><?= e($workspace) ?> / <?= e($crumbTitle) ?></div>
                    <div class="page-title"><?= e($crumbTitle) ?></div>
                </div>
            </div>
            <div class="top-actions">
                <span class="badge gray hide-mobile"><?= e(date('d M Y')) ?></span>
                <form method="post" action="<?= e(url('logout.php')) ?>">
                    <?= csrf_field() ?>
                    <button class="icon-btn" type="submit" title="Sign out" aria-label="Sign out"><i class="bi bi-box-arrow-right"></i></button>
                </form>
                <div class="avatar"><?= e($initial) ?></div>
            </div>
        </header>
        <div class="content">
            <?= flash_render() ?>
            <?php if (empty($noWelcome)): ?>
            <div class="welcome">
                <div>
                    <h1><?= e($heading ?? $pageTitle) ?></h1>
                    <?php if (!empty($pageSub)): ?><p><?= $pageSub ?></p><?php endif; ?>
                </div>
                <?= $pageAction ?? '' ?>
            </div>
            <?php endif; ?>
