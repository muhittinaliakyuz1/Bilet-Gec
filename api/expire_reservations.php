<?php
/**
 * Bilet-Geç API - Süresi Dolmuş Rezervasyonları Temizle
 * GET veya POST (cron veya AJAX ile çağrılabilir)
 * Returns: { success, expired_count }
 */

define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

start_secure_session();

header('Content-Type: application/json; charset=utf-8');

try {
    // Accept both GET and POST
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Sadece GET veya POST istekleri kabul edilir.']);
        exit;
    }

    // Expire old reservations
    $expired_count = expire_old_reservations($pdo);

    echo json_encode([
        'success'       => true,
        'expired_count' => $expired_count,
        'message'       => $expired_count > 0
            ? $expired_count . ' adet rezervasyonun süresi doldu ve temizlendi.'
            : 'Süresi dolmuş rezervasyon bulunamadı.',
    ]);

} catch (Exception $e) {
    error_log('expire_reservations API hatası: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Sunucu hatası oluştu.']);
}
