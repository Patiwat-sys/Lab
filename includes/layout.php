<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function renderHeader(string $title, string $active = ''): void
{
    $user = currentUser();
    $isAdmin = $user && $user['role'] === 'admin';
    $dateText = date('D, d M Y');
    $timeText = date('H:i');

    $moduleLabel = [
        'dashboard' => 'Overview',
        'coal' => 'Coal Laboratory',
        'limestone' => 'Limestone Laboratory',
        'gas' => 'Gas Management',
        'links' => 'Reference Center',
        'admin' => 'Data Management',
        'members' => 'Access Control',
        'logs' => 'Audit Trail',
    ][$active] ?? 'Laboratory Operations';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/app.css?v=106">
    <?php if ($active === 'gas'): ?>
    <link rel="stylesheet" href="css/gas-form.css?v=19">
    <?php endif; ?>
</head>
<body>
<header class="mobile-app-header" id="mobileAppHeader">
    <button type="button" class="mobile-app-header__menu menu-toggle" id="menuToggle" aria-label="Open menu" aria-expanded="false">
        <span class="mobile-app-header__menu-icon" aria-hidden="true"></span>
    </button>
    <div class="mobile-app-header__brand">
        <span class="mobile-app-header__logo" aria-hidden="true">⚗</span>
        <span class="mobile-app-header__title">Coal Laboratory</span>
    </div>
    <div class="mobile-app-header__actions">
        <?php if ($user): ?>
            <a class="mobile-app-header__logout" href="logout.php">Logout</a>
        <?php else: ?>
            <a class="mobile-app-header__logout" href="login.php">Login</a>
        <?php endif; ?>
    </div>
</header>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-head">
            <button type="button" class="sidebar-close" id="sidebarClose" aria-label="Close menu">&times;</button>
            <a class="brand" href="dashboard.php">Coal Laboratory</a>
            <p class="brand-subtitle">Hongsa Power Company Limited</p>
        </div>

        <nav class="sidebar-nav">
            <a class="<?= $active === 'dashboard' ? 'active' : '' ?>" href="dashboard.php">Dashboard</a>
            <a class="<?= $active === 'coal' ? 'active' : '' ?>" href="coal_dashboard.php">Coal</a>
            <a class="<?= $active === 'limestone' ? 'active' : '' ?>" href="limestone_dashboard.php">Limestone</a>
            <?php if ($isAdmin): ?>
                <a class="<?= $active === 'gas' ? 'active' : '' ?>" href="gas_consumption.php">Gas Consumption</a>
                <a class="<?= $active === 'admin' ? 'active' : '' ?>" href="admin.php">Data Management</a>
                <a class="<?= $active === 'members' ? 'active' : '' ?>" href="members.php">Members</a>
                <a class="<?= $active === 'logs' ? 'active' : '' ?>" href="activity_logs.php">Logs</a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-foot">
            <?php if ($user): ?>
                <div class="user-badge">
                    <strong><?= h($user['username']) ?></strong>
                    <span><?= h($user['role']) ?></span>
                </div>
                <a class="btn alt" href="logout.php">Logout</a>
            <?php else: ?>
                <a class="btn" href="login.php">Login</a>
            <?php endif; ?>
        </div>
    </aside>

    <div class="content-shell">
        <header class="content-topbar">
            <div class="content-topbar-main">
                <p class="eyebrow"><?= h($moduleLabel) ?></p>
                <h1><?= h($title) ?></h1>
                <p class="topbar-subtitle">Real-time quality operations, historical tracking, and member-level control.</p>
            </div>
            <div class="content-topbar-meta">
                <div class="meta-card">
                    <span>Date</span>
                    <strong><?= h($dateText) ?></strong>
                </div>
                <div class="meta-card">
                    <span>Time</span>
                    <strong><?= h($timeText) ?></strong>
                </div>
                <div class="meta-card">
                    <span>User</span>
                    <strong><?= $user ? h($user['username']) : 'Guest' ?></strong>
                </div>
            </div>
        </header>
        <main class="page">
    <?php
}

function renderFooter(): void
{
    ?>
        </main>
    </div>
 </div>

<script>
(function () {
    var body = document.body;
    var toggle = document.getElementById('menuToggle');
    var closeBtn = document.getElementById('sidebarClose');
    var backdrop = document.getElementById('sidebarBackdrop');

    function closeMenu() {
        body.classList.remove('sidebar-open');
        if (toggle) {
            toggle.setAttribute('aria-label', 'Open menu');
            toggle.setAttribute('aria-expanded', 'false');
        }
    }

    function openMenu() {
        body.classList.add('sidebar-open');
        if (toggle) {
            toggle.setAttribute('aria-label', 'Close menu');
            toggle.setAttribute('aria-expanded', 'true');
        }
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            if (body.classList.contains('sidebar-open')) {
                closeMenu();
            } else {
                openMenu();
            }
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', closeMenu);
    }

    if (backdrop) {
        backdrop.addEventListener('click', closeMenu);
    }

    window.addEventListener('resize', function () {
        if (window.innerWidth > 1100) {
            closeMenu();
        }
    });
})();
</script>
</body>
</html>
    <?php
}
