<?php
/**
 * Bilet-Geç - Kullanıcı Sadakat Puanları API
 * GET /api/user_loyalty_points.php
 * 
 * Response:
 * {
 *   "success": true|false,
 *   "points": 250,
 *   "tier": "silver",
 *   "tier_name": "Gümüş",
 *   "discount_percent": 2,
 *   "next_tier": "gold",
 *   "points_to_next_tier": 750,
 *   "transactions": [...]
 * }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/features.php';

start_secure_session();

// Sadece oturum açan kullanıcılar
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Oturum açmanız gerekiyor']);
    exit;
}

$user_id = $_SESSION['user']['id'];

try {
    // Sadakat puanlarını al
    $points = get_user_loyalty_points($pdo, $user_id);
    
    // Mevcut tier'i belirle
    global $LOYALTY_TIERS;
    $current_tier = null;
    $current_tier_name = null;
    $discount_percent = 0;
    $next_tier = null;
    $next_tier_min_points = 0;
    
    foreach ($LOYALTY_TIERS as $tier_key => $tier_info) {
        if ($points >= $tier_info['min_points']) {
            $current_tier = $tier_key;
            $current_tier_name = $tier_info['name'];
            $discount_percent = $tier_info['discount_percent'];
        }
    }
    
    // Sonraki tier'i bul
    foreach ($LOYALTY_TIERS as $tier_key => $tier_info) {
        if ($tier_info['min_points'] > $points) {
            $next_tier = $tier_key;
            $next_tier_min_points = $tier_info['min_points'];
            break;
        }
    }
    
    $points_to_next_tier = $next_tier ? max(0, $next_tier_min_points - $points) : null;
    
    // Son işlemleri al
    $stmt = $pdo->prepare(
        'SELECT description, amount, transaction_type, created_at 
         FROM point_transactions 
         WHERE user_id = ? 
         ORDER BY created_at DESC 
         LIMIT 10'
    );
    $stmt->execute([$user_id]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'points' => $points,
        'tier' => $current_tier,
        'tier_name' => $current_tier_name,
        'discount_percent' => $discount_percent,
        'next_tier' => $next_tier,
        'next_tier_name' => $next_tier ? $LOYALTY_TIERS[$next_tier]['name'] : null,
        'points_to_next_tier' => $points_to_next_tier,
        'recent_transactions' => $transactions,
    ]);
} catch (Exception $e) {
    error_log('Loyalty points API hatası: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Bir hata oluştu']);
}
