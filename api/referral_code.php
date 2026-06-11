<?php
/**
 * Bilet-Geç - Referral Kod Yönetimi API
 * GET /api/referral_code.php?action=generate|validate
 * POST /api/referral_code.php
 * 
 * GET Response (generate):
 * {
 *   "success": true|false,
 *   "code": "ABC123DEF456",
 *   "reward_points": 100,
 *   "expires_at": "2026-08-28",
 *   "share_url": "..."
 * }
 * 
 * GET Response (validate):
 * {
 *   "valid": true|false,
 *   "referrer_name": "...",
 *   "reward_points": 100
 * }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/features.php';

start_secure_session();

$action = $_GET['action'] ?? '';

if ($action === 'generate') {
    // Referral kodu oluştur
    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Oturum açmanız gerekiyor']);
        exit;
    }
    
    $user_id = $_SESSION['user']['id'];
    
    try {
        $code = generate_referral_code($pdo, $user_id, REFERRAL_REWARD_POINTS);
        
        if (!$code) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Kod oluşturulamadı']);
            exit;
        }
        
        $expiresAt = date('Y-m-d', time() + (REFERRAL_EXPIRY_DAYS * 24 * 3600));
        $shareUrl = 'http://' . $_SERVER['HTTP_HOST'] . BASE_URL . '?ref=' . urlencode($code);
        
        echo json_encode([
            'success' => true,
            'code' => $code,
            'reward_points' => REFERRAL_REWARD_POINTS,
            'expires_at' => $expiresAt,
            'share_url' => $shareUrl,
            'message' => 'Referral kodu başarıyla oluşturuldu',
        ]);
    } catch (Exception $e) {
        error_log('Referral kod oluşturma hatası: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Bir hata oluştu']);
    }
} elseif ($action === 'validate') {
    // Referral kodunu doğrula
    $code = $_GET['code'] ?? '';
    
    if (empty($code)) {
        http_response_code(400);
        echo json_encode(['valid' => false, 'message' => 'Kod gereklidir']);
        exit;
    }
    
    try {
        $referral = validate_referral_code($pdo, $code);
        
        if (!$referral) {
            echo json_encode(['valid' => false, 'message' => 'Geçersiz veya süresi dolmuş kod']);
            exit;
        }
        
        echo json_encode([
            'valid' => true,
            'referrer_name' => $referral['referrer_name'],
            'reward_points' => $referral['reward_amount'],
        ]);
    } catch (Exception $e) {
        error_log('Referral kod doğrulama hatası: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['valid' => false, 'message' => 'Bir hata oluştu']);
    }
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Geçersiz action parametresi']);
}
