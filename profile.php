<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

start_secure_session();
ensure_user_schema($pdo);
require_login();

$user_id = (int) ($_SESSION['user']['id'] ?? 0);
$user = get_user_by_id($pdo, $user_id);
if (!$user) {
    header('Location: /ilterhoca/logout.php');
    exit;
}

$errors = [];
$success_profile = '';
$success_password = '';
$warning_profile = '';

$current_password = '';
$new_password = '';
$new_password_confirm = '';
$profile_data = [
    'full_name' => $user['full_name'],
    'email'     => $user['email'],
    'phone'     => $user['phone'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = 'Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_profile') {
            $profile_data['full_name'] = trim($_POST['full_name'] ?? '');
            $profile_data['email'] = trim($_POST['email'] ?? '');
            $profile_data['phone'] = trim($_POST['phone'] ?? '');
            $current_password = $_POST['current_password'] ?? ''; 

            if (empty($profile_data['full_name'])) {
                $errors[] = 'Ad soyad alanı boş bırakılamaz.';
            } elseif (mb_strlen($profile_data['full_name']) < 2) {
                $errors[] = 'Ad soyad en az 2 karakter olmalıdır.';
            }

            if (empty($profile_data['email']) || !filter_var($profile_data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Geçerli bir e-posta adresi girin.';
            }

            if (empty($profile_data['phone'])) {
                $errors[] = 'Telefon numarası gereklidir.';
            }

            if (empty($current_password)) {
                $errors[] = 'Değişiklikleri kaydetmek için mevcut şifrenizi girin.';
            }

            $emailChanged = $profile_data['email'] !== $user['email'];
            if ($emailChanged) {
                $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
                $stmt->execute([$profile_data['email'], $user_id]);
                if ($stmt->fetch()) {
                    $errors[] = 'Bu e-posta adresi başka bir kullanıcı tarafından kullanılıyor.';
                }
            }

            if (empty($errors)) {
                $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
                $stmt->execute([$user_id]);
                $row = $stmt->fetch();
                if (!$row || !password_verify($current_password, $row['password_hash'])) {
                    $errors[] = 'Mevcut şifre yanlış.';
                }
            }

            if (empty($errors)) {
                $updated = update_user_profile(
                    $pdo,
                    $user_id,
                    $profile_data['full_name'],
                    $profile_data['email'],
                    $profile_data['phone']
                );

                if ($updated) {
                    $success_profile = 'Profil bilgileriniz kaydedildi.';
                    if ($emailChanged) {
                        $token = create_email_verification_token($pdo, $user_id, $profile_data['email']);
                        if ($token && send_verification_email($profile_data['email'], $profile_data['full_name'], $token)) {
                            $warning_profile = 'E-posta adresiniz değiştirildi. Yeni e-posta adresiniz için doğrulama bağlantısı gönderildi.';
                        } else {
                            $warning_profile = 'E-posta adresiniz değiştirildi ancak doğrulama iletisi gönderilemedi. Lütfen daha sonra tekrar deneyin.';
                        }
                    }

                    $_SESSION['user']['full_name'] = $profile_data['full_name'];
                    $_SESSION['user']['email'] = $profile_data['email'];
                    $_SESSION['user']['phone'] = $profile_data['phone'];
                    $_SESSION['full_name'] = $profile_data['full_name'];

                    $user = get_user_by_id($pdo, $user_id);
                } else {
                    $errors[] = 'Profil bilgileri güncellenirken bir hata oluştu.';
                }
            }
        }

        if ($action === 'change_password') {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $new_password_confirm = $_POST['new_password_confirm'] ?? '';

            if (empty($current_password)) {
                $errors[] = 'Mevcut şifrenizi girin.';
            }
            if (empty($new_password)) {
                $errors[] = 'Yeni şifrenizi girin.';
            } elseif (strlen($new_password) < 6) {
                $errors[] = 'Yeni şifre en az 6 karakter olmalıdır.';
            }
            if ($new_password !== $new_password_confirm) {
                $errors[] = 'Yeni şifre ve tekrar aynı olmalıdır.';
            }

            if (empty($errors)) {
                $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
                $stmt->execute([$user_id]);
                $row = $stmt->fetch();
                if (!$row || !password_verify($current_password, $row['password_hash'])) {
                    $errors[] = 'Mevcut şifre yanlış.';
                }
            }

            if (empty($errors)) {
                if (update_user_password($pdo, $user_id, $new_password)) {
                    $success_password = 'Şifreniz başarıyla değiştirildi.';
                    $current_password = $new_password = $new_password_confirm = '';
                } else {
                    $errors[] = 'Şifre güncellenirken bir hata oluştu.';
                }
            }
        }
    }
}

$csrf_token = generate_csrf_token();
$page_title = 'Profilim';
$current_page = 'profile';

require_once __DIR__ . '/includes/header.php';
?>

