<?php
/**
 * Bilet-Geç Firma Paneli - Etkinlik Yönetimi
 */

define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/activity_log.php';

start_secure_session();
require_panel();
ensure_user_schema($pdo);

global $pdo;

$user_id = get_current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjaxGlobal = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        if ($isAjaxGlobal) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'errors' => ['Güvenlik doğrulaması başarısız.']]);
            exit;
        }
        set_flash('error', 'Güvenlik doğrulaması başarısız.');
        header('Location: ' . base_url('admin/manage_events.php'));
        exit;
    }

    $action = $_POST['action'] ?? '';


    if ($action === 'change_status') {
        $event_id = (int)($_POST['event_id'] ?? 0);
        $new_status = $_POST['new_status'] ?? '';
        $allowed = ['active', 'cancelled', 'completed'];

        if ($event_id <= 0 || !in_array($new_status, $allowed, true)) {
            set_flash('error', 'Geçersiz parametre.');
            header('Location: ' . base_url('admin/manage_events.php'));
            exit;
        }

        try {
            $scopeSql = is_superadmin()
                ? 'SELECT id, title, created_by, event_date FROM events WHERE id = ?'
                : 'SELECT id, title, created_by, event_date FROM events WHERE id = ? AND created_by = ?';
            $scopeParams = is_superadmin() ? [$event_id] : [$event_id, $user_id];

            $stmt = $pdo->prepare($scopeSql);
            $stmt->execute($scopeParams);
            $ev = $stmt->fetch();
            if (!$ev) {
                set_flash('error', 'Etkinlik bulunamadı veya yetkiniz yok.');
                header('Location: ' . base_url('admin/manage_events.php'));
                exit;
            }

            if ($new_status === 'cancelled') {
                $stmt = $pdo->prepare("UPDATE reservations SET status = 'cancelled' WHERE event_id = ? AND status = 'pending'");
                $stmt->execute([$event_id]);
                
                if ($ev['event_date'] && strtotime($ev['event_date']) > time()) {
                    // Tarih geçmemişse biletleri iptal et ve iade (refund) oluştur
                    $stmt = $pdo->prepare("UPDATE tickets SET status = 'cancelled' WHERE event_id = ? AND status = 'active'");
                    $stmt->execute([$event_id]);
                    
                    $stmt = $pdo->prepare(
                        "INSERT INTO refunds (ticket_id, user_id, event_id, original_amount, refund_amount, reason, status, requested_at, created_at, updated_at) 
                         SELECT id, user_id, event_id, total_price, total_price, 'Etkinlik iptal edildi', 'pending', NOW(), NOW(), NOW() 
                         FROM tickets 
                         WHERE event_id = ? AND status = 'cancelled' AND id NOT IN (SELECT ticket_id FROM refunds)"
                    );
                    $stmt->execute([$event_id]);
                }
            }

            $updateSql = is_superadmin()
                ? 'UPDATE events SET status = ? WHERE id = ?'
                : 'UPDATE events SET status = ? WHERE id = ? AND created_by = ?';
            $updateParams = is_superadmin()
                ? [$new_status, $event_id]
                : [$new_status, $event_id, $user_id];

            $stmt = $pdo->prepare($updateSql);
            $stmt->execute($updateParams);

            $labels = ['active' => 'aktif', 'cancelled' => 'iptal edildi', 'completed' => 'tamamlandı'];
            log_activity($pdo, $user_id, 'event_status', 'event', $event_id, ['new_status' => $new_status, 'title' => $ev['title']]);
            set_flash('success', '"' . $ev['title'] . '" etkinliğinin durumu "' . ($labels[$new_status] ?? $new_status) . '" olarak güncellendi.');
        } catch (PDOException $e) {
            error_log('manage_events change_status hata: ' . $e->getMessage());
            set_flash('error', 'Durum değiştirilemedi.');
        }

        header('Location: ' . base_url('admin/manage_events.php'));
        exit;
    }

    if ($action === 'delete') {
        $event_id = (int)($_POST['event_id'] ?? 0);
        if ($event_id <= 0) {
            set_flash('error', 'Geçersiz etkinlik ID.');
            header('Location: ' . base_url('admin/manage_events.php'));
            exit;
        }

        try {
            $scopeSql = is_superadmin()
                ? 'SELECT id, title FROM events WHERE id = ?'
                : 'SELECT id, title FROM events WHERE id = ? AND created_by = ?';
            $scopeParams = is_superadmin() ? [$event_id] : [$event_id, $user_id];

            $stmt = $pdo->prepare($scopeSql);
            $stmt->execute($scopeParams);
            $ev = $stmt->fetch();
            if (!$ev) {
                set_flash('error', 'Etkinlik bulunamadı veya yetkiniz yok.');
                header('Location: ' . base_url('admin/manage_events.php'));
                exit;
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('DELETE FROM tickets WHERE event_id = ?');
            $stmt->execute([$event_id]);
            $stmt = $pdo->prepare('DELETE FROM reservations WHERE event_id = ?');
            $stmt->execute([$event_id]);

            if (is_superadmin()) {
                $stmt = $pdo->prepare('DELETE FROM events WHERE id = ?');
                $stmt->execute([$event_id]);
            } else {
                $stmt = $pdo->prepare('DELETE FROM events WHERE id = ? AND created_by = ?');
                $stmt->execute([$event_id, $user_id]);
            }
            $pdo->commit();

            log_activity($pdo, $user_id, 'event_delete', 'event', $event_id, ['title' => $ev['title']]);

            set_flash('success', '"' . $ev['title'] . '" etkinliği başarıyla silindi.');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('manage_events delete hata: ' . $e->getMessage());
            set_flash('error', 'Etkinlik silinirken hata oluştu.');
        }

        header('Location: ' . base_url('admin/manage_events.php'));
        exit;
    }

    set_flash('error', 'Geçersiz işlem.');
    header('Location: ' . base_url('admin/manage_events.php'));
    exit;
}

