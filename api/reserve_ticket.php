<?php
/**
 * Bilet-Geç API - Bilet Rezervasyonu
 * POST JSON: { event_id, quantity }
 * Returns: { success, reservation_id, expires_at, remaining, total_price }
 */

define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

start_secure_session();

header('Content-Type: application/json; charset=utf-8');

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Sadece POST istekleri kabul edilir.']);
        exit;
    }

    // Require authentication
    require_login_api();

    // CSRF doğrulama (header veya body)
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!$csrf) {
        $raw = json_decode(file_get_contents('php://input'), true);
        $csrf = $raw['csrf_token'] ?? null;
    }
    if (!verify_csrf_token($csrf ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Geçersiz CSRF token.']);
        exit;
    }

    $user_id = get_current_user_id();

    if (is_panel_user()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Firma hesabında bilet alamazsınız.']);
        exit;
    }

    // Parse JSON body
    $input = json_decode(file_get_contents('php://input'), true);

    if ($input === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Geçersiz JSON verisi.']);
        exit;
    }

    // Validate required fields
    if (!isset($input['event_id']) || !is_numeric($input['event_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Geçerli bir event_id gereklidir.']);
        exit;
    }

    if (!isset($input['quantity']) || !is_numeric($input['quantity']) || (int)$input['quantity'] < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Miktar en az 1 olmalıdır.']);
        exit;
    }

    $event_id = (int)$input['event_id'];
    $quantity = (int)$input['quantity'];

    // Validate quantity upper limit
    if ($quantity > 10) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Tek seferde en fazla 10 bilet alabilirsiniz.']);
        exit;
    }

    // Expire old reservations first
    expire_old_reservations($pdo);

    // Check if event exists and is active
    global $pdo;

    $stmt = $pdo->prepare("SELECT id, title, status, event_date, price FROM events WHERE id = ?");
    $stmt->execute([$event_id]);
    $event = $stmt->fetch();

    if (!$event) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Etkinlik bulunamadı.']);
        exit;
    }

    if ($event['status'] !== 'active') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Bu etkinlik şu anda aktif değil.']);
        exit;
    }

    // Check if event date has passed
    if (strtotime($event['event_date']) < time()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Bu etkinlik sona ermiştir.']);
        exit;
    }

    // Check if user already has a pending reservation for this event, cancel it first
    $stmt = $pdo->prepare("
        SELECT id FROM reservations 
        WHERE user_id = ? AND event_id = ? AND status = 'pending'
    ");
    $stmt->execute([$user_id, $event_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        cancel_reservation($pdo, $existing['id'], $user_id);
    }

    // Create reservation
    $result = create_reservation($pdo, $user_id, $event_id, $quantity);

    if ($result === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Yeterli bilet kalmadı.']);
        exit;
    }

    $remaining = get_remaining_capacity($pdo, $event_id);
    $total_price = $quantity * (float)$event['price'];

    echo json_encode([
        'success'        => true,
        'message'        => 'Rezervasyon oluşturuldu.',
        'reservation_id' => $result['id'],
        'expires_at'     => $result['expires_at'],
        'remaining'      => $remaining,
        'total_price'    => $total_price,
    ]);

} catch (Exception $e) {
    error_log('reserve_ticket API hatası: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Sunucu hatası oluştu.']);
}