<style>
.profile-page {
    min-height: calc(100vh - 120px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
}
.profile-card {
    width: 100%;
    max-width: 980px;
    background: rgba(15, 23, 42, 0.95);
    border: 1px solid rgba(255, 255, 255, 0.08);
    box-shadow: 0 30px 80px rgba(0, 0, 0, 0.35);
    border-radius: 24px;
    overflow: hidden;
}
.profile-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    padding: 32px 36px;
}
.profile-avatar {
    width: 110px;
    height: 110px;
    border-radius: 50%;
    background: linear-gradient(180deg, rgba(16, 185, 129, 0.2), rgba(59, 130, 246, 0.2));
    border: 1px solid rgba(255, 255, 255, 0.12);
    display: grid;
    place-items: center;
    font-size: 3rem;
    color: #fff;
    font-weight: 800;
    flex-shrink: 0;
}
.profile-main {
    flex: 1;
}
.profile-main-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}
.profile-main-title {
    min-width: 0;
}
.profile-main h1 {
    margin: 0;
    font-size: 2.4rem;
    color: #fff;
}
.profile-main p {
    margin: 8px 0 0;
    color: var(--text-secondary);
    font-size: 0.95rem;
}
.profile-card-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
}
.profile-meta {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1rem;
    padding: 0 36px 32px;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
}
.profile-stat {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 20px;
    padding: 20px 24px;
}
.profile-stat h3 {
    margin: 0;
    font-size: 1rem;
    color: var(--text-secondary);
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.profile-stat p {
    margin: 12px 0 0;
    font-size: 1.1rem;
    color: #fff;
    font-weight: 600;
}
.profile-details {
    display: grid;
    gap: 14px;
    margin-top: 10px;
}
.profile-info-item {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    color: var(--text-secondary);
    font-size: 0.95rem;
}
.profile-info-item strong {
    color: #fff;
}
.modal .auth-form {
    margin: 0;
}
.modal .form-group {
    margin-bottom: 18px;
}
.modal .form-section-title {
    margin-bottom: 18px;
    font-size: 1.15rem;
    color: #fff;
}
</style>

<section class="profile-page">
    <div class="profile-card glass-card">
        <div class="profile-top">
            <div class="profile-avatar">
                <?php echo mb_strtoupper(mb_substr($user['full_name'], 0, 1, 'UTF-8'), 'UTF-8'); ?>
            </div>
            <div class="profile-main">
                <div class="profile-main-header">
                    <div class="profile-main-title">
                        <h1><?php echo htmlspecialchars($user['full_name']); ?></h1>
                        <p><?= ($user['role'] === 'admin') ? 'Yönetici' : 'Freelancer'; ?></p>
                    </div>
                    <div class="profile-card-actions">
                        <button type="button" class="btn btn-primary" data-modal-open="profile-edit-modal">Profili Düzenle</button>
                    </div>
                </div>
                <div class="profile-details">
                    <div class="profile-info-item"><strong>E-posta</strong><span><?php echo htmlspecialchars($user['email']); ?></span></div>
                    <div class="profile-info-item"><strong>Telefon</strong><span><?php echo htmlspecialchars($user['phone']); ?></span></div>
                    <div class="profile-info-item"><strong>Durum</strong><span><?= $user['email_verified'] ? 'Doğrulandı' : 'Doğrulanmadı'; ?></span></div>
                </div>
            </div>
        </div>
    </div>
</section>

<div id="profile-edit-modal" class="modal-overlay" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="profile-edit-title">
        <div class="modal-header">
            <h3 class="modal-title" id="profile-edit-title">Profil Düzenle</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="Kapat">×</button>
        </div>
        <div class="modal-body">
            <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if (!empty($success_profile) || !empty($success_password) || !empty($warning_profile)): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success_profile ?: $success_password); ?>
                <?php if (!empty($warning_profile)): ?>
                    <p><?php echo htmlspecialchars($warning_profile); ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="profile.php" class="auth-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="update_profile">
                <h2 class="form-section-title">Profil Bilgileri</h2>
                <div class="form-group">
                    <label for="full_name" class="form-label">Ad Soyad</label>
                    <input type="text" id="full_name" name="full_name" class="form-input glass-input" required
                           value="<?php echo htmlspecialchars($profile_data['full_name']); ?>">
                </div>
                <div class="form-group">
                    <label for="email" class="form-label">E-posta</label>
                    <input type="email" id="email" name="email" class="form-input glass-input" required
                           value="<?php echo htmlspecialchars($profile_data['email']); ?>">
                </div>
                <div class="form-group">
                    <label for="phone" class="form-label">Telefon</label>
                    <input type="tel" id="phone" name="phone" class="form-input glass-input" required
                           value="<?php echo htmlspecialchars($profile_data['phone']); ?>">
                </div>
                <div class="form-group">
                    <label for="current_password_profile" class="form-label">Mevcut Şifre</label>
                    <input type="password" id="current_password_profile" name="current_password" class="form-input glass-input"
                           placeholder="Mevcut şifrenizi girin" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block auth-submit">Kaydet</button>
            </form>

            <form method="POST" action="profile.php" class="auth-form" novalidate style="margin-top: 24px;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="change_password">
                <h2 class="form-section-title">Şifre Değiştir</h2>
                <div class="form-group">
                    <label for="current_password_change" class="form-label">Mevcut Şifre</label>
                    <input type="password" id="current_password_change" name="current_password" class="form-input glass-input"
                           placeholder="Mevcut şifrenizi girin" required>
                </div>
                <div class="form-group">
                    <label for="new_password" class="form-label">Yeni Şifre</label>
                    <input type="password" id="new_password" name="new_password" class="form-input glass-input"
                           placeholder="Yeni şifrenizi girin" required>
                </div>
                <div class="form-group">
                    <label for="new_password_confirm" class="form-label">Yeni Şifre Tekrar</label>
                    <input type="password" id="new_password_confirm" name="new_password_confirm" class="form-input glass-input"
                           placeholder="Yeni şifrenizi tekrar girin" required>
                </div>
                <button type="submit" class="btn btn-secondary btn-block auth-submit">Şifreyi Değiştir</button>
            </form>
        </div>
    </div>
</div>

<?php if (!empty($errors)): ?>
<script>
    window.addEventListener('DOMContentLoaded', function() {
        if (window.ModalManager && typeof window.ModalManager.open === 'function') {
            window.ModalManager.open('profile-edit-modal');
        }
    });
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
