<?php
/**
 * Bilet-Geç Admin - Etkinlik Ekle
 */

define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

start_secure_session();
require_admin();

global $pdo;

$errors = [];
$old = [];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF verification
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = 'Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.';
    }

    // Collect and sanitize form data
    $old = [
        'title'             => trim($_POST['title'] ?? ''),
        'category_id'       => (int)($_POST['category_id'] ?? 0),
        'short_description' => trim($_POST['short_description'] ?? ''),
        'description'       => trim($_POST['description'] ?? ''),
        'venue'             => trim($_POST['venue'] ?? ''),
        'city'              => trim($_POST['city'] ?? ''),
        'event_date'        => trim($_POST['event_date'] ?? ''),
        'end_date'          => trim($_POST['end_date'] ?? ''),
        'price'             => trim($_POST['price'] ?? ''),
        'total_capacity'    => trim($_POST['total_capacity'] ?? ''),
        'organizer'         => trim($_POST['organizer'] ?? ''),
        'image_url'         => trim($_POST['image_url'] ?? ''),
    ];

    // Validation
    if (empty($old['title'])) {
        $errors[] = 'Etkinlik başlığı zorunludur.';
    }

    if ($old['category_id'] <= 0) {
        $errors[] = 'Lütfen bir kategori seçin.';
    }

    if (empty($old['short_description'])) {
        $errors[] = 'Kısa açıklama zorunludur.';
    } elseif (mb_strlen($old['short_description']) > 300) {
        $errors[] = 'Kısa açıklama en fazla 300 karakter olabilir.';
    }

    if (empty($old['description'])) {
        $errors[] = 'Açıklama zorunludur.';
    }

    if (empty($old['venue'])) {
        $errors[] = 'Mekan bilgisi zorunludur.';
    }

    if (empty($old['city'])) {
        $errors[] = 'Şehir bilgisi zorunludur.';
    }

    if (empty($old['event_date'])) {
        $errors[] = 'Etkinlik tarihi zorunludur.';
    }

    if ($old['price'] === '' || !is_numeric($old['price']) || (float)$old['price'] < 0) {
        $errors[] = 'Geçerli bir fiyat giriniz.';
    }

    if (empty($old['total_capacity']) || !is_numeric($old['total_capacity']) || (int)$old['total_capacity'] < 1) {
        $errors[] = 'Geçerli bir kapasite giriniz (en az 1).';
    }

    if (empty($old['organizer'])) {
        $errors[] = 'Organizatör adı zorunludur.';
    }

    // Verify category exists
    if ($old['category_id'] > 0 && empty($errors)) {
        $stmt = $pdo->prepare("SELECT id FROM categories WHERE id = ?");
        $stmt->execute([$old['category_id']]);
        if (!$stmt->fetch()) {
            $errors[] = 'Seçilen kategori bulunamadı.';
        }
    }

    // If no errors, insert the event
    if (empty($errors)) {
        try {
            $image_url = !empty($old['image_url']) 
                ? $old['image_url'] 
                : 'https://placehold.co/800x400/1a1a2e/7c3aed?text=Etkinlik';

            $end_date = !empty($old['end_date']) ? $old['end_date'] : null;

            // Convert datetime-local format to MySQL datetime
            $event_date_mysql = date('Y-m-d H:i:s', strtotime($old['event_date']));
            $end_date_mysql = $end_date ? date('Y-m-d H:i:s', strtotime($end_date)) : null;

            $stmt = $pdo->prepare("
                INSERT INTO events (
                    category_id, title, description, short_description, 
                    venue, city, event_date, end_date, 
                    price, total_capacity, image_url, organizer, 
                    status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
            ");
            $stmt->execute([
                $old['category_id'],
                $old['title'],
                $old['description'],
                $old['short_description'],
                $old['venue'],
                $old['city'],
                $event_date_mysql,
                $end_date_mysql,
                (float)$old['price'],
                (int)$old['total_capacity'],
                $image_url,
                $old['organizer'],
            ]);

            set_flash('success', 'Etkinlik başarıyla oluşturuldu!');
            header('Location: ' . base_url('admin/manage_events.php'));
            exit;

        } catch (PDOException $e) {
            error_log('Etkinlik ekleme hatası: ' . $e->getMessage());
            $errors[] = 'Veritabanı hatası oluştu. Lütfen tekrar deneyin.';
        }
    }
}

// Fetch categories for dropdown
$stmt = $pdo->prepare("SELECT id, name, icon FROM categories ORDER BY name ASC");
$stmt->execute();
$categories = $stmt->fetchAll();

$csrf_token = generate_csrf_token();

$page_title = 'Etkinlik Ekle';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-header">
    <h1 class="page-title">
        <i class="fas fa-plus-circle"></i> Etkinlik Ekle
    </h1>
    <a href="<?php echo base_url('admin/manage_events.php'); ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Etkinliklere Dön
    </a>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-triangle"></i>
        <div>
            <strong>Lütfen aşağıdaki hataları düzeltin:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo e($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<div class="glass-card form-card">
    <form method="POST" action="" class="admin-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">

        <div class="form-grid">
            <!-- Başlık -->
            <div class="form-group form-group-full">
                <label for="title" class="form-label">
                    <i class="fas fa-heading"></i> Etkinlik Başlığı <span class="required">*</span>
                </label>
                <input 
                    type="text" 
                    id="title" 
                    name="title" 
                    class="form-input" 
                    value="<?php echo e($old['title'] ?? ''); ?>" 
                    placeholder="Etkinlik başlığını giriniz"
                    required
                >
            </div>

            <!-- Kategori -->
            <div class="form-group">
                <label for="category_id" class="form-label">
                    <i class="fas fa-tag"></i> Kategori <span class="required">*</span>
                </label>
                <select id="category_id" name="category_id" class="form-input form-select" required>
                    <option value="">Kategori seçin</option>
                    <?php foreach ($categories as $cat): ?>
                        <option 
                            value="<?php echo (int)$cat['id']; ?>"
                            <?php echo (isset($old['category_id']) && $old['category_id'] === (int)$cat['id']) ? 'selected' : ''; ?>
                        >
                            <?php echo e($cat['icon'] . ' ' . $cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Organizatör -->
            <div class="form-group">
                <label for="organizer" class="form-label">
                    <i class="fas fa-user-tie"></i> Organizatör <span class="required">*</span>
                </label>
                <input 
                    type="text" 
                    id="organizer" 
                    name="organizer" 
                    class="form-input" 
                    value="<?php echo e($old['organizer'] ?? ''); ?>" 
                    placeholder="Organizatör adı"
                    required
                >
            </div>

            <!-- Kısa Açıklama -->
            <div class="form-group form-group-full">
                <label for="short_description" class="form-label">
                    <i class="fas fa-align-left"></i> Kısa Açıklama <span class="required">*</span>
                    <small class="char-hint">(Maks. 300 karakter)</small>
                </label>
                <textarea 
                    id="short_description" 
                    name="short_description" 
                    class="form-input form-textarea" 
                    rows="3" 
                    maxlength="300" 
                    placeholder="Kısa bir açıklama giriniz"
                    required
                ><?php echo e($old['short_description'] ?? ''); ?></textarea>
                <div class="char-counter">
                    <span id="shortDescCount"><?php echo mb_strlen($old['short_description'] ?? ''); ?></span>/300
                </div>
            </div>

            <!-- Açıklama -->
            <div class="form-group form-group-full">
                <label for="description" class="form-label">
                    <i class="fas fa-file-alt"></i> Detaylı Açıklama <span class="required">*</span>
                </label>
                <textarea 
                    id="description" 
                    name="description" 
                    class="form-input form-textarea" 
                    rows="6" 
                    placeholder="Etkinlik hakkında detaylı bilgi giriniz"
                    required
                ><?php echo e($old['description'] ?? ''); ?></textarea>
            </div>

            <!-- Mekan -->
            <div class="form-group">
                <label for="venue" class="form-label">
                    <i class="fas fa-map-marker-alt"></i> Mekan <span class="required">*</span>
                </label>
                <input 
                    type="text" 
                    id="venue" 
                    name="venue" 
                    class="form-input" 
                    value="<?php echo e($old['venue'] ?? ''); ?>" 
                    placeholder="Etkinlik mekanı"
                    required
                >
            </div>

            <!-- Şehir -->
            <div class="form-group">
                <label for="city" class="form-label">
                    <i class="fas fa-city"></i> Şehir <span class="required">*</span>
                </label>
                <input 
                    type="text" 
                    id="city" 
                    name="city" 
                    class="form-input" 
                    value="<?php echo e($old['city'] ?? ''); ?>" 
                    placeholder="Şehir adı"
                    required
                >
            </div>

            <!-- Etkinlik Tarihi -->
            <div class="form-group">
                <label for="event_date" class="form-label">
                    <i class="fas fa-calendar"></i> Başlangıç Tarihi <span class="required">*</span>
                </label>
                <input 
                    type="datetime-local" 
                    id="event_date" 
                    name="event_date" 
                    class="form-input" 
                    value="<?php echo e($old['event_date'] ?? ''); ?>"
                    required
                >
            </div>

            <!-- Bitiş Tarihi -->
            <div class="form-group">
                <label for="end_date" class="form-label">
                    <i class="fas fa-calendar-check"></i> Bitiş Tarihi
                    <small class="char-hint">(Opsiyonel)</small>
                </label>
                <input 
                    type="datetime-local" 
                    id="end_date" 
                    name="end_date" 
                    class="form-input" 
                    value="<?php echo e($old['end_date'] ?? ''); ?>"
                >
            </div>

            <!-- Fiyat -->
            <div class="form-group">
                <label for="price" class="form-label">
                    <i class="fas fa-lira-sign"></i> Bilet Fiyatı (₺) <span class="required">*</span>
                </label>
                <input 
                    type="number" 
                    id="price" 
                    name="price" 
                    class="form-input" 
                    value="<?php echo e($old['price'] ?? ''); ?>" 
                    step="0.01" 
                    min="0" 
                    placeholder="0.00"
                    required
                >
            </div>

            <!-- Kapasite -->
            <div class="form-group">
                <label for="total_capacity" class="form-label">
                    <i class="fas fa-users"></i> Toplam Kapasite <span class="required">*</span>
                </label>
                <input 
                    type="number" 
                    id="total_capacity" 
                    name="total_capacity" 
                    class="form-input" 
                    value="<?php echo e($old['total_capacity'] ?? ''); ?>" 
                    min="1" 
                    placeholder="100"
                    required
                >
            </div>

            <!-- Görsel URL -->
            <div class="form-group form-group-full">
                <label for="image_url" class="form-label">
                    <i class="fas fa-image"></i> Görsel URL
                    <small class="char-hint">(Boş bırakılırsa varsayılan görsel kullanılır)</small>
                </label>
                <input 
                    type="url" 
                    id="image_url" 
                    name="image_url" 
                    class="form-input" 
                    value="<?php echo e($old['image_url'] ?? ''); ?>" 
                    placeholder="https://ornek.com/gorsel.jpg"
                >
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fas fa-plus-circle"></i> Etkinlik Oluştur
            </button>
            <a href="<?php echo base_url('admin/manage_events.php'); ?>" class="btn btn-secondary btn-lg">
                <i class="fas fa-times"></i> İptal
            </a>
        </div>
    </form>
</div>

<script>
// Character counter for short description
document.addEventListener('DOMContentLoaded', function() {
    const shortDesc = document.getElementById('short_description');
    const counter = document.getElementById('shortDescCount');
    
    if (shortDesc && counter) {
        shortDesc.addEventListener('input', function() {
            counter.textContent = this.value.length;
            if (this.value.length > 300) {
                counter.parentElement.classList.add('over-limit');
            } else {
                counter.parentElement.classList.remove('over-limit');
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
