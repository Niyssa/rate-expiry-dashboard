<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Rate Expiry Dashboard') ?></title>
    <link rel="stylesheet" href="/rate-expiry-dashboard/assets/css/style.css">
</head>
<body>
<?php
$_currentScript = basename($_SERVER['PHP_SELF']);
$_alertCount = getDashboardAlertCount();
$_navLinks = [
    ['href' => '/rate-expiry-dashboard/index.php',                'label' => 'Dashboard',     'file' => 'index.php',        'badge' => 0],
    ['href' => '/rate-expiry-dashboard/pages/rates.php',          'label' => 'Rates',         'file' => 'rates.php',        'badge' => 0],
    ['href' => '/rate-expiry-dashboard/pages/notifications.php',  'label' => 'Notifications', 'file' => 'notifications.php','badge' => $_alertCount],
    ['href' => '/rate-expiry-dashboard/pages/upload.php',         'label' => 'Upload',        'file' => 'upload.php',       'badge' => 0],
];
?>
<nav class="navbar">
    <div class="navbar-brand">&#128200; Rate Expiry Dashboard</div>
    <ul class="navbar-links">
        <?php foreach ($_navLinks as $link): ?>
        <li>
            <a href="<?= $link['href'] ?>" class="<?= $_currentScript === $link['file'] ? 'active' : '' ?>" style="position:relative">
                <?= $link['label'] ?>
                <?php if ($link['badge'] > 0): ?>
                <span class="nav-badge"><?= $link['badge'] ?></span>
                <?php endif; ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
</nav>
<main class="container">
