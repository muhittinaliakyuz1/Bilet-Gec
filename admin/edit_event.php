<?php
/**
 * Bilet-Geç Firma Paneli - Etkinlik Düzenle
 */

define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

start_secure_session();
require_panel();

global $pdo;

$event_id = (int)($_GET['id'] ?? 0);
$user_id = get_current_user_id();

if ($event_id <= 0) {
    set_flash('error', 'Geçersiz etkinlik ID.');
    header('Location: ' . base_url('admin/manage_events.php'));
    exit;
}

$scopeSql = is_superadmin()
    ? 'SELECT e.*, c.name AS category_name FROM events e LEFT JOIN categories c ON e.category_id = c.id WHERE e.id = ?'
    : 'SELECT e.*, c.name AS category_name FROM events e LEFT JOIN categories c ON e.category_id = c.id WHERE e.id = ? AND e.created_by = ?';
$scopeParams = is_superadmin() ? [$event_id] : [$event_id, $user_id];

$stmt = $pdo->prepare($scopeSql);
$stmt->execute($scopeParams);
$event = $stmt->fetch();

if (!$event) {
    set_flash('error', 'Etkinlik bulunamadı veya yetkiniz yok.');
    header('Location: ' . base_url('admin/manage_events.php'));
    exit;
}

$stmt = $pdo->prepare("SELECT id, name, icon FROM categories ORDER BY name ASC");
$stmt->execute();
$categories = $stmt->fetchAll();

$firmas = [];
if (is_superadmin()) {
    $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE role IN ('firma', 'superadmin') ORDER BY full_name ASC");
    $stmt->execute();
    $firmas = $stmt->fetchAll();
}

$csrf_token = generate_csrf_token();
$flash = get_flash();

$page_title = 'Etkinlik Düzenle';
$current_page = 'manage_events';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/_sidebar.php';

$event_date_date = $event['event_date'] ? date('Y-m-d', strtotime($event['event_date'])) : '';
$event_date_time = $event['event_date'] ? date('H:i', strtotime($event['event_date'])) : '';
$end_date_date = $event['end_date'] ? date('Y-m-d', strtotime($event['end_date'])) : '';
$end_date_time = $event['end_date'] ? date('H:i', strtotime($event['end_date'])) : '';
?>

<div class="admin-content">
<div class="admin-header">
    <h1 class="page-title"><i class="fas fa-edit"></i> Etkinlik Düzenle</h1>
    <a href="<?php echo base_url('admin/manage_events.php'); ?>" class="btn btn-secondary btn-sm">← Geri Dön</a>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'error'; ?>">
        <span><?php echo e($flash['message']); ?></span>
    </div>
<?php endif; ?>

<div class="glass-card form-card">
    <form method="POST" enctype="multipart/form-data" action="<?php echo base_url('admin/event_submit.php'); ?>" class="admin-form" id="edit-event-form" data-validate novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
        <input type="hidden" name="event_id" value="<?php echo (int)$event['id']; ?>">
        <input type="hidden" name="return_to" value="<?php echo base_url('admin/edit_event.php?id=' . (int)$event['id']); ?>">

        <div class="form-grid">
            <div class="form-group form-group-full">
                <label class="form-label">Etkinlik Başlığı <span class="required">*</span></label>
                <input type="text" name="title" class="form-input" value="<?php echo e($event['title']); ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Kategori <span class="required">*</span></label>
                <select name="category_id" class="form-input" required>
                    <option value="">Kategori seçin</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int)$cat['id']; ?>" <?php echo (int)$event['category_id'] === (int)$cat['id'] ? 'selected' : ''; ?>>
                            <?php echo e($cat['icon'] . ' ' . $cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Organizatör <span class="required">*</span></label>
                <input type="text" name="organizer" class="form-input" value="<?php echo e($event['organizer']); ?>" required>
            </div>

            <?php if (is_superadmin()): ?>
            <div class="form-group form-group-full">
                <label class="form-label">Etkinlik Sahibi Firma <span class="required">*</span></label>
                <select name="created_by" class="form-input" required>
                    <option value="">Firma Seçin</option>
                    <?php foreach ($firmas as $firma): ?>
                        <option value="<?php echo (int)$firma['id']; ?>" <?php echo (int)$event['created_by'] === (int)$firma['id'] ? 'selected' : ''; ?>><?php echo e($firma['full_name'] . ' (' . $firma['email'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="form-group form-group-full">
                <label class="form-label">Kısa Açıklama <span class="required">*</span></label>
                <textarea name="short_description" class="form-input form-textarea" rows="3" maxlength="300" required><?php echo e($event['short_description']); ?></textarea>
            </div>

            <div class="form-group form-group-full">
                <label class="form-label">Detaylı Açıklama <span class="required">*</span></label>
                <textarea name="description" class="form-input form-textarea" rows="6" required><?php echo e($event['description']); ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Mekan <span class="required">*</span></label>
                <input type="text" name="venue" class="form-input" value="<?php echo e($event['venue']); ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Şehir <span class="required">*</span></label>
                <input type="text" name="city" class="form-input" value="<?php echo e($event['city']); ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Başlangıç Tarihi <span class="required">*</span></label>
                <div class="date-time-row">
                    <input type="date" name="event_date_date" class="form-input" value="<?php echo e($event_date_date); ?>" required min="<?php echo date('Y-m-d'); ?>">
                    <input type="time" name="event_date_time" class="form-input" value="<?php echo e($event_date_time); ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Bitiş Tarihi (Opsiyonel)</label>
                <div class="date-time-row">
                    <input type="date" name="end_date_date" class="form-input" value="<?php echo e($end_date_date); ?>">
                    <input type="time" name="end_date_time" class="form-input" value="<?php echo e($end_date_time); ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Bilet Fiyatı (₺) <span class="required">*</span></label>
                <input type="number" name="price" class="form-input" step="0.01" min="0" value="<?php echo e($event['price']); ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Toplam Kapasite <span class="required">*</span></label>
                <input type="number" name="total_capacity" class="form-input" min="1" value="<?php echo (int)$event['total_capacity']; ?>" required>
            </div>

            <div class="form-group form-group-full">
                <label class="form-label">Görsel URL veya Dosya</label>
                <input type="url" name="image_url" class="form-input" value="<?php echo e($event['image_url']); ?>" placeholder="https://ornek.com/gorsel.jpg">
                <input type="file" name="image_file" accept="image/*" class="form-input" style="margin-top: 8px;">
                <small class="form-note">Buraya dosya seçerseniz URL yerine yüklenecek dosya kullanılacaktır. Maksimum 2MB, JPG/PNG/GIF/WEBP formatları desteklenir.</small>
            </div>
        </div>

        <div class="form-actions" style="margin-top: 20px; display: flex; gap: 12px;">
            <button type="submit" class="btn btn-primary">Değişiklikleri Kaydet</button>
            <a href="<?php echo base_url('admin/manage_events.php'); ?>" class="btn btn-secondary">İptal</a>
        </div>
    </form>
</div>
</div>

<?php require_once __DIR__ . '/_sidebar_footer.php'; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
