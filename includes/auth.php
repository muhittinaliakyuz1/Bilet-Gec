<?php
/**
 * Bilet-Geç - Kimlik Doğrulama Yardımcı Fonksiyonları
 * Oturum yönetimi, kayıt, giriş, CSRF koruması
 */

if (!defined('ALLOWED_ACCESS')) {
    die('Doğrudan erişim yasaktır.');
}

require_once __DIR__ . '/../config/database.php';

/**
 * Güvenli oturum başlat
 * HttpOnly, SameSite=Lax ayarlarıyla session cookie yapılandırması
 */
function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_URL,
        'domain'   => '',
        'secure'   => false,
        'httponly'  => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/**
 * Yeni kullanıcı kaydı
 *
 * @param PDO    $pdo      Veritabanı bağlantısı
 * @param string $name     Tam ad
 * @param string $email    E-posta adresi
 * @param string $password Şifre (düz metin)
 * @param string $phone    Telefon numarası
 * @return int|false       Başarılıysa kullanıcı ID, değilse false
 */
function register_user(PDO $pdo, string $name, string $email, string $password, string $phone, string $verification_token = null): int|false
{
    // E-posta benzersizlik kontrolü
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);

    if ($stmt->fetch()) {
        return false; // E-posta zaten kayıtlı
    }

    $password_hash = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare(
        'INSERT INTO users (full_name, email, password_hash, phone, role, email_verified, verification_token, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$name, $email, $password_hash, $phone, 'user', 0, $verification_token]);

    return (int) $pdo->lastInsertId();
}

/**
 * Kullanıcı girişi
 *
 * @param PDO    $pdo      Veritabanı bağlantısı
 * @param string $email    E-posta adresi
 * @param string $password Şifre (düz metin)
 * @param string|null &$error Hata mesajı dönüşü
 * @return bool            Giriş başarılı mı
 */
function login_user(PDO $pdo, string $email, string $password, ?string &$error = null): bool
{
    $stmt = $pdo->prepare('SELECT id, full_name, email, password_hash, phone, role, email_verified FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        $error = 'E-posta veya şifre hatalı.';
        return false;
    }

    if (!password_verify($password, $user['password_hash'])) {
        $error = 'E-posta veya şifre hatalı.';
        return false;
    }

    if ((int)$user['email_verified'] !== 1) {
        $error = 'E-posta adresiniz doğrulanmamış. Lütfen gelen doğrulama bağlantısını kullanın.';
        return false;
    }

    // Oturum sabitleme saldırılarını önle
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id'        => $user['id'],
        'full_name' => $user['full_name'],
        'email'     => $user['email'],
        'phone'     => $user['phone'],
        'role'      => $user['role'],
    ];

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];

    return true;
}

/**
 * Kullanıcıyı ID ile getir
 *
 * @param PDO $pdo
 * @param int $user_id
 * @return array|false
 */
function get_user_by_id(PDO $pdo, int $user_id): array|false
{
    $stmt = $pdo->prepare('SELECT id, full_name, email, phone, role, email_verified FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

/**
 * Kullanıcı profilini güncelle
 *
 * @param PDO $pdo
 * @param int $user_id
 * @param string $full_name
 * @param string $email
 * @param string $phone
 * @return bool
 */
function update_user_profile(PDO $pdo, int $user_id, string $full_name, string $email, string $phone): bool
{
    $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $current = $stmt->fetch();
    if (!$current) {
        return false;
    }

    $emailChanged = $email !== $current['email'];

    if ($emailChanged) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $stmt->execute([$email, $user_id]);
        if ($stmt->fetch()) {
            return false;
        }

        $stmt = $pdo->prepare(
            'UPDATE users
             SET full_name = ?, email = ?, phone = ?, email_verified = 0, verified_at = NULL
             WHERE id = ?'
        );
        return $stmt->execute([$full_name, $email, $phone, $user_id]);
    }

    $stmt = $pdo->prepare(
        'UPDATE users
         SET full_name = ?, phone = ?
         WHERE id = ?'
    );
    return $stmt->execute([$full_name, $phone, $user_id]);
}

/**
 * Kullanıcı şifresini güncelle
 *
 * @param PDO $pdo
 * @param int $user_id
 * @param string $new_password
 * @return bool
 */
function update_user_password(PDO $pdo, int $user_id, string $new_password): bool
{
    $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    return $stmt->execute([$password_hash, $user_id]);
}

/**
 * Kullanıcı çıkışı - oturumu tamamen yok et
 */
function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

/**
 * Kullanıcı giriş yapmış mı kontrol et
 */
function is_logged_in(): bool
{
    return isset($_SESSION['user']['id']);
}

/**
 * Kullanıcı süperadmin mi kontrol et
 */
function is_superadmin(): bool
{
    return isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'superadmin';
}

/**
 * Kullanıcı firma mı kontrol et
 */
function is_firma(): bool
{
    return isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'firma';
}

/**
 * Panel erişimi olan kullanıcı mı (firma veya süperadmin)
 */
function is_panel_user(): bool
{
    return is_superadmin() || is_firma();
}

/**
 * @deprecated is_firma() kullanın
 */
function is_admin(): bool
{
    return is_firma();
}

/**
 * Geçerli oturumdaki kullanıcı bilgilerini döndür
 *
 * @return array|null Kullanıcı verisi veya null
 */
function get_logged_in_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * CSRF token üret ve oturuma kaydet
 *
 * @return string Üretilen token
 */
function generate_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * CSRF token doğrula
 *
 * @param string $token Doğrulanacak token
 * @return bool         Token geçerli mi
 */
function verify_csrf_token(string $token): bool
{
    if (empty($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Giriş yapılmamışsa login sayfasına yönlendir
 */
function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ' . BASE_URL . 'auth/login.php');
        exit;
    }
}

/**
 * Panel kullanıcısı değilse ana sayfaya yönlendir
 */
function require_panel(): void
{
    if (!is_panel_user()) {
        header('Location: ' . BASE_URL . 'index.php');
        exit;
    }
}

/**
 * @deprecated require_panel() kullanın
 */
function require_admin(): void
{
    require_panel();
}

/**
 * Süperadmin değilse panel ana sayfasına yönlendir
 */
function require_superadmin(): void
{
    if (!is_superadmin()) {
        header('Location: ' . BASE_URL . 'admin/');
        exit;
    }
}

/**
 * Firma kullanıcısının bir etkinliğe erişim yetkisi var mı
 */
function can_manage_event(array $event, ?int $user_id = null): bool
{
    if (is_superadmin()) {
        return true;
    }
    $user_id = $user_id ?? get_current_user_id();
    if (!$user_id) {
        return false;
    }
    $created_by = $event['created_by'] ?? null;
    return $created_by === null || (int)$created_by === (int)$user_id;
}

/**
 * API istekleri için giriş kontrolü (JSON döner)
 */
function require_login_api(): void
{
    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Lütfen bilet almak için giriş yapın.']);
        exit;
    }
}

/**
 * Giriş yapan kullanıcının ID'sini döndür
 */
function get_current_user_id(): ?int
{
    return $_SESSION['user']['id'] ?? null;
}

// Oturumu dosya include edildiğinde başlat
start_secure_session();
