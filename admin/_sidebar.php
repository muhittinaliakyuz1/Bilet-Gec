<?php
/**
 * Firma Paneli - Ortak sidebar layout başlangıcı
 * Bu dosya include edildikten sonra admin-content div'i açılır.
 */
if (!defined('ALLOWED_ACCESS')) {
    die('Doğrudan erişim yasaktır.');
}

$panel_label = is_superadmin() ? 'Admin Paneli' : 'Firma Paneli';
$nav_items = [
    ['page' => 'admin',           'href' => base_url('admin/'),                 'icon' => '🏠', 'label' => 'Dashboard'],
    ['page' => 'manage_events',   'href' => base_url('admin/manage_events.php'), 'icon' => '📅', 'label' => 'Etkinlikler'],
    ['page' => 'manage_refunds',  'href' => base_url('admin/manage_refunds.php'), 'icon' => '💰', 'label' => 'İadeler'],
];

if (is_superadmin()) {
    $nav_items[] = ['page' => 'manage_users',   'href' => base_url('admin/manage_users.php'),   'icon' => '👥', 'label' => 'Kullanıcılar'];
    $nav_items[] = ['page' => 'activity_logs',  'href' => base_url('admin/activity_logs.php'),  'icon' => '📋', 'label' => 'Aktivite Logları'];
}

$current = $current_page ?? '';
?>
<div class="admin-layout">
    <button class="admin-sidebar-toggle" id="adminSidebarToggle">
        <span class="nav-icon">☰</span> Menü
    </button>
    <aside class="admin-sidebar" id="adminSidebar">
    <div class="admin-sidebar-brand">
        <span class="logo-emoji">🎫</span>
        <strong><?php echo e($panel_label); ?></strong>
    </div>

    <div class="admin-sidebar-title">Menü</div>
    <?php foreach ($nav_items as $item): ?>
        <a href="<?php echo e($item['href']); ?>" class="admin-nav-link <?php echo $current === $item['page'] ? 'active' : ''; ?>">
            <span class="nav-icon"><?php echo $item['icon']; ?></span>
            <?php echo e($item['label']); ?>
        </a>
    <?php endforeach; ?>

    <div class="admin-sidebar-title">Hesap</div>
    <a href="<?php echo base_url('index.php'); ?>" class="admin-nav-link">
        <span class="nav-icon">🌐</span> Siteye Dön
    </a>
    <a href="<?php echo base_url('auth/logout.php'); ?>" class="admin-nav-link" style="color: var(--danger);">
        <span class="nav-icon">🚪</span> Çıkış Yap
    </a>
</aside>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggle = document.getElementById('adminSidebarToggle');
    const sidebar = document.getElementById('adminSidebar');
    if (toggle && sidebar) {
        toggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
    }
});
</script>
