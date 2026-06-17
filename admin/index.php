<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/refund_functions.php';
require_once __DIR__ . '/../includes/activity_log.php';

start_secure_session();
require_panel();
ensure_user_schema($pdo);

global $pdo;

$user_id = get_current_user_id();
$firma_filter = is_superadmin() ? null : $user_id;

$event_count_sql = is_superadmin()
    ? 'SELECT COUNT(*) FROM events'
    : 'SELECT COUNT(*) FROM events WHERE created_by = ?';
$event_stmt = is_superadmin()
    ? $pdo->query($event_count_sql)
    : $pdo->prepare($event_count_sql);
if (!is_superadmin()) {
    $event_stmt->execute([$user_id]);
}
$event_count = (int)$event_stmt->fetchColumn();

$stats = get_refund_statistics($pdo, $firma_filter);
$pending_refunds = (int)($stats['pending']['count'] ?? 0);

$sales_sql = is_superadmin()
    ? "SELECT SUM(quantity) as total_tickets, SUM(total_price) as total_revenue FROM tickets WHERE status = 'active'"
    : "SELECT SUM(t.quantity) as total_tickets, SUM(t.total_price) as total_revenue FROM tickets t JOIN events e ON t.event_id = e.id WHERE t.status = 'active' AND e.created_by = ?";
$sales_stmt = $pdo->prepare($sales_sql);
if (!is_superadmin()) {
    $sales_stmt->execute([$user_id]);
} else {
    $sales_stmt->execute();
}
$sales_stats = $sales_stmt->fetch();
$total_tickets = (int)$sales_stats['total_tickets'];
$total_revenue = (float)$sales_stats['total_revenue'];

$recent_logs = [];
if (is_superadmin()) {
    $recent_logs = get_activity_logs($pdo, '', 8, 0);
}

$page_title = is_superadmin() ? 'Admin Paneli' : 'Firma Paneli';
$current_page = 'admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/_sidebar.php';
?>

<div class="admin-content">
    <div class="admin-header">
        <h1>👋 Hoş Geldiniz, <?php echo e($_SESSION['full_name'] ?? ''); ?></h1>
        <p><?php echo is_superadmin() ? 'Tüm firmaları ve kullanıcıları yönetin' : 'Etkinliklerinizi ve iadelerinizi yönetin'; ?></p>
    </div>

    <div class="dashboard-stats">
        <div class="stat-card stat-card-blue">
            <div class="stat-icon">📅</div>
            <div class="stat-info">
                <div class="stat-value"><?php echo $event_count; ?></div>
                <div class="stat-label">Etkinlik</div>
            </div>
        </div>
        <div class="stat-card stat-card-yellow">
            <div class="stat-icon">⏳</div>
            <div class="stat-info">
                <div class="stat-value"><?php echo $pending_refunds; ?></div>
                <div class="stat-label">Bekleyen İade</div>
            </div>
        </div>
        <div class="stat-card stat-card-green">
            <div class="stat-icon">💰</div>
            <div class="stat-info">
                <div class="stat-value">₺<?php echo number_format($total_revenue, 2, ',', '.'); ?></div>
                <div class="stat-label">Toplam Ciro (<?php echo $total_tickets; ?> Bilet)</div>
            </div>
        </div>
        <?php if (is_superadmin()): ?>
        <div class="stat-card" style="background: rgba(139, 92, 246, 0.1); border-left: 4px solid #8b5cf6;">
            <div class="stat-icon" style="color: #8b5cf6;">👥</div>
            <div class="stat-info">
                <?php
                $user_stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'");
                $user_count = (int)$user_stmt->fetchColumn();
                $firma_stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'firma'");
                $firma_count = (int)$firma_stmt->fetchColumn();
                ?>
                <div class="stat-value" style="color: #8b5cf6;"><?php echo $user_count; ?></div>
                <div class="stat-label" style="color: rgba(255,255,255,0.7);">Kullanıcı / <?php echo $firma_count; ?> Firma</div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="admin-quick-links">
        <a href="<?php echo base_url('admin/manage_events.php'); ?>" class="admin-btn">
            <span class="btn-icon">📅</span>
            <span class="btn-title">Etkinlikleri Yönet</span>
            <span class="btn-desc">Oluştur, düzenle ve yönet</span>
        </a>
        <a href="<?php echo base_url('admin/manage_refunds.php'); ?>" class="admin-btn">
            <span class="btn-icon">💰</span>
            <span class="btn-title">İadeleri Yönet</span>
            <span class="btn-desc"><?php echo $pending_refunds; ?> bekleyen talep</span>
        </a>
        <?php if (is_superadmin()): ?>
        <a href="<?php echo base_url('admin/manage_users.php'); ?>" class="admin-btn">
            <span class="btn-icon">👥</span>
            <span class="btn-title">Kullanıcıları Yönet</span>
            <span class="btn-desc">Rol atama ve kontrol</span>
        </a>
        <a href="<?php echo base_url('admin/activity_logs.php'); ?>" class="admin-btn">
            <span class="btn-icon">📋</span>
            <span class="btn-title">Aktivite Logları</span>
            <span class="btn-desc">Tüm sistem kayıtları</span>
        </a>
        <?php endif; ?>
    </div>

    <?php if (is_superadmin() && !empty($recent_logs)): ?>
    <div class="glass-card" style="margin-top: 24px; padding: 20px;">
        <h3 style="margin: 0 0 16px;">Son Aktiviteler</h3>
        <div class="activity-list">
            <?php foreach ($recent_logs as $log): ?>
                <div class="activity-list-item">
                    <span class="activity-action"><?php echo e(activity_action_label($log['action'])); ?></span>
                    <span class="activity-actor"><?php echo e($log['actor_name']); ?></span>
                    <span class="activity-time"><?php echo date('d.m.Y H:i', strtotime($log['created_at'])); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <a href="<?php echo base_url('admin/activity_logs.php'); ?>" class="btn btn-sm btn-secondary" style="margin-top: 12px;">Tüm Logları Gör</a>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_sidebar_footer.php'; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
