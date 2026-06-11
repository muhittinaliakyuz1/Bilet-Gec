<?php
/**
 * Bilet-Geç Admin Panel - Dashboard
 */

define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

start_secure_session();
require_admin();

// Fetch dashboard statistics
global $pdo;

// Toplam Etkinlik
$stmt = $pdo->prepare("SELECT COUNT(*) FROM events");
$stmt->execute();
$total_events = (int)$stmt->fetchColumn();

// Aktif Etkinlik
$stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE status = ?");
$stmt->execute(['active']);
$active_events = (int)$stmt->fetchColumn();

// Toplam Kullanıcı
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users");
$stmt->execute();
$total_users = (int)$stmt->fetchColumn();

// Satılan Bilet (toplam adet)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM tickets");
$stmt->execute();
$total_tickets_sold = (int)$stmt->fetchColumn();

// Toplam Gelir
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_price), 0) FROM tickets");
$stmt->execute();
$total_revenue = (float)$stmt->fetchColumn();

// Son 20 bilet satışı
$stmt = $pdo->prepare("SELECT t.ticket_code, t.quantity, t.total_price, t.purchased_at, e.title AS event_title, u.full_name AS user_name FROM tickets t JOIN events e ON t.event_id = e.id JOIN users u ON t.user_id = u.id ORDER BY t.purchased_at DESC LIMIT 20");
$stmt->execute();
$recent_sales = $stmt->fetchAll();

$page_title = 'Admin Panel';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-header">
    <h1 class="page-title">
        <i class="fas fa-tachometer-alt"></i> Admin Panel
    </h1>
    <p class="page-subtitle">Bilet-Geç yönetim paneline hoş geldiniz.</p>
</div>

<!-- Stats Cards -->
<div class="stats-grid">
    <div class="stat-card glass-card">
        <div class="stat-icon stat-icon-purple">
            <i class="fas fa-calendar-alt"></i>
        </div>
        <div class="stat-info">
            <span class="stat-value"><?php echo $total_events; ?></span>
            <span class="stat-label">Toplam Etkinlik</span>
        </div>
    </div>

    <div class="stat-card glass-card">
        <div class="stat-icon stat-icon-green">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-info">
            <span class="stat-value"><?php echo $active_events; ?></span>
            <span class="stat-label">Aktif Etkinlik</span>
        </div>
    </div>

    <div class="stat-card glass-card">
        <div class="stat-icon stat-icon-cyan">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-info">
            <span class="stat-value"><?php echo $total_users; ?></span>
            <span class="stat-label">Toplam Kullanıcı</span>
        </div>
    </div>

    <div class="stat-card glass-card">
        <div class="stat-icon stat-icon-orange">
            <i class="fas fa-ticket-alt"></i>
        </div>
        <div class="stat-info">
            <span class="stat-value"><?php echo $total_tickets_sold; ?></span>
            <span class="stat-label">Satılan Bilet</span>
        </div>
    </div>

    <div class="stat-card glass-card">
        <div class="stat-icon stat-icon-gold">
            <i class="fas fa-coins"></i>
        </div>
        <div class="stat-info">
            <span class="stat-value"><?php echo format_price($total_revenue); ?></span>
            <span class="stat-label">Toplam Gelir</span>
        </div>
    </div>
</div>

<!-- Quick Links -->
<div class="quick-links">
    <a href="<?php echo base_url('admin/add_event.php'); ?>" class="quick-link-card glass-card">
        <i class="fas fa-plus-circle"></i>
        <span>Etkinlik Ekle</span>
    </a>
    <a href="<?php echo base_url('admin/manage_events.php'); ?>" class="quick-link-card glass-card">
        <i class="fas fa-tasks"></i>
        <span>Etkinlikleri Yönet</span>
    </a>
</div>

<!-- Recent Sales Table -->
<div class="admin-section">
    <h2 class="section-title">
        <i class="fas fa-receipt"></i> Son Satışlar
    </h2>

    <div class="glass-card table-container">
        <?php if (empty($recent_sales)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p>Henüz bilet satışı bulunmuyor.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Bilet Kodu</th>
                            <th>Etkinlik</th>
                            <th>Kullanıcı</th>
                            <th>Adet</th>
                            <th>Tutar</th>
                            <th>Tarih</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_sales as $sale): ?>
                            <tr>
                                <td>
                                    <span class="ticket-code"><?php echo e($sale['ticket_code']); ?></span>
                                </td>
                                <td><?php echo e($sale['event_title']); ?></td>
                                <td><?php echo e($sale['user_name']); ?></td>
                                <td class="text-center"><?php echo (int)$sale['quantity']; ?></td>
                                <td class="text-right"><?php echo format_price($sale['total_price']); ?></td>
                                <td><?php echo format_date($sale['purchased_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
