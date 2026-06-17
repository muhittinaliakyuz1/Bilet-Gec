<?php if(!defined('ALLOWED_ACCESS')) die('Doğrudan erişim yasak'); ?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <?php if (function_exists('generate_csrf_token')): ?>
        <meta name="csrf-token" content="<?= htmlspecialchars(generate_csrf_token()) ?>">
    <?php endif; ?>
    <title><?= htmlspecialchars($page_title ?? 'Bilet-Geç') ?> | Bilet-Geç</title>
    <meta name="description" content="<?= htmlspecialchars($page_description ?? 'Bilet-Geç ile konser, tiyatro, spor ve daha fazla etkinlik için kolayca bilet alın.') ?>">
    <meta name="theme-color" content="#0a0a1a">

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Main Stylesheet -->
    <link rel="stylesheet" href="<?= defined('BASE_URL') ? BASE_URL : '/ilterhoca/' ?>assets/css/common.css?v=<?= time() ?>">
    <?php
        $page_css = '';
        if (!empty($current_page)) {
            $page_css = preg_replace('/[^a-z0-9_-]/', '', strtolower($current_page));
        }
        $page_css_file = __DIR__ . '/../assets/css/pages/' . $page_css . '.css';
    ?>
    <?php if ($page_css && file_exists($page_css_file)): ?>
        <link rel="stylesheet" href="<?= (defined('BASE_URL') ? BASE_URL : '/ilterhoca/') ?>assets/css/pages/<?= htmlspecialchars($page_css) ?>.css?v=<?= time() ?>">
    <?php endif; ?>
</head>
<?php
$admin_panel_pages = ['admin', 'manage_events', 'manage_refunds', 'manage_users', 'activity_logs'];
$is_admin_panel = in_array($current_page ?? '', $admin_panel_pages, true);
?>
<body class="<?= $is_admin_panel ? 'admin-panel' : '' ?>" data-page="<?= htmlspecialchars($current_page ?? 'home') ?>" data-base-url="<?= htmlspecialchars(defined('BASE_URL') ? BASE_URL : '/ilterhoca/') ?>">

<!-- Navigation -->
<nav class="navbar">
    <div class="container">
        <!-- Logo -->
        <a href="/ilterhoca/" class="navbar-logo">
            <span class="logo-emoji">🎫</span>
            Bilet-Geç
        </a>

        <!-- Mobile Hamburger Toggle (CSS Only) -->
        <input type="checkbox" id="nav-toggle" class="nav-toggle hidden" aria-label="Menüyü aç/kapat">
        <label for="nav-toggle" class="nav-toggle-label" aria-label="Menü">
            <span></span>
        </label>

        <!-- Navigation Links -->
        <div class="navbar-nav">
            <?php if (function_exists('is_panel_user') && is_panel_user()): ?>
                <?php $panel_nav_label = is_superadmin() ? 'Admin Paneli' : 'Firma Paneli';
                      $top_nav_events_url = '/ilterhoca/events.php'; ?>
                <a href="/ilterhoca/admin/" class="nav-link <?= ($current_page ?? '') === 'admin' ? 'active' : '' ?>">
                    ⚙️ <?= htmlspecialchars($panel_nav_label) ?>
                </a>
                <a href="<?= $top_nav_events_url ?>" class="nav-link <?= ($current_page ?? '') === 'events' ? 'active' : '' ?>">
                    📅 Etkinlikler
                </a>
                <div class="nav-actions">
                    <div class="user-menu">
                        <button class="user-menu-trigger" type="button">
                            <span class="user-avatar">
                                <?= mb_strtoupper(mb_substr($_SESSION['full_name'] ?? 'A', 0, 1, 'UTF-8'), 'UTF-8') ?>
                            </span>
                            <span><?= htmlspecialchars($_SESSION['full_name'] ?? 'Kullanıcı') ?></span>
                            <span style="font-size:0.6rem;">▼</span>
                        </button>
                        <div class="user-dropdown">
                            <a href="/ilterhoca/admin/">⚙️ <?= htmlspecialchars($panel_nav_label) ?></a>
                            <div class="dropdown-divider"></div>
                            <a href="/ilterhoca/auth/logout.php" style="color: var(--danger);">🚪 Çıkış Yap</a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Normal Kullanıcı -->
                <a href="/ilterhoca/" class="nav-link <?= ($current_page ?? '') === 'home' ? 'active' : '' ?>">Ana Sayfa</a>
                <a href="/ilterhoca/events.php" class="nav-link <?= ($current_page ?? '') === 'events' ? 'active' : '' ?>">Etkinlikler</a>

                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="/ilterhoca/my_tickets.php" class="nav-link <?= ($current_page ?? '') === 'my_tickets' ? 'active' : '' ?>">Biletlerim</a>
                    <div class="nav-actions">
                        <div class="user-menu">
                            <button class="user-menu-trigger" type="button">
                                <span class="user-avatar">
                                    <?= mb_strtoupper(mb_substr($_SESSION['full_name'] ?? 'U', 0, 1, 'UTF-8'), 'UTF-8') ?>
                                </span>
                                <span><?= htmlspecialchars($_SESSION['full_name'] ?? 'Kullanıcı') ?></span>
                                <span style="font-size:0.6rem;">▼</span>
                            </button>
                            <div class="user-dropdown">
                                <a href="/ilterhoca/profile.php">👤 Profilim</a>
                                <a href="/ilterhoca/my_tickets.php">🎫 Biletlerim</a>
                                <div class="dropdown-divider"></div>
                                <a href="/ilterhoca/auth/logout.php" style="color: var(--danger);">🚪 Çıkış Yap</a>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="nav-actions">
                        <a href="/ilterhoca/auth/login.php" class="btn btn-sm btn-secondary">Giriş Yap</a>
                        <a href="/ilterhoca/auth/register.php" class="btn btn-sm btn-primary">Kayıt Ol</a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</nav>

<!-- Main Content Start -->
<main>
