<?php
/**
 * Bilet-Geç Admin - Etkinlik Yönetimi
 */

define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

start_secure_session();
require_admin();

global $pdo;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF verification
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        set_flash('error', 'Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.');
        header('Location: ' . base_url('admin/manage_events.php'));
        exit;
    }

    $action = $_POST['action'] ?? '';

    // Change event status
    if ($action === 'change_status') {
        $event_id = (int)($_POST['event_id'] ?? 0);
        $new_status = $_POST['new_status'] ?? '';

        $allowed_statuses = ['active', 'cancelled', 'completed'];

        if ($event_id <= 0 || !in_array($new_status, $allowed_statuses, true)) {
            set_flash('error', 'Geçersiz istek parametreleri.');
            header('Location: ' . base_url('admin/manage_events.php'));
            exit;
        }

        try {
            // Verify event exists
            $stmt = $pdo->prepare("SELECT id, title FROM events WHERE id = ?");
            $stmt->execute([$event_id]);
            $event = $stmt->fetch();

            if (!$event) {
                set_flash('error', 'Etkinlik bulunamadı.');
                header('Location: ' . base_url('admin/manage_events.php'));
                exit;
            }

            // If cancelling, also cancel all pending reservations
            if ($new_status === 'cancelled') {
                $stmt = $pdo->prepare("
                    UPDATE reservations SET status = 'cancelled' 
                    WHERE event_id = ? AND status = 'pending'
                ");
                $stmt->execute([$event_id]);
            }

            $stmt = $pdo->prepare("UPDATE events SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $event_id]);

            $status_labels = [
                'active'    => 'aktif',
                'cancelled' => 'iptal edildi',
                'completed' => 'tamamlandı',
            ];

            set_flash('success', '"' . $event['title'] . '" etkinliğinin durumu "' . $status_labels[$new_status] . '" olarak güncellendi.');

        } catch (PDOException $e) {
            error_log('Durum güncelleme hatası: ' . $e->getMessage());
            set_flash('error', 'Durum güncellenirken bir hata oluştu.');
        }

        header('Location: ' . base_url('admin/manage_events.php'));
        exit;
    }

    // Delete event
    if ($action === 'delete') {
        $event_id = (int)($_POST['event_id'] ?? 0);

        if ($event_id <= 0) {
            set_flash('error', 'Geçersiz etkinlik ID.');
            header('Location: ' . base_url('admin/manage_events.php'));
            exit;
        }

        try {
            // Verify event exists
            $stmt = $pdo->prepare("SELECT id, title FROM events WHERE id = ?");
            $stmt->execute([$event_id]);
            $event = $stmt->fetch();

            if (!$event) {
                set_flash('error', 'Etkinlik bulunamadı.');
                header('Location: ' . base_url('admin/manage_events.php'));
                exit;
            }

            $pdo->beginTransaction();

            // Delete tickets related to this event
            $stmt = $pdo->prepare("DELETE FROM tickets WHERE event_id = ?");
            $stmt->execute([$event_id]);

            // Delete reservations related to this event
            $stmt = $pdo->prepare("DELETE FROM reservations WHERE event_id = ?");
            $stmt->execute([$event_id]);

            // Delete the event
            $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
            $stmt->execute([$event_id]);

            $pdo->commit();

            set_flash('success', '"' . $event['title'] . '" etkinliği ve ilgili tüm kayıtlar başarıyla silindi.');

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Etkinlik silme hatası: ' . $e->getMessage());
            set_flash('error', 'Etkinlik silinirken bir hata oluştu.');
        }

        header('Location: ' . base_url('admin/manage_events.php'));
        exit;
    }

    // Unknown action
    set_flash('error', 'Geçersiz işlem.');
    header('Location: ' . base_url('admin/manage_events.php'));
    exit;
}

// Fetch all events with category names and sold counts
$stmt = $pdo->query("
    SELECT 
        e.*,
        c.name AS category_name,
        COALESCE((SELECT SUM(t.quantity) FROM tickets t WHERE t.event_id = e.id), 0) AS sold_count
    FROM events e
    LEFT JOIN categories c ON e.category_id = c.id
    ORDER BY e.created_at DESC
");
$events = $stmt->fetchAll();

$csrf_token = generate_csrf_token();
$flash = get_flash();

$page_title = 'Etkinlik Yönetimi';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-header">
    <h1 class="page-title">
        <i class="fas fa-tasks"></i> Etkinlik Yönetimi
    </h1>
    <a href="<?php echo base_url('admin/add_event.php'); ?>" class="btn btn-primary">
        <i class="fas fa-plus-circle"></i> Yeni Etkinlik Ekle
    </a>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'error'; ?>">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
        <span><?php echo e($flash['message']); ?></span>
    </div>
<?php endif; ?>

<div class="glass-card table-container">
    <?php if (empty($events)): ?>
        <div class="empty-state">
            <i class="fas fa-calendar-times"></i>
            <p>Henüz etkinlik eklenmemiş.</p>
            <a href="<?php echo base_url('admin/add_event.php'); ?>" class="btn btn-primary">
                <i class="fas fa-plus-circle"></i> İlk Etkinliği Ekle
            </a>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Görsel</th>
                        <th>Başlık</th>
                        <th>Kategori</th>
                        <th>Tarih</th>
                        <th>Fiyat</th>
                        <th>Kapasite</th>
                        <th>Satılan</th>
                        <th>Durum</th>
                        <th>İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $event): ?>
                        <tr>
                            <td class="text-center"><?php echo (int)$event['id']; ?></td>
                            <td>
                                <img 
                                    src="<?php echo e($event['image_url']); ?>" 
                                    alt="<?php echo e($event['title']); ?>" 
                                    class="table-thumbnail"
                                    onerror="this.src='https://placehold.co/80x50/1a1a2e/7c3aed?text=Görsel'"
                                >
                            </td>
                            <td>
                                <strong><?php echo e($event['title']); ?></strong>
                                <br>
                                <small class="text-muted">
                                    <i class="fas fa-map-marker-alt"></i> 
                                    <?php echo e($event['venue'] . ', ' . $event['city']); ?>
                                </small>
                            </td>
                            <td><?php echo e($event['category_name'] ?? 'Kategori Yok'); ?></td>
                            <td>
                                <span class="nowrap"><?php echo format_date($event['event_date'], 'd.m.Y'); ?></span>
                                <br>
                                <small class="text-muted"><?php echo format_date($event['event_date'], 'H:i'); ?></small>
                            </td>
                            <td class="text-right nowrap"><?php echo format_price($event['price']); ?></td>
                            <td class="text-center"><?php echo (int)$event['total_capacity']; ?></td>
                            <td class="text-center">
                                <span class="sold-count"><?php echo (int)$event['sold_count']; ?></span>
                            </td>
                            <td>
                                <?php echo status_badge($event['status']); ?>
                            </td>
                            <td class="actions-cell">
                                <div class="action-buttons">
                                    <!-- Düzenle -->
                                    <a href="<?php echo base_url('admin/edit_event.php?id=' . (int)$event['id']); ?>" 
                                       class="btn btn-sm btn-info" 
                                       title="Düzenle">
                                        <i class="fas fa-edit"></i>
                                    </a>

                                    <!-- Durumu Değiştir -->
                                    <?php if ($event['status'] === 'active'): ?>
                                        <form method="POST" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                            <input type="hidden" name="action" value="change_status">
                                            <input type="hidden" name="event_id" value="<?php echo (int)$event['id']; ?>">
                                            <input type="hidden" name="new_status" value="completed">
                                            <button type="submit" class="btn btn-sm btn-warning" title="Tamamlandı olarak işaretle">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                        <form method="POST" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                            <input type="hidden" name="action" value="change_status">
                                            <input type="hidden" name="event_id" value="<?php echo (int)$event['id']; ?>">
                                            <input type="hidden" name="new_status" value="cancelled">
                                            <button type="submit" class="btn btn-sm btn-danger" title="İptal et">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                        </form>
                                    <?php elseif ($event['status'] === 'cancelled' || $event['status'] === 'completed'): ?>
                                        <form method="POST" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                            <input type="hidden" name="action" value="change_status">
                                            <input type="hidden" name="event_id" value="<?php echo (int)$event['id']; ?>">
                                            <input type="hidden" name="new_status" value="active">
                                            <button type="submit" class="btn btn-sm btn-success" title="Aktif yap">
                                                <i class="fas fa-play"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- Sil -->
                                    <form method="POST" class="inline-form" 
                                          onsubmit="return confirm('Bu etkinliği silmek istediğinize emin misiniz?\n\nDİKKAT: Etkinliğe ait tüm rezervasyonlar ve biletler de silinecektir!');">
                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="event_id" value="<?php echo (int)$event['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Sil">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="table-footer">
            <p class="text-muted">
                Toplam <?php echo count($events); ?> etkinlik listeleniyor.
            </p>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
