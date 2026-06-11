<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

start_secure_session();
ensure_user_schema($pdo);

// Hata ve eski e‑posta değerlerini tutacak değişkenler
$errors = [];
$old_email = '';

// If already logged in, redirect to home
if (is_logged_in()) {
    header('Location: /ilterhoca/');
    exit;
}

// Process POST (login or resend verification)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Tek seferlik CSRF kontrolü
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = 'Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.';
    } else {
        // Doğrulama e‑postasını tekrar gönderme isteği
        if (isset($_POST['resend_verification'])) {
            $email = trim($_POST['email'] ?? '');
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Geçerli bir e-posta adresi girin.';
            } else {
                $stmt = $pdo->prepare('SELECT id, full_name FROM users WHERE email = ?');
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                if ($user) {
                    $newToken = create_email_verification_token($pdo, (int)$user['id'], $email);
                    if ($newToken) {
                        if (send_verification_email($email, $user['full_name'], $newToken)) {
                            $errors[] = 'Doğrulama e-postası tekrar gönderildi. Lütfen e-posta kutunuzu kontrol edin.';
                        } else {
                            $errors[] = 'Doğrulama e-postası gönderilemedi. Lütfen daha sonra tekrar deneyin.';
                        }
                    } else {
                        $errors[] = 'Doğrulama tokeni oluşturulamadı. Lütfen daha sonra tekrar deneyin.';
                    }
                } else {
                    $errors[] = 'Bu e-posta adresi sistemde bulunamadı.';
                }
            }
        } else {
            // Normal giriş işlemi
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $old_email = $email;

            // Validate inputs
            if (empty($email)) {
                $errors[] = 'E-posta adresi gereklidir.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Geçerli bir e-posta adresi girin.';
            }

            if (empty($password)) {
                $errors[] = 'Şifre gereklidir.';
            }

            // Attempt login
            if (empty($errors)) {
                $loginError = null;
                $success = login_user($pdo, $email, $password, $loginError);
                if ($success) {
                    // Check for return URL (already stored in $return_url)
                    if (!empty($return_url) && strpos($return_url, '/ilterhoca/') === false) {
                        $return_url = '/ilterhoca/' . ltrim($return_url, '/');
                    }
                    if (empty($return_url)) {
                        $return_url = '/ilterhoca/';
                    }
                    header('Location: ' . $return_url);
                    exit;
                } else {
                    $errors[] = $loginError ?? 'E-posta veya şifre hatalı.';
                }
            }
        }
    }
}

// CSRF token ve return URL sadece form gösterilirken oluşturulur
$return_url = $_GET['return'] ?? '';

$csrf_token = generate_csrf_token();

$page_title = "Giriş Yap";
$current_page = "login";

require_once __DIR__ . '/includes/header.php';
?>

<section class="auth-section">
    <div class="auth-container">
        <div class="auth-card glass-card">
            <div class="auth-header">
                <div class="auth-logo">🎫</div>
                <h1 class="auth-title">Bilet-Geç</h1>
                <p class="auth-subtitle">Hesabınıza giriş yapın</p>
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

            <form method="POST" action="login.php<?php echo !empty($return_url) ? '?return=' . urlencode($return_url) : ''; ?>" class="auth-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <?php if (!empty($return_url)): ?>
                <input type="hidden" name="return" value="<?php echo htmlspecialchars($return_url); ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="email" class="form-label">📧 E-posta</label>
                    <input type="email" id="email" name="email" class="form-input glass-input" 
                           placeholder="ornek@email.com" required autofocus
                           value="<?php echo htmlspecialchars($old_email); ?>">
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">🔒 Şifre</label>
                    <input type="password" id="password" name="password" class="form-input glass-input" 
                           placeholder="Şifrenizi girin" required>
                </div>

                <button type="submit" class="btn btn-primary btn-block auth-submit">
                    Giriş Yap
                </button>

                <?php
                // Eğer son hata e-posta doğrulanmamışsa, yeniden gönderme butonu göster
                $show_resend = false;
                foreach ($errors as $e) {
                    if (strpos($e, 'doğrulanmamış') !== false) {
                        $show_resend = true;
                        break;
                    }
                }
                if ($show_resend): ?>
                <button type="submit" name="resend_verification" value="1" class="btn btn-secondary btn-block auth-submit" style="margin-top:10px;">
                    Doğrulama E-postasını Tekrar Gönder
                </button>
                <?php endif; ?>
            </form>

            <div class="auth-footer">
                <p><a href="forgot_password.php" class="auth-link">Şifremi unuttum</a></p>
                <p>Hesabın yok mu? <a href="register.php" class="auth-link">Kayıt Ol</a></p>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
