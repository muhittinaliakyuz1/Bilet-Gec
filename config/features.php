<?php
/**
 * Bilet-Geç - Yeni Özellikler Yapılandırması
 * Tüm premium özellikler için ayarlar ve konfigürasyon
 */

if (!defined('ALLOWED_ACCESS')) {
    die('Doğrudan erişim yasaktır.');
}

// ════════════════════════════════════════════════════════════════════════════════
// CACHE YAPIŞLANDIIRMASI
// ════════════════════════════════════════════════════════════════════════════════

// Cache TTL (Time To Live) - saniye cinsinden
define('CACHE_EVENTS_LIST_TTL', 3600);      // 1 saat
define('CACHE_CATEGORIES_TTL', 86400);      // 24 saat
define('CACHE_PRICE_RANGE_TTL', 3600);      // 1 saat
define('CACHE_EVENT_DETAIL_TTL', 1800);     // 30 dakika

// ════════════════════════════════════════════════════════════════════════════════
// SADAKAT PUANI YAPIŞLANDIIRMASI
// ════════════════════════════════════════════════════════════════════════════════

// Her 1 TL'lik satın almada kazanılan puan (0.01 = %1)
define('LOYALTY_POINTS_PER_AMOUNT', 1);

// Puan seviyeleri ve avantajları
$LOYALTY_TIERS = [
    'bronze'   => ['min_points' => 0,     'discount_percent' => 0,    'name' => 'Bronz'],
    'silver'   => ['min_points' => 500,   'discount_percent' => 2,    'name' => 'Gümüş'],
    'gold'     => ['min_points' => 1000,  'discount_percent' => 5,    'name' => 'Altın'],
    'platinum' => ['min_points' => 2000,  'discount_percent' => 10,   'name' => 'Platin'],
];

// ════════════════════════════════════════════════════════════════════════════════
// REFERRAL SİSTEMİ YAPIŞLANDIIRMASI
// ════════════════════════════════════════════════════════════════════════════════

// Referral ödülü (puan)
define('REFERRAL_REWARD_POINTS', 100);

// Referral kodunun geçerlilik süresi (gün)
define('REFERRAL_EXPIRY_DAYS', 90);

// Maksimum referral kullanımı (NULL = sınırsız)
define('REFERRAL_MAX_USAGE', null);

// ════════════════════════════════════════════════════════════════════════════════
// KUPON YAPIŞLANDIIRMASI
// ════════════════════════════════════════════════════════════════════════════════

// Kupon kodunun max uzunluğu
define('COUPON_MAX_LENGTH', 20);

// Varsayılan olarak kupon başına max kullanım sayısı
define('COUPON_DEFAULT_PER_USER_LIMIT', 1);

// ════════════════════════════════════════════════════════════════════════════════
// E-POSTA DOĞRULAMA YAPIŞLANDIIRMASI
// ════════════════════════════════════════════════════════════════════════════════

// Doğrulama e-postası geçerliliği (saat)
define('EMAIL_VERIFICATION_HOURS', 24);

// Yeniden gönderme arasında minimum bekleme süresi (saniye)
define('EMAIL_RESEND_COOLDOWN', 300); // 5 dakika

// ════════════════════════════════════════════════════════════════════════════════
// ARAMA VE FİLTRELEME YAPIŞLANDIIRMASI
// ════════════════════════════════════════════════════════════════════════════════

// Varsayılan sayfa başına etkinlik sayısı
define('EVENTS_PER_PAGE', 12);

// Maksimum arama sonucu
define('MAX_SEARCH_RESULTS', 100);

// Etkinlik dinamik fiyat filtreleme etkin mi?
define('ENABLE_PRICE_FILTER', true);

// ════════════════════════════════════════════════════════════════════════════════
// VERİ TABANSI OPTİMİZASYONU YAPIŞLANDIIRMASI
// ════════════════════════════════════════════════════════════════════════════════

// Bulk işlemler için chunk boyutu
define('DB_BULK_CHUNK_SIZE', 1000);

// Slow query logging
define('ENABLE_SLOW_QUERY_LOG', false);
define('SLOW_QUERY_THRESHOLD', 1.0); // saniye
