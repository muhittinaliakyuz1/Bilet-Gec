<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

start_secure_session();
ensure_user_schema($pdo);

// If already logged in, redirect to home
if (is_logged_in()) {
    header('Location: /ilterhoca/');
    exit;
}

$errors = [];
$success_message = '';
$old = [
    'full_name' => '',
    'email' => '',
    'phone' => ''
];

// Process registration form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = 'Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.';
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';

        // Preserve old values
        $old['full_name'] = $full_name;
        $old['email'] = $email;
        $old['phone'] = $phone;

        // Validate full name
        if (empty($full_name)) {
            $errors[] = 'Ad soyad gereklidir.';
        } elseif (mb_strlen($full_name) < 2) {
            $errors[] = 'Ad soyad en az 2 karakter olmalıdır.';
        } elseif (mb_strlen($full_name) > 100) {
            $errors[] = 'Ad soyad en fazla 100 karakter olabilir.';
        }

        // Validate email
        if (empty($email)) {
            $errors[] = 'E-posta adresi gereklidir.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Geçerli bir e-posta adresi girin.';
        } else {
            // Check email uniqueness
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = 'Bu e-posta adresi zaten kayıtlı.';
            }
        }

        // Validate phone
        if (empty($phone)) {
            $errors[] = 'Telefon numarası gereklidir.';
        } elseif (!preg_match('/^[0-9\s\+\-\(\)]{10,15}$/', $phone)) {
            $errors[] = 'Geçerli bir telefon numarası girin.';
        }

        // Validate password
        if (empty($password)) {
            $errors[] = 'Şifre gereklidir.';
        } elseif (strlen($password) < 6) {
            $errors[] = 'Şifre en az 6 karakter olmalıdır.';
        }

        // Validate password confirmation
        if ($password !== $password_confirm) {
            $errors[] = 'Şifreler eşleşmiyor.';
        }

        // Attempt registration
        if (empty($errors)) {
            // Kullanıcıyı oluştur, verification_token alanını boş bırak (eski sistem)
            $user_id = register_user($pdo, $full_name, $email, $password, $phone, null);
            if ($user_id !== false) {
                // Yeni sistem: email_verification_log tablosuna token ekle
                $newToken = create_email_verification_token($pdo, $user_id, $email);
                if ($newToken) {
                    send_verification_email($email, $full_name, $newToken);
                    $success_message = 'Hesabınız oluşturuldu. Lütfen e-posta adresinize gönderilen doğrulama bağlantısını tıklayarak hesabınızı etkinleştirin.';
                } else {
                    $errors[] = 'Doğrulama tokeni oluşturulamadı. Lütfen daha sonra tekrar deneyin.';
                }
            } else {
                $errors[] = 'Kayıt oluşturulamadı. E-posta adresi zaten kullanımda olabilir.';
            }
        }
    }
}

$csrf_token = generate_csrf_token();

$page_title = "Kayıt Ol";
$current_page = "register";

require_once __DIR__ . '/../includes/header.php';
?>

