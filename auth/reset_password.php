<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

start_secure_session();
ensure_user_schema($pdo);

$errors = [];
$success_message = '';
$password = '';
$password_confirm = '';
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$user = false;

if (!empty($token)) {
    $user = get_user_by_reset_token($pdo, $token);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = 'Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.';
    } else {
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';

        if (empty($token) || !$user) {
            $errors[] = 'Geçersiz veya süresi dolmuş şifre sıfırlama bağlantısı.';
        }

        if (empty($password)) {
            $errors[] = 'Yeni şifre gereklidir.';
        } elseif (strlen($password) < 6) {
            $errors[] = 'Şifre en az 6 karakter olmalıdır.';
        }

        if ($password !== $password_confirm) {
            $errors[] = 'Şifreler eşleşmiyor.';
        }

        if (empty($errors)) {
            if (complete_password_reset($pdo, $token, $password)) {
                $success_message = 'Şifreniz başarıyla güncellendi. Artık <a href="login.php">giriş yapabilirsiniz</a>.';
                $user = false;
            } else {
                $errors[] = 'Şifre sıfırlama bağlantısı geçersiz veya süresi dolmuş.';
            }
        }
    }
}

$csrf_token = generate_csrf_token();

$page_title = 'Şifre Sıfırla';
$current_page = 'reset_password';

require_once __DIR__ . '/../includes/header.php';
?>

<section class="auth-section">
    <div class="auth-container">
        <div class="auth-card glass-card">
            <div class="auth-header">
                <div class="auth-logo">🔑</div>
                <h1 class="auth-title">Şifre Sıfırla</h1>
                <p class="auth-subtitle">Yeni şifrenizi belirleyin.</p>
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
                <?php echo $success_message; ?>
            </div>
            <?php elseif (!$token || !$user): ?>
            <div class="alert alert-error">
                Geçersiz veya süresi dolmuş şifre sıfırlama bağlantısı.
            </div>
            <?php else: ?>
            <form method="POST" action="reset_password.php" class="auth-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                <div class="form-group">
                    <label for="password" class="form-label">🔒 Yeni Şifre</label>
                    <input type="password" id="password" name="password" class="form-input glass-input" placeholder="Yeni şifrenizi girin" required autofocus>
                </div>

                <div class="form-group">
                    <label for="password_confirm" class="form-label">🔒 Yeni Şifre Tekrar</label>
                    <input type="password" id="password_confirm" name="password_confirm" class="form-input glass-input" placeholder="Şifrenizi tekrar girin" required>
                </div>

                <button type="submit" class="btn btn-primary btn-block auth-submit">Şifreyi Yenile</button>
            </form>
            <?php endif; ?>

            <div class="auth-footer">
                <p><a href="login.php" class="auth-link">Giriş sayfasına dön</a></p>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
