<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

start_secure_session();
ensure_user_schema($pdo);

$token = trim($_GET['token'] ?? '');
$message = '';
$messageClass = 'alert-error';

$verified = false;
if (!empty($token)) {
    // Öncelikle yeni email_verification_log tablosu üzerinden doğrulama dene
    $verified = verify_email_token($pdo, $token);
    if (!$verified) {
        // Eski sistemde users.verification_token alanı varsa ona bak
        $stmt = $pdo->prepare('SELECT id FROM users WHERE verification_token = ? AND email_verified = 0');
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        if ($user) {
            $pdo->prepare('UPDATE users SET email_verified = 1, verification_token = NULL, verified_at = NOW() WHERE id = ?')
                ->execute([$user['id']]);
            $verified = true;
        }
    }
}

if ($verified) {
    $message = 'E-posta adresiniz başarıyla doğrulandı. Artık giriş yapabilirsiniz.';
    $messageClass = 'alert-success';
} else {
    $message = 'Doğrulama bağlantısı geçersiz veya süresi dolmuş. Lütfen kayıt işlemini tekrar deneyin.';
}

$page_title = 'E-posta Doğrulama';
$current_page = 'verify_email';

require_once __DIR__ . '/includes/header.php';
?>

<section class="auth-section">
    <div class="auth-container">
        <div class="auth-card glass-card">
            <div class="auth-header">
                <div class="auth-logo">✅</div>
                <h1 class="auth-title">E-posta Doğrulama</h1>
                <p class="auth-subtitle">Hesabınızı aktifleştirme bilgisi.</p>
            </div>

            <div class="alert <?php echo $messageClass; ?>">
                <?php echo htmlspecialchars($message); ?>
                <?php if ($messageClass === 'alert-success'): ?>
                    <p><a href="login.php" class="auth-link">Giriş yapmaya git</a></p>
                <?php else: ?>
                    <p><a href="register.php" class="auth-link">Tekrar kayıt olmayı deneyin</a></p>
                <?php endif; ?>
            </div>

            <div class="auth-footer">
                <p><a href="login.php" class="auth-link">Giriş sayfasına dön</a></p>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>