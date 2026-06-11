<?php
/**
 * Bilet-Geç - Etkinlik Arama API
 * GET /api/search_events.php
 * 
 * Query Parameters:
 * - q (string): Arama terimi
 * - category_id (int): Kategori ID
 * - city (string): Şehir
 * - price_from (float): Minimum fiyat
 * - price_to (float): Maksimum fiyat
 * - date_from (string): Başlangıç tarihi (YYYY-MM-DD)
 * - date_to (string): Bitiş tarihi (YYYY-MM-DD)
 * - sort_by (string): Sıralama (date_asc, date_desc, price_asc, price_desc, popularity)
 * - page (int): Sayfa numarası
 * - limit (int): Sayfa başına etkinlik sayısı
 * 
 * Response:
 * {
 *   "success": true|false,
 *   "events": [...],
 *   "total": 50,
 *   "page": 1,
 *   "per_page": 12,
 *   "price_range": {"min": 50, "max": 500}
 * }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/features.php';

try {
    // Parametreleri al
    $search = $_GET['q'] ?? '';
    $category_id = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null;
    $city = $_GET['city'] ?? '';
    $price_from = !empty($_GET['price_from']) ? (float)$_GET['price_from'] : null;
    $price_to = !empty($_GET['price_to']) ? (float)$_GET['price_to'] : null;
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $sort_by = $_GET['sort_by'] ?? 'date_asc';
    $page = !empty($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = !empty($_GET['limit']) ? min((int)$_GET['limit'], MAX_SEARCH_RESULTS) : EVENTS_PER_PAGE;
    
    // Sayfalama hesapla
    $offset = ($page - 1) * $limit;
    
    // Filtre dizisini oluştur
    $filters = [];
    if (!empty($search)) $filters['search'] = $search;
    if ($category_id) $filters['category_id'] = $category_id;
    if (!empty($city)) $filters['city'] = $city;
    if ($price_from !== null && ENABLE_PRICE_FILTER) $filters['price_from'] = $price_from;
    if ($price_to !== null && ENABLE_PRICE_FILTER) $filters['price_to'] = $price_to;
    if (!empty($date_from)) $filters['date_from'] = $date_from;
    if (!empty($date_to)) $filters['date_to'] = $date_to;
    $filters['sort_by'] = $sort_by;
    $filters['available_only'] = true;
    $filters['limit'] = $limit;
    $filters['offset'] = $offset;
    
    // Etkinlikleri ara
    $events = search_events($pdo, $filters);
    
    // Toplam sayıyı al
    $countSql = 'SELECT COUNT(*) as total FROM events WHERE status = :status';
    $countParams = ['status' => 'active'];
    
    if (!empty($search)) {
        $countSql .= ' AND (title LIKE :search OR description LIKE :search2 OR venue LIKE :search3)';
        $searchTerm = '%' . $search . '%';
        $countParams['search'] = $searchTerm;
        $countParams['search2'] = $searchTerm;
        $countParams['search3'] = $searchTerm;
    }
    
    if ($category_id) {
        $countSql .= ' AND category_id = :category_id';
        $countParams['category_id'] = $category_id;
    }
    
    if (!empty($city)) {
        $countSql .= ' AND city = :city';
        $countParams['city'] = $city;
    }
    
    if ($price_from !== null && ENABLE_PRICE_FILTER) {
        $countSql .= ' AND price >= :price_from';
        $countParams['price_from'] = $price_from;
    }
    
    if ($price_to !== null && ENABLE_PRICE_FILTER) {
        $countSql .= ' AND price <= :price_to';
        $countParams['price_to'] = $price_to;
    }
    
    if (!empty($date_from)) {
        $countSql .= ' AND event_date >= :date_from';
        $countParams['date_from'] = $date_from . ' 00:00:00';
    }
    
    if (!empty($date_to)) {
        $countSql .= ' AND event_date <= :date_to';
        $countParams['date_to'] = $date_to . ' 23:59:59';
    }
    
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($countParams);
    $totalResult = $stmt->fetch();
    $total = (int)$totalResult['total'];
    
    // Fiyat aralığını al
    $priceRange = get_event_price_range($pdo);
    
    echo json_encode([
        'success' => true,
        'events' => $events,
        'total' => $total,
        'page' => $page,
        'per_page' => $limit,
        'total_pages' => ceil($total / $limit),
        'price_range' => $priceRange,
    ]);
} catch (Exception $e) {
    error_log('Etkinlik arama API hatası: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Arama işlemi başarısız oldu',
    ]);
}