try {
    if (is_superadmin()) {
        $stmt = $pdo->query(
            "SELECT e.*, c.name AS category_name, COALESCE((SELECT SUM(t.quantity) FROM tickets t WHERE t.event_id = e.id), 0) AS sold_count
             FROM events e
             LEFT JOIN categories c ON e.category_id = c.id
             ORDER BY e.created_at DESC"
        );
    } else {
        $stmt = $pdo->prepare(
            "SELECT e.*, c.name AS category_name, COALESCE((SELECT SUM(t.quantity) FROM tickets t WHERE t.event_id = e.id), 0) AS sold_count
             FROM events e
             LEFT JOIN categories c ON e.category_id = c.id
             WHERE e.created_by = ?
             ORDER BY e.created_at DESC"
        );
        $stmt->execute([$user_id]);
    }
    $events = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('manage_events fetch hata: ' . $e->getMessage());
    $events = [];
}

$csrf_token = generate_csrf_token();
$flash = get_flash();

$page_title = 'Etkinlik Yönetimi';
$current_page = 'manage_events';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/_sidebar.php';
?>

<div class="admin-content">
<div class="admin-header">
    <h1 class="page-title">
        <i class="fas fa-tasks"></i> Etkinlik Yönetimi
    </h1>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'error'; ?>">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
        <span><?php echo e($flash['message']); ?></span>
    </div>
<?php endif; ?>

<div class="admin-toolbar">
    <button type="button" class="btn btn-primary" data-modal-open="add-event-modal">
        <i class="fas fa-plus-circle"></i> Yeni Etkinlik Ekle
    </button>
    <input type="text" id="event-search" class="form-input glass-input" placeholder="Etkinlik adı ara...">
</div>

<div class="glass-card events-container">
    <?php if (empty($events)): ?>
        <div class="empty-state">
            <i class="fas fa-calendar-times"></i>
            <p>Henüz etkinlik eklenmemiş.</p>
            <button type="button" class="btn btn-primary" data-modal-open="add-event-modal">
                <i class="fas fa-plus-circle"></i> İlk Etkinliği Ekle
            </button>
        </div>
    <?php else: ?>
        <div class="events-list" data-searchable="true">
            <?php foreach ($events as $event): ?>
                <div class="event-list-item" data-search-text="<?php echo strtolower(e($event['title'] . ' ' . ($event['category_name'] ?? '') . ' ' . ($event['venue'] ?? ''))); ?>">
                    <div class="event-thumb">
                        <img src="<?php echo e(resolve_url($event['image_url'])); ?>" alt="<?php echo e($event['title']); ?>" onerror="this.src='https://placehold.co/160x90/1a1a2e/7c3aed?text=Görsel'">
                    </div>
                    <div class="event-main">
                        <h3 class="event-title"><?php echo e($event['title']); ?></h3>
                        <div class="event-meta">
                            <span class="meta-cat"><?php echo e($event['category_name'] ?? 'Kategori Yok'); ?></span>
                            • <span class="meta-date"><?php echo format_date($event['event_date'], 'd.m.Y H:i'); ?></span>
                            • <span class="meta-venue"><?php echo e($event['venue'] . ', ' . $event['city']); ?></span>
                        </div>
                        <div class="event-stats">
                            <strong><?php echo format_price($event['price']); ?></strong>
                            &nbsp;•&nbsp; Kapasite: <?php echo (int)$event['total_capacity']; ?>
                            &nbsp;•&nbsp; Satılan: <?php echo (int)$event['sold_count']; ?>
                        </div>
                    </div>
                    <div class="event-actions">
                        <div class="event-status"><?php echo status_badge($event['status']); ?></div>
                        <div class="event-buttons">
                            <a href="<?php echo base_url('admin/edit_event.php?id=' . (int)$event['id']); ?>" class="btn btn-sm btn-gray" title="Düzenle"><i class="fas fa-edit"></i> Düzenle</a>
                            <form method="POST" class="inline-form" style="display:inline-block;">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                <input type="hidden" name="action" value="change_status">
                                <input type="hidden" name="event_id" value="<?php echo (int)$event['id']; ?>">
                                <input type="hidden" name="new_status" value="completed">
                                <button type="submit" class="btn btn-sm btn-yellow" title="Tamamlandı olarak işaretle"><i class="fas fa-check"></i> Bitti</button>
                            </form>
                            <form method="POST" action="<?php echo base_url('admin/manage_events.php'); ?>" class="inline-form" style="display:inline-block;">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="event_id" value="<?php echo (int)$event['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger" title="Sil" data-confirm="Bu etkinliği silmek istediğinize emin misiniz?"><i class="fas fa-trash-alt"></i> Sil</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_add_event_modal.php'; ?>
</div>

<?php require_once __DIR__ . '/_sidebar_footer.php'; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
