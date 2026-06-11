<?php
/**
 * Bilet-Geç - Kupon Doğrulama API
 * POST /api/validate_coupon.php
 * 
 * Request Parameters:
 * - code (string): Kupon kodu
 * - amount (float, optional): Sipariş tutarı
 * 
 * Response:
 * {
 *   "valid": true|false,
 *   "coupon": {...},
 *   "discount_amount": 10.50,
 *   "final_amount": 239.50,
 *   "message": "..."
 * }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/features.php';

start_secure_session();

$response = ['valid' => false, 'message' => 'Geçersiz istek'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode($response);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['code'] ?? '';
$amount = (float)($input['amount'] ?? 0);
$user_id = is_logged_in() ? $_SESSION['user']['id'] : null;

if (empty($code)) {
    http_response_code(400);
    echo json_encode(['valid' => false, 'message' => 'Kupon kodu gereklidir']);
    exit;
}

// Kuponu doğrula
$coupon = validate_coupon($pdo, $code, $user_id, $amount);

if (!$coupon) {
    echo json_encode([
        'valid' => false,
        'message' => 'Kupon geçersiz, süresi dolmuş veya kullanım limitine ulaşmış.'
    ]);
    exit;
}

// İndirim tutarını hesapla
$discount_amount = calculate_discount($coupon, $amount);
$final_amount = $amount - $discount_amount;

echo json_encode([
    'valid' => true,
    'message' => 'Kupon başarıyla uygulandı',
    'coupon' => [
        'id' => $coupon['id'],
        'code' => $coupon['code'],
        'description' => $coupon['description'],
        'discount_type' => $coupon['discount_type'],
        'discount_value' => $coupon['discount_value'],
    ],
    'discount_amount' => round($discount_amount, 2),
    'final_amount' => round($final_amount, 2),
]);
