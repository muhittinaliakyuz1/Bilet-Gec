<?php
/**
 * Partial: Add Event Modal (used in manage_events.php)
 */
if (!defined('ALLOWED_ACCESS')) {
    define('ALLOWED_ACCESS', true);
}

// Ensure session and helpers are available
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Fetch categories
$stmt = $pdo->prepare("SELECT id, name, icon FROM categories ORDER BY name ASC");
$stmt->execute();
$categories_modal = $stmt->fetchAll();

$firmas_modal = [];
if (is_superadmin()) {
    $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE role IN ('firma', 'superadmin') ORDER BY full_name ASC");
    $stmt->execute();
    $firmas_modal = $stmt->fetchAll();
}

$modal_csrf = generate_csrf_token();
?>

<div id="add-event-modal" class="modal-overlay" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="add-event-title">
        <div class="modal-header">
            <h3 id="add-event-title" class="modal-title">Yeni Etkinlik Oluştur</h3>
            <button class="modal-close" data-modal-close>&times;</button>
        </div>
        <div class="modal-body">
            <div class="glass-card form-card">
                <form method="POST" enctype="multipart/form-data" action="<?php echo base_url('admin/event_submit.php'); ?>" class="admin-form" data-validate novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo e($modal_csrf); ?>">
                    <input type="hidden" name="return_to" value="<?php echo base_url('admin/manage_events.php'); ?>">

                    <div class="form-grid">
                        <div class="form-group form-group-full">
                            <label class="form-label">Etkinlik Başlığı <span class="required">*</span></label>
                            <input type="text" name="title" class="form-input" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Kategori <span class="required">*</span></label>
                            <select name="category_id" class="form-input" required>
                                <option value="">Kategori seçin</option>
                                <?php foreach ($categories_modal as $cat): ?>
                                    <option value="<?php echo (int)$cat['id']; ?>"><?php echo e($cat['icon'] . ' ' . $cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Organizatör <span class="required">*</span></label>
                            <input type="text" name="organizer" class="form-input" required>
                        </div>
                        
                        <?php if (is_superadmin()): ?>
                        <div class="form-group form-group-full">
                            <label class="form-label">Etkinlik Sahibi Firma <span class="required">*</span></label>
                            <select name="created_by" class="form-input" required>
                                <option value="">Firma Seçin</option>
                                <?php foreach ($firmas_modal as $firma): ?>
                                    <option value="<?php echo (int)$firma['id']; ?>"><?php echo e($firma['full_name'] . ' (' . $firma['email'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-note">Süper admin olarak etkinliğin hangi firmaya ait olduğunu seçmelisiniz.</small>
                        </div>
                        <?php endif; ?>

                        <div class="form-group form-group-full">
                            <label class="form-label">Kısa Açıklama <span class="required">*</span></label>
                            <textarea name="short_description" class="form-input form-textarea" rows="3" maxlength="300" required></textarea>
                        </div>

                        <div class="form-group form-group-full">
                            <label class="form-label">Detaylı Açıklama <span class="required">*</span></label>
                            <textarea name="description" class="form-input form-textarea" rows="6" required></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Mekan <span class="required">*</span></label>
                            <input type="text" name="venue" class="form-input" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Şehir <span class="required">*</span></label>
                            <input type="text" name="city" class="form-input" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Başlangıç Tarihi <span class="required">*</span></label>
                            <div class="date-time-row">
                                <input type="date" name="event_date_date" class="form-input" required min="<?php echo date('Y-m-d'); ?>">
                                <input type="time" name="event_date_time" class="form-input" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Bitiş Tarihi (Opsiyonel)</label>
                            <div class="date-time-row">
                                <input type="date" name="end_date_date" class="form-input">
                                <input type="time" name="end_date_time" class="form-input">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Bilet Fiyatı (₺) <span class="required">*</span></label>
                            <input type="number" name="price" class="form-input" step="0.01" min="0" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Toplam Kapasite <span class="required">*</span></label>
                            <input type="number" name="total_capacity" class="form-input" min="1" required>
                        </div>

                        <div class="form-group form-group-full">
                            <label class="form-label">Görsel URL veya Dosya (Opsiyonel)</label>
                            <input type="url" name="image_url" class="form-input" placeholder="https://ornek.com/gorsel.jpg">
                            <input type="file" name="image_file" accept="image/*" class="form-input" style="margin-top: 8px;">
                            <small class="form-note">Dosya seçerseniz URL yerine yüklenen dosya kullanılacaktır. Maksimum 2MB, JPG/PNG/GIF/WEBP formatları desteklenir.</small>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-modal-close>İptal</button>
                        <button type="submit" class="btn btn-primary">Etkinliği Oluştur</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
