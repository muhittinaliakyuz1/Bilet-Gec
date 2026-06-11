<?php
/**
 * Bilet-Geç - Veritabanı Bağlantı Yapılandırması
 * PDO ile MySQL bağlantısı
 */

// Doğrudan erişimi engelle
if (!defined('ALLOWED_ACCESS')) {
    die('Doğrudan erişim yasaktır.');
}

// Site sabitleri
define('BASE_URL', '/ilterhoca/');
define('SITE_NAME', 'Bilet-Geç');

// Veritabanı yapılandırması
$db_host = 'localhost';
$db_name = 'biletgec';
$db_user = 'root';
$db_pass = '';
$db_charset = 'utf8mb4';

$dsn = "mysql:host={$db_host};dbname={$db_name};charset={$db_charset}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (PDOException $e) {
    error_log('Veritabanı bağlantı hatası: ' . $e->getMessage());
    die('Veritabanı bağlantısı kurulamadı. Lütfen daha sonra tekrar deneyin.');
}
