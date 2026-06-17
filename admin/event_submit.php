<?php
/**
 * Etkinlik Oluştur / Güncelle Handler
 * Basit, temiz ve doğrudan mantık
 */

define('ALLOWED_ACCESS', true);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/image_handler.php';
require_once __DIR__ . '/../includes/activity_log.php';

start_secure_session();
require_panel();

global $pdo;

// ===== 1. İstek Türü ve CSRF Kontrol =====
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . base_url('admin/manage_events.php'));
    exit;
}

$csrf_token = trim($_POST['csrf_token'] ?? '');
$return_to = trim($_POST['return_to'] ?? base_url('admin/manage_events.php'));

if (!verify_csrf_token($csrf_token)) {
    set_flash('error', 'CSRF doğrulaması başarısız.');
    header('Location: ' . $return_to);
    exit;
}

$user_id = get_current_user_id();
$event_id = (int)($_POST['event_id'] ?? 0);

// ===== 2. Form Verilerini Al =====
$title = trim($_POST['title'] ?? '');
$category_id = (int)($_POST['category_id'] ?? 0);
$organizer = trim($_POST['organizer'] ?? '');
$short_description = trim($_POST['short_description'] ?? '');
$description = trim($_POST['description'] ?? '');
$venue = trim($_POST['venue'] ?? '');
$city = trim($_POST['city'] ?? '');
$event_date = trim($_POST['event_date'] ?? '');
$event_date_date = trim($_POST['event_date_date'] ?? '');
$event_date_time = trim($_POST['event_date_time'] ?? '');
$end_date = trim($_POST['end_date'] ?? '');
$end_date_date = trim($_POST['end_date_date'] ?? '');
$end_date_time = trim($_POST['end_date_time'] ?? '');
$price = isset($_POST['price']) ? (float)$_POST['price'] : 0.0;
$total_capacity = isset($_POST['total_capacity']) ? (int)$_POST['total_capacity'] : 0;
$image_url_input = trim($_POST['image_url'] ?? '');

$event_owner_id = $user_id;
if (is_superadmin() && !empty($_POST['created_by'])) {
    $event_owner_id = (int)$_POST['created_by'];
}

$errors = [];

if ($event_date_date !== '' || $event_date_time !== '') {
    if ($event_date_date === '' || $event_date_time === '') {
        $errors[] = 'Başlangıç tarihi ve saat birlikte girilmelidir.';
    } else {
        $event_date = $event_date_date . ' ' . $event_date_time;
    }
}
if ($end_date_date !== '' || $end_date_time !== '') {
    if ($end_date_date === '' || $end_date_time === '') {
        $errors[] = 'Bitiş tarihi ve saati birlikte girilmelidir.';
    } else {
        $end_date = $end_date_date . ' ' . $end_date_time;
    }
}

// Tarih formatı: T'yi boşluk ile değiştir
if ($event_date !== '') {
    $event_date = str_replace('T', ' ', $event_date);
}
if ($end_date !== '') {
    $end_date = str_replace('T', ' ', $end_date);
}

// ===== 3. Validasyon =====
$errors = [];

if (empty($title)) {
    $errors[] = 'Etkinlik başlığı zorunludur.';
}
if ($category_id <= 0) {
    $errors[] = 'Kategori seçmelisiniz.';
}
if (empty($organizer)) {
    $errors[] = 'Organizatör zorunludur.';
}
if (empty($short_description)) {
    $errors[] = 'Kısa açıklama zorunludur.';
}
if (empty($description)) {
    $errors[] = 'Detaylı açıklama zorunludur.';
}
if (empty($venue)) {
    $errors[] = 'Mekan zorunludur.';
}
if (empty($city)) {
    $errors[] = 'Şehir zorunludur.';
}
if (empty($event_date)) {
    $errors[] = 'Etkinlik tarihi zorunludur.';
}
if ($total_capacity <= 0) {
    $errors[] = 'Kapasite 0 dan büyük olmalıdır.';
}
if ($price < 0) {
    $errors[] = 'Fiyat negatif olamaz.';
}

// Tarih doğrulama
$eventDateTime = $event_date ? date_create($event_date) : false;
$endDateTime = $end_date ? date_create($end_date) : false;

if ($event_date && !$eventDateTime) {
    $errors[] = 'Geçersiz etkinlik tarihi.';
}
if ($end_date && !$endDateTime) {
    $errors[] = 'Geçersiz bitiş tarihi.';
}

if ($eventDateTime) {
    $now = new DateTime('now');
    if ($eventDateTime < $now) {
        $errors[] = 'Etkinlik başlangıcı bugünden önce olamaz.';
    }
}
if ($eventDateTime && $endDateTime && $endDateTime < $eventDateTime) {
    $errors[] = 'Bitiş tarihi başlangıçtan önce olamaz.';
}

if (!empty($errors)) {
    set_flash('error', implode(' | ', $errors));
    header('Location: ' . $return_to);
    exit;
}

