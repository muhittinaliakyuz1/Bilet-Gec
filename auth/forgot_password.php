<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

start_secure_session();
ensure_user_schema($pdo);

$errors = [];
$success_message = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = 'Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.';
    } else {
        $email = trim($_POST['email'] ?? '');

        if (empty($email)) {
            $errors[] = 'E-posta adresi gereklidir.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Geçerli bir e-posta adresi girin.';
        }

        if (empty($errors)) {
            $token = bin2hex(random_bytes(32));
            if (create_password_reset_token($pdo, $email, $token)) {
                send_password_reset_email($email, $token);
            }
            $success_message = 'Eğer bu e-posta adresi sistemde kayıtlıysa, şifre sıfırlama bağlantısı gönderildi.';
        }
    }
}

$csrf_token = generate_csrf_token();

$page_title = 'Şifremi Unuttum';
$current_page = 'forgot_password';

require_once __DIR__ . '/../includes/header.php';
?>

<section class="auth-section">
    <div class="auth-container">
        <div class="auth-card glass-card">
            <div class="auth-header">
                <div class="auth-logo">🔒</div>
                <h1 class="auth-title">Şifremi Unuttum</h1>
                <p class="auth-subtitle">E-posta adresinize şifre sıfırlama bağlantısı gönderelim.</p>
            </div>

            <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
            <?php else: ?>
            <form method="POST" action="forgot_password.php" class="auth-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <div class="form-group">
                    <label for="email" class="form-label">📧 Kayıtlı e-posta</label>
                    <input type="email" id="email" name="email" class="form-input glass-input" placeholder="ornek@email.com" required autofocus value="<?php echo htmlspecialchars($email); ?>">
                </div>

                <button type="submit" class="btn btn-primary btn-block auth-submit">Şifre Sıfırlama Bağlantısı Gönder</button>
            </form>
            <?php endif; ?>

            <div class="auth-footer">
                <p><a href="login.php" class="auth-link">Giriş sayfasına dön</a></p>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