<section class="auth-section">
    <div class="auth-container">
        <div class="auth-card glass-card">
            <div class="auth-header">
                <div class="auth-logo">🎫</div>
                <h1 class="auth-title">Bilet-Geç</h1>
                <p class="auth-subtitle">Yeni hesap oluşturun</p>
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
            <form method="POST" action="register.php" class="auth-form" id="register-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <div class="form-group">
                    <label for="full_name" class="form-label">👤 Ad Soyad</label>
                    <input type="text" id="full_name" name="full_name" class="form-input glass-input" 
                           placeholder="Adınız ve soyadınız" required autofocus
                           value="<?php echo htmlspecialchars($old['full_name']); ?>">
                    <span class="form-error" id="error-full_name"></span>
                </div>

                <div class="form-group">
                    <label for="email" class="form-label">📧 E-posta</label>
                    <input type="email" id="email" name="email" class="form-input glass-input" 
                           placeholder="ornek@email.com" required
                           value="<?php echo htmlspecialchars($old['email']); ?>">
                    <span class="form-error" id="error-email"></span>
                </div>

                <div class="form-group">
                    <label for="phone" class="form-label">📱 Telefon</label>
                    <input type="tel" id="phone" name="phone" class="form-input glass-input" 
                           placeholder="0555 555 5555" required
                           value="<?php echo htmlspecialchars($old['phone']); ?>">
                    <span class="form-error" id="error-phone"></span>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">🔒 Şifre</label>
                    <input type="password" id="password" name="password" class="form-input glass-input" 
                           placeholder="En az 6 karakter" required>
                    <span class="form-error" id="error-password"></span>
                </div>

                <div class="form-group">
                    <label for="password_confirm" class="form-label">🔒 Şifre Tekrar</label>
                    <input type="password" id="password_confirm" name="password_confirm" class="form-input glass-input" 
                           placeholder="Şifrenizi tekrar girin" required>
                    <span class="form-error" id="error-password_confirm"></span>
                </div>

                <button type="submit" class="btn btn-primary btn-block auth-submit">
                    Kayıt Ol
                </button>
            </form>
            <?php endif; ?>

            <div class="auth-footer">
                <p>Zaten hesabın var mı? <a href="login.php" class="auth-link">Giriş Yap</a></p>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('register-form');

    function showError(fieldId, message) {
        const errorEl = document.getElementById('error-' + fieldId);
        const inputEl = document.getElementById(fieldId);
        if (errorEl) errorEl.textContent = message;
        if (inputEl) inputEl.classList.add('input-error');
    }

    function clearError(fieldId) {
        const errorEl = document.getElementById('error-' + fieldId);
        const inputEl = document.getElementById(fieldId);
        if (errorEl) errorEl.textContent = '';
        if (inputEl) inputEl.classList.remove('input-error');
    }

    function clearAllErrors() {
        ['full_name', 'email', 'phone', 'password', 'password_confirm'].forEach(clearError);
    }

    // Real-time validation on blur
    document.getElementById('full_name').addEventListener('blur', function() {
        clearError('full_name');
        if (this.value.trim().length === 0) {
            showError('full_name', 'Ad soyad gereklidir.');
        } else if (this.value.trim().length < 2) {
            showError('full_name', 'Ad soyad en az 2 karakter olmalıdır.');
        }
    });

    document.getElementById('email').addEventListener('blur', function() {
        clearError('email');
        const email = this.value.trim();
        if (email.length === 0) {
            showError('email', 'E-posta adresi gereklidir.');
        } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showError('email', 'Geçerli bir e-posta adresi girin.');
        }
    });

    document.getElementById('phone').addEventListener('blur', function() {
        clearError('phone');
        const phone = this.value.trim();
        if (phone.length === 0) {
            showError('phone', 'Telefon numarası gereklidir.');
        } else if (!/^[0-9\s\+\-\(\)]{10,15}$/.test(phone)) {
            showError('phone', 'Geçerli bir telefon numarası girin.');
        }
    });

    document.getElementById('password').addEventListener('blur', function() {
        clearError('password');
        if (this.value.length === 0) {
            showError('password', 'Şifre gereklidir.');
        } else if (this.value.length < 6) {
            showError('password', 'Şifre en az 6 karakter olmalıdır.');
        }
    });

    document.getElementById('password_confirm').addEventListener('blur', function() {
        clearError('password_confirm');
        const pwd = document.getElementById('password').value;
        if (this.value.length === 0) {
            showError('password_confirm', 'Şifre tekrarı gereklidir.');
        } else if (this.value !== pwd) {
            showError('password_confirm', 'Şifreler eşleşmiyor.');
        }
    });

    // Form submission validation
    form.addEventListener('submit', function(e) {
        clearAllErrors();
        let hasError = false;

        const fullName = document.getElementById('full_name').value.trim();
        const email = document.getElementById('email').value.trim();
        const phone = document.getElementById('phone').value.trim();
        const password = document.getElementById('password').value;
        const passwordConfirm = document.getElementById('password_confirm').value;

        if (fullName.length === 0) {
            showError('full_name', 'Ad soyad gereklidir.');
            hasError = true;
        } else if (fullName.length < 2) {
            showError('full_name', 'Ad soyad en az 2 karakter olmalıdır.');
            hasError = true;
        }

        if (email.length === 0) {
            showError('email', 'E-posta adresi gereklidir.');
            hasError = true;
        } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showError('email', 'Geçerli bir e-posta adresi girin.');
            hasError = true;
        }

        if (phone.length === 0) {
            showError('phone', 'Telefon numarası gereklidir.');
            hasError = true;
        } else if (!/^[0-9\s\+\-\(\)]{10,15}$/.test(phone)) {
            showError('phone', 'Geçerli bir telefon numarası girin.');
            hasError = true;
        }

        if (password.length === 0) {
            showError('password', 'Şifre gereklidir.');
            hasError = true;
        } else if (password.length < 6) {
            showError('password', 'Şifre en az 6 karakter olmalıdır.');
            hasError = true;
        }

        if (passwordConfirm.length === 0) {
            showError('password_confirm', 'Şifre tekrarı gereklidir.');
            hasError = true;
        } else if (password !== passwordConfirm) {
            showError('password_confirm', 'Şifreler eşleşmiyor.');
            hasError = true;
        }

        if (hasError) {
            e.preventDefault();
        }
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