$event_date_mysql = $eventDateTime ? $eventDateTime->format('Y-m-d H:i:s') : null;
$end_date_mysql = $endDateTime ? $endDateTime->format('Y-m-d H:i:s') : null;

// ===== 4. Düzenleme İçin Mevcut Etkinlik Kontrol =====
$currentImageUrl = '';
if ($event_id > 0) {
    $check_sql = is_superadmin()
        ? 'SELECT id, image_url FROM events WHERE id = ?'
        : 'SELECT id, image_url FROM events WHERE id = ? AND created_by = ?';
    
    $check_params = is_superadmin() ? [$event_id] : [$event_id, $user_id];
    $stmt = $pdo->prepare($check_sql);
    $stmt->execute($check_params);
    $existing = $stmt->fetch();
    
    if (!$existing) {
        set_flash('error', 'Etkinlik bulunamadı veya düzenleme yetkiniz yok.');
        header('Location: ' . $return_to);
        exit;
    }
    
    $currentImageUrl = trim($existing['image_url'] ?? '');
}

try {
    // ===== 5. Görsel İşle (Yeni Dosya veya URL) =====
    $final_image_url = $currentImageUrl;

    // Yüklenen dosya varsa
    if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        // Yeni etkinlik için geçici ID kullan, update sonra gerçek ID
        $img_id = $event_id > 0 ? $event_id : 0;
        
        $result = handle_image_upload($_FILES['image_file'], $img_id);
        if (!$result['success']) {
            throw new RuntimeException($result['error']);
        }
        $final_image_url = $result['path'];
    }
    // URL'den görsel indir
    elseif (!empty($image_url_input)) {
        $img_id = $event_id > 0 ? $event_id : 0;
        
        $result = handle_remote_image($image_url_input, $img_id);
        if (!$result['success']) {
            throw new RuntimeException($result['error']);
        }
        $final_image_url = $result['path'];
    }

    // ===== 6. Düzenle veya Oluştur =====
    if ($event_id > 0) {
        // GÜNCELLE
        $update_sql = is_superadmin()
            ? 'UPDATE events SET category_id=?, title=?, description=?, short_description=?, venue=?, city=?, event_date=?, end_date=?, price=?, total_capacity=?, image_url=?, organizer=?, created_by=? WHERE id=?'
            : 'UPDATE events SET category_id=?, title=?, description=?, short_description=?, venue=?, city=?, event_date=?, end_date=?, price=?, total_capacity=?, image_url=?, organizer=? WHERE id=? AND created_by=?';
        
        $update_params = is_superadmin()
            ? [$category_id, $title, $description, $short_description, $venue, $city, $event_date_mysql, $end_date_mysql, $price, $total_capacity, $final_image_url, $organizer, $event_owner_id, $event_id]
            : [$category_id, $title, $description, $short_description, $venue, $city, $event_date_mysql, $end_date_mysql, $price, $total_capacity, $final_image_url, $organizer, $event_id, $user_id];
        
        $stmt = $pdo->prepare($update_sql);
        $stmt->execute($update_params);
        
        log_activity($pdo, $user_id, 'event_update', 'event', $event_id, ['title' => $title]);
        set_flash('success', 'Etkinlik başarıyla güncellendi.');
    } else {
        // OLUŞTUR
        $pdo->beginTransaction();
        
        $insert_sql = 'INSERT INTO events (category_id, title, description, short_description, venue, city, event_date, end_date, price, total_capacity, image_url, organizer, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';
        
        $stmt = $pdo->prepare($insert_sql);
        $stmt->execute([
            $category_id,
            $title,
            $description,
            $short_description,
            $venue,
            $city,
            $event_date_mysql,
            $end_date_mysql,
            $price,
            $total_capacity,
            $final_image_url,
            $organizer,
            'active',
            $event_owner_id
        ]);
        
        $new_id = (int)$pdo->lastInsertId();
        
        // Görsel varsa ve geçici ID kullanmışsak, dosyayı yeniden isimlendirmek gerekebilir
        // (şimdilik final_image_url daha önceden doğru oluşturulmuş olmalı)
        
        $pdo->commit();
        
        log_activity($pdo, $user_id, 'event_create', 'event', $new_id, ['title' => $title]);
        set_flash('success', 'Etkinlik başarıyla oluşturuldu.');
    }

    // Başarı: Yönetim sayfasına dön
    header('Location: ' . base_url('admin/manage_events.php'));
    exit;

} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', 'Etkinlik işleme hatası: ' . $e->getMessage());
    header('Location: ' . $return_to);
    exit;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('event_submit PDO hatası: ' . $e->getMessage());
    set_flash('error', 'Veritabanı işlem hatası.');
    header('Location: ' . $return_to);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('event_submit genel hatası: ' . $e->getMessage());
    set_flash('error', 'Beklenmeyen bir hata oluştu.');
    header('Location: ' . $return_to);
    exit;
}
