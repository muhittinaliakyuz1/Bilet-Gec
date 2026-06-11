<?php
/**
 * Bilet-Geç API - Rezervasyon İptali
 * POST JSON: { reservation_id }
 * Returns: { success, message }
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

    // CSRF doğrulama
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

    // Parse JSON body
    $input = json_decode(file_get_contents('php://input'), true);

    if ($input === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Geçersiz JSON verisi.']);
        exit;
    }

    // Validate reservation_id
    if (!isset($input['reservation_id']) || !is_numeric($input['reservation_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Geçerli bir reservation_id gereklidir.']);
        exit;
    }

    $reservation_id = (int)$input['reservation_id'];

    // Verify the reservation exists and belongs to this user
    global $pdo;

    $stmt = $pdo->prepare("SELECT id, user_id, status FROM reservations WHERE id = ?");
    $stmt->execute([$reservation_id]);
    $reservation = $stmt->fetch();

    if (!$reservation) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Rezervasyon bulunamadı.']);
        exit;
    }

    // Check ownership
    if ((int)$reservation['user_id'] !== $user_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Bu rezervasyon size ait değil.']);
        exit;
    }

    // Check if already cancelled or confirmed
    if ($reservation['status'] === 'cancelled') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Bu rezervasyon zaten iptal edilmiş.']);
        exit;
    }

    if ($reservation['status'] === 'confirmed') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Onaylanmış bir rezervasyon iptal edilemez.']);
        exit;
    }

    if ($reservation['status'] === 'expired') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Süresi dolmuş bir rezervasyon iptal edilemez.']);
        exit;
    }

    // Cancel the reservation
    $cancelled = cancel_reservation($pdo, $reservation_id, $user_id);

    if (!$cancelled) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'İptal işlemi başarısız oldu.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Rezervasyon iptal edildi.',
    ]);

} catch (Exception $e) {
    error_log('cancel_reservation API hatası: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Sunucu hatası oluştu.']);
}
