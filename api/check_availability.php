<?php
/**
 * Bilet-Geç API - Etkinlik Müsaitlik Kontrolü
 * GET: ?event_id=123
 * Returns: { success, remaining, total_capacity, percentage }
 */

define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

start_secure_session();

header('Content-Type: application/json; charset=utf-8');

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Sadece GET istekleri kabul edilir.']);
        exit;
    }

    // Validate event_id parameter
    if (!isset($_GET['event_id']) || !is_numeric($_GET['event_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Geçerli bir event_id parametresi gereklidir.']);
        exit;
    }

    $event_id = (int)$_GET['event_id'];

    // Expire old reservations first to free up seats
    expire_old_reservations($pdo);

    // Get event and capacity
    $remaining = get_remaining_capacity($pdo, $event_id);
    $event = get_event_by_id($pdo, $event_id);

    if ($event === false) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Etkinlik bulunamadı.']);
        exit;
    }

    $total_capacity = (int)$event['total_capacity'];
    $percentage = $total_capacity > 0 ? ($remaining / $total_capacity) * 100 : 0;

    echo json_encode([
        'success'        => true,
        'remaining'      => $remaining,
        'total_capacity' => $total_capacity,
        'percentage'     => $percentage,
    ]);

} catch (Exception $e) {
    error_log('check_availability API hatası: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Sunucu hatası oluştu.']);
}
