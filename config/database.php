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
function load_dotenv(string $filePath): array
{
    if (!file_exists($filePath)) {
        return [];
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $vars = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strncmp($line, '#', 1) === 0) {
            continue;
        }

        $parts = explode('=', $line, 2);
        $name = trim($parts[0]);
        $value = isset($parts[1]) ? trim($parts[1]) : '';
        if ($name === '') {
            continue;
        }

        $vars[$name] = preg_replace('/^(["\'])(.*)\1$/', '$2', $value);
    }

    return $vars;
}

function bg_env(string $key, $default = null)
{
    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }

    if (isset($_ENV[$key])) {
        return $_ENV[$key];
    }

    static $dotenv;
    if ($dotenv === null) {
        $dotenv = load_dotenv(__DIR__ . '/../.env');
    }

    return $dotenv[$key] ?? $default;
}

define('BASE_URL', bg_env('BASE_URL', '/ilterhoca/'));
define('SITE_NAME', 'Bilet-Geç');

// Veritabanı yapılandırması
$db_host = bg_env('DB_HOST', 'localhost');
$db_name = bg_env('DB_NAME', 'biletgec');
$db_user = bg_env('DB_USER', 'root');
$db_pass = bg_env('DB_PASS', '');
$db_charset = bg_env('DB_CHARSET', 'utf8mb4');

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
