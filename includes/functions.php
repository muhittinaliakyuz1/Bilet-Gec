<?php
/**
 * Bilet-Geç - Genel Yardımcı Fonksiyonlar
 * Etkinlik, rezervasyon, bilet işlemleri ve yardımcı araçlar
 * v2.0 - Premium Features Modernization
 */

if (!defined('ALLOWED_ACCESS')) {
    die('Doğrudan erişim yasaktır.');
}

/**
 * Tüm aktif etkinlikleri filtrelerle getir
 *
 * @param PDO   $pdo     Veritabanı bağlantısı
 * @param array $filters Filtre parametreleri (category_id, city, search, date_from, date_to)
 * @return array          Etkinlik listesi
 */
function get_all_events(PDO $pdo, array $filters = []): array
{
    $sql = 'SELECT e.*, c.name AS category_name, c.icon AS category_icon, c.slug AS category_slug
            FROM events e
            LEFT JOIN categories c ON e.category_id = c.id
            WHERE e.status = :status';

    $params = ['status' => 'active'];

    if (!empty($filters['category_id'])) {
        $sql .= ' AND e.category_id = :category_id';
        $params['category_id'] = (int) $filters['category_id'];
    }

    if (!empty($filters['city'])) {
        $sql .= ' AND e.city = :city';
        $params['city'] = $filters['city'];
    }

    if (!empty($filters['search'])) {
        $sql .= ' AND (e.title LIKE :search OR e.description LIKE :search2 OR e.venue LIKE :search3)';
        $searchTerm = '%' . $filters['search'] . '%';
        $params['search']  = $searchTerm;
        $params['search2'] = $searchTerm;
        $params['search3'] = $searchTerm;
    }

    if (!empty($filters['date_from'])) {
        $sql .= ' AND e.event_date >= :date_from';
        $params['date_from'] = $filters['date_from'];
    }

    if (!empty($filters['date_to'])) {
        $sql .= ' AND e.event_date <= :date_to';
        $params['date_to'] = $filters['date_to'];
    }

    $sql .= ' ORDER BY e.event_date ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Tek etkinlik detayını getir
 *
 * @param PDO $pdo Veritabanı bağlantısı
 * @param int $id  Etkinlik ID
 * @return array|false Etkinlik verisi veya false
 */
function get_event_by_id(PDO $pdo, int $id): array|false
{
    $stmt = $pdo->prepare(
        'SELECT e.*, c.name AS category_name, c.icon AS category_icon, c.slug AS category_slug
         FROM events e
         LEFT JOIN categories c ON e.category_id = c.id
         WHERE e.id = ?'
    );
    $stmt->execute([$id]);

    return $stmt->fetch();
}

/**
 * Tüm kategorileri getir
 *
 * @param PDO $pdo Veritabanı bağlantısı
 * @return array    Kategori listesi
 */
function get_categories(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT * FROM categories ORDER BY id ASC');
    return $stmt->fetchAll();
}

/**
 * Etkinliğin kalan kapasitesini hesapla
 * Kapasite = toplam - onaylanmış biletler (bekleyenler kilitlenmediği için, ödeme anında kontrol edilir)
 *
 * @param PDO $pdo      Veritabanı bağlantısı
 * @param int $event_id Etkinlik ID
 * @return int           Kalan kapasite
 */
function get_remaining_capacity(PDO $pdo, int $event_id): int
{
    // Toplam kapasiteyi al
    $stmt = $pdo->prepare('SELECT total_capacity FROM events WHERE id = ?');
    $stmt->execute([$event_id]);
    $event = $stmt->fetch();

    if (!$event) {
        return 0;
    }

    $total = (int) $event['total_capacity'];

    // Onaylanmış biletlerin toplam miktarı
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(t.quantity), 0) AS confirmed_qty
         FROM tickets t
         WHERE t.event_id = ?'
    );
    $stmt->execute([$event_id]);
    $confirmed = (int) $stmt->fetch()['confirmed_qty'];

    // Biletler kilitlenmediği için (herkes ödeme ekranına geçebilsin diye)
    // bekleyen rezervasyonları kalan kapasiteden düşmüyoruz!
    $remaining = $total - $confirmed;

    return max(0, $remaining);
}

/**
 * Gerektiğinde guest_reservations tablosunu ve guest kullanıcı alanını oluşturur.
 *
 * @param PDO $pdo Veritabanı bağlantısı
 * @return void
 */
function ensure_guest_schema(PDO $pdo): void
{
    try {
        $columnStmt = $pdo->prepare("SHOW COLUMNS FROM users LIKE ?");
        $columnStmt->execute(['is_guest']);
        if (!$columnStmt->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN is_guest TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (PDOException $e) {
        error_log('ensure_guest_schema users.is_guest hatası: ' . $e->getMessage());
    }

    try {
        $tableStmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $tableStmt->execute(['guest_reservations']);
        if (!$tableStmt->fetch()) {
            $pdo->exec(
                "CREATE TABLE guest_reservations (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    event_id INT UNSIGNED NOT NULL,
                    user_id INT UNSIGNED DEFAULT NULL,
                    email VARCHAR(255) NOT NULL,
                    name VARCHAR(255) DEFAULT NULL,
                    quantity INT UNSIGNED NOT NULL DEFAULT 1,
                    total_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    token VARCHAR(128) NOT NULL,
                    status ENUM('pending', 'completed', 'expired', 'cancelled') NOT NULL DEFAULT 'pending',
                    expires_at DATETIME DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY idx_guest_reservations_token (token),
                    INDEX idx_guest_reservations_event (event_id),
                    INDEX idx_guest_reservations_user (user_id),
                    INDEX idx_guest_reservations_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
    } catch (PDOException $e) {
        error_log('ensure_guest_schema guest_reservations hatası: ' . $e->getMessage());
    }
}

/**
 * Yeni rezervasyon oluştur (transaction ile)
 *
 * @param PDO $pdo      Veritabanı bağlantısı
 * @param int $user_id  Kullanıcı ID
 * @param int $event_id Etkinlik ID
 * @param int $quantity Adet
 * @return array|false   Rezervasyon verisi veya false
 */
function create_reservation(PDO $pdo, int $user_id, int $event_id, int $quantity): array|false
{
    try {
        $pdo->beginTransaction();

        // Etkinliği kilitle (FOR UPDATE)
        $stmt = $pdo->prepare('SELECT * FROM events WHERE id = ? AND status = ? FOR UPDATE');
        $stmt->execute([$event_id, 'active']);
        $event = $stmt->fetch();

        if (!$event) {
            $pdo->rollBack();
            return false;
        }

        // Kalan kapasiteyi kontrol et (transaction içinde)
        $remaining = get_remaining_capacity($pdo, $event_id);

        if ($remaining < $quantity) {
            $pdo->rollBack();
            return false;
        }

        // Rezervasyonu oluştur (5 dakika geçerlilik)
        $stmt = $pdo->prepare(
            'INSERT INTO reservations (user_id, event_id, quantity, status, reserved_at, expires_at)
             VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 5 MINUTE))'
        );
        $stmt->execute([$user_id, $event_id, $quantity, 'pending']);
        $reservation_id = (int) $pdo->lastInsertId();

        $pdo->commit();

        // Oluşturulan rezervasyonu getir
        $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ?');
        $stmt->execute([$reservation_id]);

        return $stmt->fetch();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Rezervasyon oluşturma hatası: ' . $e->getMessage());
        return false;
    }
}

/**
 * Rezervasyonu onayla ve bilet oluştur
 *
 * @param PDO $pdo            Veritabanı bağlantısı
 * @param int $reservation_id Rezervasyon ID
 * @param int $user_id        Kullanıcı ID (yetki kontrolü)
 * @return array|false         Bilet verisi veya false
 */
function confirm_reservation(PDO $pdo, int $reservation_id, int $user_id): array|false
{
    try {
        $pdo->beginTransaction();

        // Rezervasyonu kilitle (FOR UPDATE)
        $stmt = $pdo->prepare(
            'SELECT r.*, e.price, e.total_capacity
             FROM reservations r
             JOIN events e ON r.event_id = e.id
             WHERE r.id = ? AND r.user_id = ? AND r.status = ? AND r.expires_at > NOW()
             FOR UPDATE'
        );
        $stmt->execute([$reservation_id, $user_id, 'pending']);
        $reservation = $stmt->fetch();

        if (!$reservation) {
            $pdo->rollBack();
            return false;
        }

        $event_id = (int)$reservation['event_id'];
        $quantity = (int)$reservation['quantity'];

        // Etkinliği kilitle (FOR UPDATE)
        $stmt = $pdo->prepare('SELECT total_capacity FROM events WHERE id = ? FOR UPDATE');
        $stmt->execute([$event_id]);
        $eventLock = $stmt->fetch();
        if (!$eventLock) {
            $pdo->rollBack();
            return false;
        }

        // Aktif kalan kapasiteyi hesapla (yalnızca onaylanmış biletler)
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(quantity), 0) AS confirmed_qty 
             FROM tickets 
             WHERE event_id = ?'
        );
        $stmt->execute([$event_id]);
        $confirmed = (int)$stmt->fetch()['confirmed_qty'];
        $total_capacity = (int)$reservation['total_capacity'];
        
        $remaining = $total_capacity - $confirmed;

        // EĞER kapasite yetersiz ise işlemi iptal et ve rezervasyonu başarısız olarak işaretle
        if ($remaining < $quantity) {
            $stmt = $pdo->prepare("UPDATE reservations SET status = 'cancelled' WHERE id = ?");
            $stmt->execute([$reservation_id]);
            $pdo->commit(); // Değişikliği kaydet (rezervasyon başarısız oldu)
            return false;
        }

        // Rezervasyonu onayla
        $stmt = $pdo->prepare(
            'UPDATE reservations SET status = ?, confirmed_at = NOW() WHERE id = ?'
        );
        $stmt->execute(['confirmed', $reservation_id]);

        // Bilet oluştur
        $ticket_code = generate_ticket_code();
        $total_price = $reservation['price'] * $quantity;

        $stmt = $pdo->prepare(
            'INSERT INTO tickets (reservation_id, user_id, event_id, ticket_code, quantity, total_price, purchased_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $reservation_id,
            $user_id,
            $event_id,
            $ticket_code,
            $quantity,
            $total_price,
        ]);

        $ticket_id = (int) $pdo->lastInsertId();

        $pdo->commit();

        // Oluşturulan bileti getir
        $stmt = $pdo->prepare('SELECT * FROM tickets WHERE id = ?');
        $stmt->execute([$ticket_id]);

        return $stmt->fetch();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Rezervasyon onaylama hatası: ' . $e->getMessage());
        return false;
    }
}

/**
 * Load email configuration from config/email.php
 *
 * @return array
 */
function load_email_config(): array
{
    $configPath = __DIR__ . '/../config/email.php';
    if (file_exists($configPath)) {
        $config = include $configPath;
        if (is_array($config)) {
            return $config;
        }
    }
    return [];
}

/**
 * Send an email using configured SMTP or fallback methods.
 *
 * @param string $to
 * @param string $subject
 * @param string $body
 * @return bool
 */
function send_mail_message(string $to, string $subject, string $body): bool
{
    $emailConfig = load_email_config();
    $mailSent = false;
    $composerAutoload = __DIR__ . '/../vendor/autoload.php';

    if (!empty($emailConfig['smtp_auth']) && file_exists($composerAutoload)) {
        require_once $composerAutoload;
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            try {
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $emailConfig['smtp_host'];
                $mail->SMTPAuth = true;
                $mail->Username = $emailConfig['smtp_user'];
                $mail->Password = $emailConfig['smtp_pass'];
                if (!empty($emailConfig['smtp_encryption'])) {
                    $mail->SMTPSecure = $emailConfig['smtp_encryption'];
                }
                $mail->Port = $emailConfig['smtp_port'];
                $mail->setFrom($emailConfig['sendmail_from'] ?? $emailConfig['smtp_user'], $emailConfig['sendmail_name'] ?? 'Bilet-Geç');
                $mail->addAddress($to);
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $body;
                $mail->send();
                $mailSent = true;
            } catch (Throwable $e) {
                error_log('PHPMailer hata: ' . $e->getMessage());
                $mailSent = false;
            }
        } else {
            error_log('PHPMailer sınıfı bulunamadı, fallback SMTP kullanılıyor.');
        }
    } elseif (!empty($emailConfig['smtp_auth']) && file_exists(__DIR__ . '/../lib/smtp_mailer.php')) {
        require_once __DIR__ . '/../lib/smtp_mailer.php';
        $mailer = new SimpleSMTPMailer();
        $mailSent = $mailer->send($emailConfig, $emailConfig['sendmail_from'] ?? ($emailConfig['smtp_user'] ?? 'noreply@biletgec.com'), $emailConfig['sendmail_name'] ?? 'Bilet-Geç', $to, $subject, $body);
        if (!$mailSent) {
            error_log('SimpleSMTPMailer hata: ' . $mailer->getError());
        }
    }

    if (!$mailSent) {
        if (!empty($emailConfig['use_smtp'])) {
            if (!empty($emailConfig['smtp_host'])) {
                ini_set('SMTP', $emailConfig['smtp_host']);
            }
            if (!empty($emailConfig['smtp_port'])) {
                ini_set('smtp_port', $emailConfig['smtp_port']);
            }
            if (!empty($emailConfig['sendmail_from'])) {
                ini_set('sendmail_from', $emailConfig['sendmail_from']);
            }
        }

        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= 'From: ' . ($emailConfig['sendmail_from'] ?? 'noreply@biletgec.com') . "\r\n";
        $mailSent = @mail($to, $subject, $body, $headers);

        if (!$mailSent) {
            $mailError = error_get_last();
            if ($mailError) {
                error_log('PHP mail() hata: ' . $mailError['message']);
            }
            $storageDir = __DIR__ . '/../storage/emails';
            if (!is_dir($storageDir)) {
                @mkdir($storageDir, 0755, true);
            }
            $fileName = $storageDir . '/email_' . time() . '_' . bin2hex(random_bytes(4)) . '.html';
            @file_put_contents($fileName, $body);
            error_log('Email kaydedildi: ' . $fileName);
        }
    }

    return $mailSent;
}

/**
 * Ensure the users table has verification and reset columns.
 *
 * @param PDO $pdo
 * @return void
 */
function ensure_user_schema(PDO $pdo): void
{
    $columns = [
        'email_verified'    => "ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0",
        'verification_token'=> "ADD COLUMN verification_token VARCHAR(128) DEFAULT NULL",
        'reset_token'       => "ADD COLUMN reset_token VARCHAR(128) DEFAULT NULL",
        'reset_expires'     => "ADD COLUMN reset_expires DATETIME DEFAULT NULL",
        'verified_at'       => "ADD COLUMN verified_at DATETIME DEFAULT NULL",
    ];

    foreach ($columns as $column => $alter) {
        try {
            $columnStmt = $pdo->prepare("SHOW COLUMNS FROM users LIKE ?");
            $columnStmt->execute([$column]);
            if (!$columnStmt->fetch()) {
                $pdo->exec("ALTER TABLE users $alter");
            }
        } catch (PDOException $e) {
            error_log('ensure_user_schema hatası (' . $column . '): ' . $e->getMessage());
        }
    }

    // email_verification_log tablosunu oluştur (yoksa)
    try {
        $tableStmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $tableStmt->execute(['email_verification_log']);
        if (!$tableStmt->fetch()) {
            $pdo->exec("
                CREATE TABLE email_verification_log (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL,
                    email VARCHAR(255) NOT NULL,
                    token VARCHAR(128) NOT NULL,
                    expires_at DATETIME NOT NULL,
                    is_verified TINYINT(1) NOT NULL DEFAULT 0,
                    verified_at DATETIME DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY idx_evl_token (token),
                    INDEX idx_evl_user (user_id),
                    INDEX idx_evl_email (email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    } catch (PDOException $e) {
        error_log('ensure_user_schema email_verification_log hatası: ' . $e->getMessage());
    }

    // Rol ENUM migrasyonu: admin -> firma, superadmin ekle
    try {
        $roleCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
        if ($roleCol && isset($roleCol['Type'])) {
            $type = $roleCol['Type'];
            if (strpos($type, 'superadmin') === false || strpos($type, 'firma') === false) {
                $pdo->exec("ALTER TABLE users MODIFY role ENUM('user','admin','firma','superadmin') NOT NULL DEFAULT 'user'");
            }
            $pdo->exec("UPDATE users SET role = 'firma' WHERE role = 'admin'");
            $pdo->exec("ALTER TABLE users MODIFY role ENUM('user','firma','superadmin') NOT NULL DEFAULT 'user'");
        }
    } catch (PDOException $e) {
        error_log('ensure_user_schema role migrasyon hatası: ' . $e->getMessage());
    }

    // activity_logs tablosu
    try {
        $tableStmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $tableStmt->execute(['activity_logs']);
        if (!$tableStmt->fetch()) {
            $pdo->exec("
                CREATE TABLE activity_logs (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    actor_id INT UNSIGNED NOT NULL,
                    action VARCHAR(50) NOT NULL,
                    target_type VARCHAR(30) DEFAULT NULL,
                    target_id INT UNSIGNED DEFAULT NULL,
                    details JSON DEFAULT NULL,
                    ip_address VARCHAR(45) DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_actor (actor_id),
                    INDEX idx_action (action),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    } catch (PDOException $e) {
        error_log('ensure_user_schema activity_logs hatası: ' . $e->getMessage());
    }
}

/**
 * Send email verification message.
 *
 * @param string $email
 * @param string $name
 * @param string $token
 * @return bool
 */
function send_verification_email(string $email, string $name, string $token): bool
{
    $verificationUrl = 'http://' . $_SERVER['HTTP_HOST'] . BASE_URL . 'auth/verify_email.php?token=' . urlencode($token);
    $subject = 'Bilet-Geç E-posta Doğrulama';
    $body = '<h2>Merhaba ' . htmlspecialchars($name ?: $email) . ',</h2>' .
        '<p>Hesabınızı tamamlamak için lütfen aşağıdaki butona tıklayarak e-posta adresinizi doğrulayın.</p>' .
        '<p><a href="' . $verificationUrl . '" style="display:inline-block;padding:12px 24px;background:#10b981;color:#fff;border-radius:6px;text-decoration:none;">E-postamı Doğrula</a></p>' .
        '<p>Alternatif olarak, bu bağlantıyı kopyalayabilirsiniz:</p>' .
        '<p><a href="' . $verificationUrl . '">' . $verificationUrl . '</a></p>' .
        '<p>Teşekkürler,<br>Bilet-Geç</p>';

    return send_mail_message($email, $subject, $body);
}

/**
 * Send password reset email.
 *
 * @param string $email
 * @param string $token
 * @return bool
 */
function send_password_reset_email(string $email, string $token): bool
{
    $resetUrl = 'http://' . $_SERVER['HTTP_HOST'] . BASE_URL . 'auth/reset_password.php?token=' . urlencode($token);
    $subject = 'Bilet-Geç Şifre Sıfırlama';
    $body = '<h2>Şifre Sıfırlama Talebi</h2>' .
        '<p>Bu isteği siz yaptıysanız, aşağıdaki buton ile yeni şifrenizi belirleyebilirsiniz.</p>' .
        '<p><a href="' . $resetUrl . '" style="display:inline-block;padding:12px 24px;background:#10b981;color:#fff;border-radius:6px;text-decoration:none;">Şifremi Sıfırla</a></p>' .
        '<p>Bağlantı 1 saat boyunca geçerlidir.</p>' .
        '<p>Eğer siz bu isteği yapmadıysanız bu e-postayı göz ardı edebilirsiniz.</p>' .
        '<p>Teşekkürler,<br>Bilet-Geç</p>';

    return send_mail_message($email, $subject, $body);
}

/**
 * Send login notification email.
 *
 * @param string $email Kullanıcı e-posta adresi
 * @param string $name Kullanıcı adı
 * @return bool E-posta gönderimi başarılı mı
 */
function send_login_notification_email(string $email, string $name): bool
{
    // Giriş bilgileri
    $loginTime = date('d.m.Y H:i:s');
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Bilinmiyor';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Bilinmiyor';
    
    // Tarayıcı ve işletim sistemi bilgisi çıkar
    $browser = 'Bilinmiyor';
    $os = 'Bilinmiyor';
    
    if (strpos($userAgent, 'Chrome') !== false) $browser = 'Chrome';
    elseif (strpos($userAgent, 'Firefox') !== false) $browser = 'Firefox';
    elseif (strpos($userAgent, 'Safari') !== false) $browser = 'Safari';
    elseif (strpos($userAgent, 'Edge') !== false) $browser = 'Edge';
    elseif (strpos($userAgent, 'Opera') !== false) $browser = 'Opera';
    
    if (strpos($userAgent, 'Windows') !== false) $os = 'Windows';
    elseif (strpos($userAgent, 'Mac') !== false) $os = 'Mac OS';
    elseif (strpos($userAgent, 'Linux') !== false) $os = 'Linux';
    elseif (strpos($userAgent, 'Android') !== false) $os = 'Android';
    elseif (strpos($userAgent, 'iOS') !== false) $os = 'iOS';
    
    $subject = 'Bilet-Geç - Hesabınıza Giriş Yapıldı';
    $body = '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
        <h2 style="color: #10b981;">Merhaba ' . htmlspecialchars($name) . ',</h2>
        
        <p style="font-size: 16px; line-height: 1.6;">
            Hesabınıza başarılı bir giriş yapıldı. Bu sizin değil miydiniz?
        </p>
        
        <div style="background: #f3f4f6; border-radius: 8px; padding: 20px; margin: 20px 0;">
            <h3 style="color: #374151; margin-top: 0;">Giriş Detayları:</h3>
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 8px 0; color: #6b7280;">🕐 Zaman:</td>
                    <td style="padding: 8px 0; font-weight: bold;">' . htmlspecialchars($loginTime) . '</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #6b7280;">🌍 IP Adresi:</td>
                    <td style="padding: 8px 0; font-weight: bold;">' . htmlspecialchars($ipAddress) . '</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #6b7280;">💻 Tarayıcı:</td>
                    <td style="padding: 8px 0; font-weight: bold;">' . htmlspecialchars($browser) . '</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #6b7280;">📱 İşletim Sistemi:</td>
                    <td style="padding: 8px 0; font-weight: bold;">' . htmlspecialchars($os) . '</td>
                </tr>
            </table>
        </div>
        
        <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin: 20px 0; border-radius: 4px;">
            <p style="margin: 0; color: #92400e;">
                <strong>⚠️ Bu siz değil miydiniz?</strong><br>
                Eğer bu giriş tarafınızca yapılmadıysa, derhal şifrenizi değiştirin ve hesabınızın güvenliğini kontrol edin.
            </p>
        </div>
        
        <p style="text-align: center; margin: 30px 0;">
            <a href="http://' . $_SERVER['HTTP_HOST'] . BASE_URL . 'profile.php" 
               style="display: inline-block; padding: 12px 30px; background: #10b981; color: #fff; 
                      text-decoration: none; border-radius: 6px; font-weight: bold;">
                Profilimi Görüntüle
            </a>
        </p>
        
        <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 30px 0;">
        
        <p style="color: #6b7280; font-size: 14px; text-align: center;">
            Hesap güvenliği konusunda herhangi bir sorunuz varsa, lütfen bizimle iletişime geçin.<br>
            <strong>Teşekkürler,</strong><br>
            Bilet-Geç Ekibi
        </p>
    </div>';

    return send_mail_message($email, $subject, $body);
}

/**
 * Create a password reset token for the given email.
 *
 * @param PDO $pdo
 * @param string $email
 * @param string $token
 * @return bool
 */
function create_password_reset_token(PDO $pdo, string $email, string $token): bool
{
    $stmt = $pdo->prepare(
        'UPDATE users SET reset_token = ?, reset_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE email = ?'
    );
    $stmt->execute([$token, $email]);
    return $stmt->rowCount() > 0;
}

/**
 * Get a valid reset token user.
 *
 * @param PDO $pdo
 * @param string $token
 * @return array|false
 */
function get_user_by_reset_token(PDO $pdo, string $token): array|false
{
    $stmt = $pdo->prepare(
        'SELECT id, email, full_name, reset_expires FROM users WHERE reset_token = ? AND reset_expires > NOW()'
    );
    $stmt->execute([$token]);
    return $stmt->fetch();
}

/**
 * Complete the password reset process.
 *
 * @param PDO $pdo
 * @param string $token
 * @param string $new_password
 * @return bool
 */
function complete_password_reset(PDO $pdo, string $token, string $new_password): bool
{
    $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare(
        'UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE reset_token = ? AND reset_expires > NOW()'
    );
    $stmt->execute([$password_hash, $token]);
    return $stmt->rowCount() > 0;
}

/**
 * Rezervasyonu iptal et
 *
 * @param PDO $pdo            Veritabanı bağlantısı
 * @param int $reservation_id Rezervasyon ID
 * @param int $user_id        Kullanıcı ID (yetki kontrolü)
 * @return bool                Başarılı mı
 */
function cancel_reservation(PDO $pdo, int $reservation_id, int $user_id): bool
{
    $stmt = $pdo->prepare(
        'UPDATE reservations SET status = ?
         WHERE id = ? AND user_id = ? AND status = ?'
    );
    $stmt->execute(['cancelled', $reservation_id, $user_id, 'pending']);

    return $stmt->rowCount() > 0;
}

/**
 * Süresi dolmuş bekleyen rezervasyonları expire et
 *
 * @param PDO $pdo Veritabanı bağlantısı
 * @return int      Güncellenen satır sayısı
 */
function expire_old_reservations(PDO $pdo): int
{
    $stmt = $pdo->prepare(
        'UPDATE reservations SET status = ?
         WHERE status = ? AND expires_at < NOW()'
    );
    $stmt->execute(['expired', 'pending']);

    return $stmt->rowCount();
}

/**
 * Kullanıcının onaylanmış biletlerini getir
 *
 * @param PDO $pdo     Veritabanı bağlantısı
 * @param int $user_id Kullanıcı ID
 * @return array        Bilet listesi
 */
function get_user_tickets(PDO $pdo, int $user_id): array
{
    $stmt = $pdo->prepare(
        'SELECT t.*, e.title AS event_title, e.venue, e.city, e.event_date, e.end_date,
                e.image_url, e.price AS event_price,
                c.name AS category_name, c.icon AS category_icon
         FROM tickets t
         JOIN events e ON t.event_id = e.id
         LEFT JOIN categories c ON e.category_id = c.id
         WHERE t.user_id = ?
         ORDER BY t.purchased_at DESC'
    );
    $stmt->execute([$user_id]);

    return $stmt->fetchAll();
}

/**
 * Kullanıcının bekleyen rezervasyonlarını getir
 *
 * @param PDO $pdo     Veritabanı bağlantısı
 * @param int $user_id Kullanıcı ID
 * @return array        Rezervasyon listesi
 */
function get_user_reservations(PDO $pdo, int $user_id): array
{
    $stmt = $pdo->prepare(
        'SELECT r.*, e.title AS event_title, e.venue, e.city, e.event_date, e.end_date,
                e.image_url, e.price AS event_price,
                c.name AS category_name, c.icon AS category_icon
         FROM reservations r
         JOIN events e ON r.event_id = e.id
         LEFT JOIN categories c ON e.category_id = c.id
         WHERE r.user_id = ? AND r.status = ?
         ORDER BY r.reserved_at DESC'
    );
    $stmt->execute([$user_id, 'pending']);

    return $stmt->fetchAll();
}

/**
 * Rastgele 8 karakter büyük harf + rakam bilet kodu üret
 *
 * @return string Bilet kodu
 */
function generate_ticket_code(): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    $max = strlen($chars) - 1;

    for ($i = 0; $i < 8; $i++) {
        $code .= $chars[random_int(0, $max)];
    }

    return $code;
}

/**
 * Fiyatı Türk Lirası formatında göster
 *
 * @param float $price Fiyat
 * @return string       Formatlanmış fiyat (₺XX.XX)
 */
function format_price(float $price): string
{
    return '₺' . number_format($price, 2, '.', ',');
}

/**
 * Tarihi Türkçe okunabilir formatta göster
 * Örnek: 29 Mayıs 2026, Cuma 20:00
 *
 * @param string $date Tarih string'i
 * @return string       Türkçe formatlanmış tarih
 */
function format_date(string $date, ?string $format = null): string
{
    $months = [
        1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan',
        5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos',
        9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık',
    ];

    $days = [
        'Monday'    => 'Pazartesi',
        'Tuesday'   => 'Salı',
        'Wednesday' => 'Çarşamba',
        'Thursday'  => 'Perşembe',
        'Friday'    => 'Cuma',
        'Saturday'  => 'Cumartesi',
        'Sunday'    => 'Pazar',
    ];

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }
    if ($format) {
        return date($format, $timestamp);
    }

    $day_num    = (int) date('j', $timestamp);
    $month_num  = (int) date('n', $timestamp);
    $year       = date('Y', $timestamp);
    $day_name   = date('l', $timestamp);
    $time       = date('H:i', $timestamp);

    $month_tr = $months[$month_num] ?? '';
    $day_tr   = $days[$day_name] ?? '';

    return "{$day_num} {$month_tr} {$year}, {$day_tr} {$time}";
}

/**
 * Göreceli zaman (Türkçe)
 * Örnek: 5 dakika önce, 2 saat önce, 3 gün önce
 *
 * @param string $date Tarih string'i
 * @return string       Göreceli zaman ifadesi
 */
function time_ago(string $date): string
{
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }

    $diff = time() - $timestamp;

    if ($diff < 0) {
        // Gelecek tarih
        $diff = abs($diff);

        if ($diff < 60) {
            return 'birazdan';
        }
        if ($diff < 3600) {
            $minutes = (int) floor($diff / 60);
            return $minutes . ' dakika sonra';
        }
        if ($diff < 86400) {
            $hours = (int) floor($diff / 3600);
            return $hours . ' saat sonra';
        }
        if ($diff < 2592000) {
            $days = (int) floor($diff / 86400);
            return $days . ' gün sonra';
        }
        if ($diff < 31536000) {
            $months = (int) floor($diff / 2592000);
            return $months . ' ay sonra';
        }

        $years = (int) floor($diff / 31536000);
        return $years . ' yıl sonra';
    }

    if ($diff < 60) {
        return 'az önce';
    }
    if ($diff < 3600) {
        $minutes = (int) floor($diff / 60);
        return $minutes . ' dakika önce';
    }
    if ($diff < 86400) {
        $hours = (int) floor($diff / 3600);
        return $hours . ' saat önce';
    }
    if ($diff < 2592000) {
        $days = (int) floor($diff / 86400);
        return $days . ' gün önce';
    }
    if ($diff < 31536000) {
        $months = (int) floor($diff / 2592000);
        return $months . ' ay önce';
    }

    $years = (int) floor($diff / 31536000);
    return $years . ' yıl önce';
}

/**
 * Kullanıcı girdisini temizle
 *
 * @param string $input Ham girdi
 * @return string         Temizlenmiş girdi
 */
function sanitize(string $input): string
{
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

/**
 * Eski sistem: verify_email_token (mevcut verify_email_token ile değiştirildi)
 * Bu fonksiyon kaldırıldı - yeni email_verification_log tablosunu kullanan
 * gelişmiş versiyonu kullanın.
 */

// ════════════════════════════════════════════════════════════════════════════════
// CACHE SİSTEMİ FONKSİYONLARI
// ════════════════════════════════════════════════════════════════════════════════

/**
 * Cache'den veri al
 * 
 * @param PDO $pdo Veritabanı bağlantısı
 * @param string $key Cache anahtarı
 * @return mixed Cache verisi veya null
 */
function get_cache(PDO $pdo, string $key): mixed
{
    try {
        $stmt = $pdo->prepare('SELECT cache_value FROM cache WHERE cache_key = ? AND expires_at > NOW()');
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        
        if ($result) {
            return json_decode($result['cache_value'], true);
        }
    } catch (PDOException $e) {
        error_log('Cache okuma hatası: ' . $e->getMessage());
    }
    
    return null;
}

/**
 * Cache'e veri yazma
 * 
 * @param PDO $pdo Veritabanı bağlantısı
 * @param string $key Cache anahtarı
 * @param mixed $value Saklanacak veri
 * @param int $ttl Yaşam süresi (saniye)
 * @return bool
 */
function set_cache(PDO $pdo, string $key, mixed $value, int $ttl = 3600): bool
{
    try {
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);
        $cachedValue = json_encode($value);
        
        $stmt = $pdo->prepare(
            'INSERT INTO cache (cache_key, cache_value, expires_at) 
             VALUES (?, ?, ?) 
             ON DUPLICATE KEY UPDATE cache_value = ?, expires_at = ?'
        );
        $stmt->execute([$key, $cachedValue, $expiresAt, $cachedValue, $expiresAt]);
        
        return true;
    } catch (PDOException $e) {
        error_log('Cache yazma hatası: ' . $e->getMessage());
        return false;
    }
}

/**
 * Cache'i temizle
 * 
 * @param PDO $pdo Veritabanı bağlantısı
 * @param string|null $key Cache anahtarı (null ise tüm cache temizlenir)
 * @return bool
 */
function clear_cache(PDO $pdo, ?string $key = null): bool
{
    try {
        if ($key === null) {
            $stmt = $pdo->prepare('DELETE FROM cache WHERE expires_at < NOW()');
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare('DELETE FROM cache WHERE cache_key = ?');
            $stmt->execute([$key]);
        }
        
        return true;
    } catch (PDOException $e) {
        error_log('Cache temizleme hatası: ' . $e->getMessage());
        return false;
    }
}

// ════════════════════════════════════════════════════════════════════════════════
// SADAKAT PUANI SİSTEMİ FONKSİYONLARI
// ════════════════════════════════════════════════════════════════════════════════

/**
 * Kullanıcının sadakat puanını al
 * 
 * @param PDO $pdo Veritabanı bağlantısı
 * @param int $user_id Kullanıcı ID
 * @return int Puan sayısı
 */
function get_user_loyalty_points(PDO $pdo, int $user_id): int
{
    try {
        $stmt = $pdo->prepare('SELECT points FROM loyalty_points WHERE user_id = ?');
        $stmt->execute([$user_id]);
        $result = $stmt->fetch();
        
        return $result ? (int)$result['points'] : 0;
    } catch (PDOException $e) {
        error_log('Sadakat puanı okuma hatası: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Sadakat puanı ekle ve işlem kaydı oluştur
 * 
 * @param PDO $pdo Veritabanı bağlantısı
 * @param int $user_id Kullanıcı ID
 * @param int $points Eklenecek puan
 * @param string $type İşlem türü (earn, redeem, bonus)
 * @param string|null $description İşlem açıklaması
 * @param int|null $ticket_id İlgili bilet ID
 * @return bool
 */
function add_loyalty_points(PDO $pdo, int $user_id, int $points, string $type = 'earn', 
                            ?string $description = null, ?int $ticket_id = null): bool
{
    try {
        $pdo->beginTransaction();
        
        // Eğer kayıt yoksa oluştur
        $stmt = $pdo->prepare('INSERT IGNORE INTO loyalty_points (user_id, points) VALUES (?, 0)');
        $stmt->execute([$user_id]);
        
        // Puanı güncelle
        if ($type === 'redeem') {
            $stmt = $pdo->prepare(
                'UPDATE loyalty_points SET points = GREATEST(points - ?, 0) WHERE user_id = ?'
            );
        } else {
            $stmt = $pdo->prepare(
                'UPDATE loyalty_points SET points = points + ?, last_purchase_at = NOW(), updated_at = NOW() WHERE user_id = ?'
            );
        }
        $stmt->execute([$points, $user_id]);
        
        // İşlem kaydı oluştur
        $stmt = $pdo->prepare(
            'INSERT INTO point_transactions (user_id, ticket_id, amount, transaction_type, description) 
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$user_id, $ticket_id, $points, $type, $description]);
        
        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Sadakat puanı ekleme hatası: ' . $e->getMessage());
        return false;
    }
}

/**
 * Bilet satışından sadakat puanı kazandır (Bilet fiyatının %1'i = 1 puan)
 * 
 * @param PDO $pdo Veritabanı bağlantısı
 * @param int $user_id Kullanıcı ID
 * @param float $ticket_price Bilet fiyatı
 * @param int $ticket_id Bilet ID
 * @return bool
 */
function earn_points_from_purchase(PDO $pdo, int $user_id, float $ticket_price, int $ticket_id): bool
{
    $pointsEarned = (int)($ticket_price / 1); // Her TL başına 1 puan
    $description = 'Bilet satışından puan: ' . format_price($ticket_price);
    
    return add_loyalty_points($pdo, $user_id, $pointsEarned, 'earn', $description, $ticket_id);
}

// ════════════════════════════════════════════════════════════════════════════════
// REFERRAL SİSTEMİ FONKSİYONLARI
// ════════════════════════════════════════════════════════════════════════════════

/**
 * Kullanıcı için referral kodu oluştur
 * 
 * @param PDO $pdo Veritabanı bağlantısı
 * @param int $user_id Kullanıcı ID
 * @param int $reward_points Ödül puanı
 * @return string|false Referral kodu veya false
 */
function generate_referral_code(PDO $pdo, int $user_id, int $reward_points = 100): string|false
{
    try {
        $code = strtoupper(substr(md5(uniqid()), 0, 10));
        $expiresAt = date('Y-m-d H:i:s', time() + (90 * 24 * 3600)); // 90 gün
        
        $stmt = $pdo->prepare(
            'INSERT INTO referral_codes (user_id, code, reward_type, reward_amount, expires_at) 
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$user_id, $code, 'points', $reward_points, $expiresAt]);
        
        return $code;
    } catch (PDOException $e) {
        error_log('Referral kodu oluşturma hatası: ' . $e->getMessage());
        return false;
    }
}

/**
 * Referral kodunun geçerli olup olmadığını kontrol et
 * 
 * @param PDO $pdo Veritabanı bağlantısı
 * @param string $code Referral kodu
 * @return array|false Kod bilgileri veya false
 */
function validate_referral_code(PDO $pdo, string $code): array|false
{
    try {
        $stmt = $pdo->prepare(
            'SELECT rc.*, u.full_name AS referrer_name 
             FROM referral_codes rc
             JOIN users u ON rc.user_id = u.id
             WHERE rc.code = ? AND rc.is_active = 1 
             AND (rc.expires_at IS NULL OR rc.expires_at > NOW())
             AND (rc.usage_limit IS NULL OR rc.usage_count < rc.usage_limit)'
        );
        $stmt->execute([$code]);
        
        return $stmt->fetch() ?: false;
    } catch (PDOException $e) {
        error_log('Referral kod doğrulama hatası: ' . $e->getMessage());
        return false;
    }
}

/**
 * Referral kodunu kullan ve ödül ver
 * 
 * @param PDO $pdo Veritabanı bağlantısı
 * @param string $code Referral kodu
 * @param int $referred_user_id Referral yapan kullanıcı
 * @param int $ticket_id Bilet ID
 * @return bool
 */
function use_referral_code(PDO $pdo, string $code, int $referred_user_id, int $ticket_id): bool
{
    try {
        $pdo->beginTransaction();
        
        $referral = validate_referral_code($pdo, $code);
        if (!$referral) {
            $pdo->rollBack();
            return false;
        }
        
        $referrer_user_id = (int)$referral['user_id'];
        $reward_points = (int)$referral['reward_amount'];
        
        // Referral kullanımı kayıt et
        $stmt = $pdo->prepare(
            'INSERT INTO referral_usages (referral_code_id, referred_user_id, ticket_id, reward_earned) 
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$referral['id'], $referred_user_id, $ticket_id, $reward_points]);
        
        // Referrer'e puan ver
        add_loyalty_points($pdo, $referrer_user_id, $reward_points, 'bonus', 
                          'Referral ödülü: ' . $code, null);
        
        // Kullanım sayısını artır
        $stmt = $pdo->prepare('UPDATE referral_codes SET usage_count = usage_count + 1 WHERE id = ?');
        $stmt->execute([$referral['id']]);
        
        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Referral kodunu kullanma hatası: ' . $e->getMessage());
        return false;
    }
}

// ════════════════════════════════════════════════════════════════════════════════
// KUPON/İNDİRİM KODU SİSTEMİ FONKSİYONLARI
// ════════════════════════════════════════════════════════════════════════════════

/**
 * Kupon kodunun geçerli olup olmadığını kontrol et
 * 
 * @param PDO $pdo Veritabanı bağlantısı
 * @param string $code Kupon kodu
 * @param int|null $user_id Kullanıcı ID (kullanıcı sınırı kontrolü için)
 * @param float $order_amount Sipariş tutarı
 * @return array|false Kupon bilgileri veya false
 */
function validate_coupon(PDO $pdo, string $code, ?int $user_id = null, float $order_amount = 0): array|false
{
    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM coupons 
             WHERE code = ? AND is_active = 1
             AND NOW() >= starts_at AND NOW() <= expires_at
             AND (usage_limit IS NULL OR usage_count < usage_limit)'
        );
        $stmt->execute([$code]);
        $coupon = $stmt->fetch();
        
        if (!$coupon) {
            return false;
        }
        
        // Minimum sipariş tutarını kontrol et
        if ($order_amount > 0 && (float)$coupon['minimum_order_amount'] > 0 
            && $order_amount < (float)$coupon['minimum_order_amount']) {
            return false;
        }
        
        // Kullanıcı başına kullanım limitini kontrol et
        if ($user_id && (int)$coupon['per_user_limit'] > 0) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) as usage_count FROM coupon_usages 
                 WHERE coupon_id = ? AND user_id = ?'
            );
            $stmt->execute([$coupon['id'], $user_id]);
            $usage = $stmt->fetch();
            
            if ((int)$usage['usage_count'] >= (int)$coupon['per_user_limit']) {
                return false;
            }
        }
        
        return $coupon;
    } catch (PDOException $e) {
        error_log('Kupon doğrulama hatası: ' . $e->getMessage());
        return false;
    }
}

/**
 * İndirim tutarını hesapla
 * 
 * @param array $coupon Kupon bilgisi
 * @param float $amount Ürün fiyatı
 * @return float İndirim tutarı
 */
function calculate_discount(array $coupon, float $amount): float
{
    if ($coupon['discount_type'] === 'percentage') {
        $discount = ($amount * (float)$coupon['discount_value']) / 100;
    } else {
        $discount = (float)$coupon['discount_value'];
    }
    
    // Maximum indirim sınırını uygula
    if (!empty($coupon['maximum_discount'])) {
        $discount = min($discount, (float)$coupon['maximum_discount']);
    }
    
    return min($discount, $amount); // İndirim ürünün fiyatını aşamaz
}

/**
 * Kuponu kullan ve işlem kaydı oluştur
 * 
 * @param PDO $pdo Veritabanı bağlantısı
 * @param string $code Kupon kodu
 * @param int $user_id Kullanıcı ID
 * @param int $ticket_id Bilet ID
 * @param float $discount_amount İndirim tutarı
 * @return bool
 */
function use_coupon(PDO $pdo, string $code, int $user_id, int $ticket_id, float $discount_amount): bool
{
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare('SELECT id FROM coupons WHERE code = ? AND is_active = 1');
        $stmt->execute([$code]);
        $coupon = $stmt->fetch();
        
        if (!$coupon) {
            $pdo->rollBack();
            return false;
        }
        
        $coupon_id = (int)$coupon['id'];
        
        // Kupon kullanım kaydı oluştur
        $stmt = $pdo->prepare(
            'INSERT INTO coupon_usages (coupon_id, user_id, ticket_id, discount_amount) 
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$coupon_id, $user_id, $ticket_id, $discount_amount]);
        
        // Kupon kullanım sayısını artır
        $stmt = $pdo->prepare('UPDATE coupons SET usage_count = usage_count + 1 WHERE id = ?');
        $stmt->execute([$coupon_id]);
        
        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Kupon kullanma hatası: ' . $e->getMessage());
        return false;
    }
}

// ════════════════════════════════════════════════════════════════════════════════
// E-POSTA DOĞRULAMA İYİLEŞTİRMELERİ
// ════════════════════════════════════════════════════════════════════════════════

/**
 * E-posta doğrulama tokenı oluştur ve kaydet
 * 
 * @param PDO $pdo Veritabanı bağlantısı
 * @param int $user_id Kullanıcı ID
 * @param string $email E-posta adresi
 * @param int $validity_hours Geçerlilik süresi (saat)
 * @return string Doğrulama tokeni
 */
function create_email_verification_token(PDO $pdo, int $user_id, string $email, int $validity_hours = 24): string
{
    try {
        $token = bin2hex(random_bytes(64));
        $expiresAt = date('Y-m-d H:i:s', time() + ($validity_hours * 3600));
        
        $stmt = $pdo->prepare(
            'INSERT INTO email_verification_log (user_id, email, token, expires_at, is_verified) 
             VALUES (?, ?, ?, ?, 0)'
        );
        $stmt->execute([$user_id, $email, $token, $expiresAt]);
        
        return $token;
    } catch (PDOException $e) {
        error_log('E-posta doğrulama tokeni oluşturma hatası: ' . $e->getMessage());
        return '';
    }
}

/**
 * E-posta doğrulama tokenını doğrula
 * 
 * @param PDO $pdo Veritabanı bağlantısı
 * @param string $token Doğrulama tokeni
 * @return int|false Kullanıcı ID veya false
 */
function verify_email_token(PDO $pdo, string $token): int|false
{
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare(
            'SELECT id, user_id, email FROM email_verification_log 
             WHERE token = ? AND is_verified = 0 AND expires_at > NOW()'
        );
        $stmt->execute([$token]);
        $log = $stmt->fetch();
        
        if (!$log) {
            $pdo->rollBack();
            return false;
        }
        
        $user_id = (int)$log['user_id'];
        
        // Doğrulama kaydını güncelle
        $stmt = $pdo->prepare(
            'UPDATE email_verification_log SET is_verified = 1, verified_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$log['id']]);
        
        // Kullanıcı e-postasını doğru olarak işaretle
        $stmt = $pdo->prepare(
            'UPDATE users SET email_verified = 1, verified_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$user_id]);
        
        $pdo->commit();
        return $user_id;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('E-posta doğrulama hatası: ' . $e->getMessage());
        return false;
    }
}

/**
 * Bir kullanıcının doğru e-posta adresine sahip olup olmadığını kontrol et
 * 
 * @param PDO $pdo Veritabanı bağlantısı
 * @param int $user_id Kullanıcı ID
 * @return bool
 */
function is_email_verified(PDO $pdo, int $user_id): bool
{
    try {
        $stmt = $pdo->prepare('SELECT email_verified FROM users WHERE id = ?');
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        return $user && (int)$user['email_verified'] === 1;
    } catch (PDOException $e) {
        error_log('E-posta doğrulama kontrol hatası: ' . $e->getMessage());
        return false;
    }
}

// ════════════════════════════════════════════════════════════════════════════════
// GELİŞTİRİLMİŞ ETKINLIK ARAMA VE FİLTRELEME
// ════════════════════════════════════════════════════════════════════════════════

/**
 * Geliştirilmiş etkinlik arama ve filtreleme (fiyat ve diğer kriterler)
 * 
 * @param PDO $pdo Veritabanı bağlantısı
 * @param array $filters Filtre parametreleri
 * @return array Etkinlik listesi
 */
function search_events(PDO $pdo, array $filters = []): array
{
    try {
        $sql = 'SELECT e.*, c.name AS category_name, c.icon AS category_icon, c.slug AS category_slug,
                       (SELECT COUNT(*) FROM tickets WHERE event_id = e.id) AS tickets_sold,
                       e.total_capacity - COALESCE((SELECT SUM(quantity) FROM tickets WHERE event_id = e.id), 0) AS remaining_capacity
                FROM events e
                LEFT JOIN categories c ON e.category_id = c.id
                WHERE e.status != :status';
        
        $params = ['status' => 'cancelled'];

        if (isset($filters['status']) && $filters['status'] !== '') {
            $sql = 'SELECT e.*, c.name AS category_name, c.icon AS category_icon, c.slug AS category_slug,
                       (SELECT COUNT(*) FROM tickets WHERE event_id = e.id) AS tickets_sold,
                       e.total_capacity - COALESCE((SELECT SUM(quantity) FROM tickets WHERE event_id = e.id), 0) AS remaining_capacity
                FROM events e
                LEFT JOIN categories c ON e.category_id = c.id
                WHERE e.status = :status';
            $params = ['status' => $filters['status']];
        }
        
        // Kategori filtresi
        if (!empty($filters['category_id'])) {
            $sql .= ' AND e.category_id = :category_id';
            $params['category_id'] = (int)$filters['category_id'];
        }
        
        // Şehir filtresi
        if (!empty($filters['city'])) {
            $sql .= ' AND e.city = :city';
            $params['city'] = $filters['city'];
        }

        // Mekan filtresi
        if (!empty($filters['venue'])) {
            $sql .= ' AND e.venue = :venue';
            $params['venue'] = $filters['venue'];
        }
        
        // Arama filtresi
        if (!empty($filters['search'])) {
            $sql .= ' AND (e.title LIKE :search OR e.description LIKE :search2 OR e.venue LIKE :search3)';
            $searchTerm = '%' . $filters['search'] . '%';
            $params['search'] = $searchTerm;
            $params['search2'] = $searchTerm;
            $params['search3'] = $searchTerm;
        }
        
        // Tarih filtresi
        if (!empty($filters['date_from'])) {
            $sql .= ' AND e.event_date >= :date_from';
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= ' AND e.event_date <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }
        
        // Fiyat filtresi
        if (!empty($filters['price_from'])) {
            $sql .= ' AND e.price >= :price_from';
            $params['price_from'] = (float)$filters['price_from'];
        }
        
        if (!empty($filters['price_to'])) {
            $sql .= ' AND e.price <= :price_to';
            $params['price_to'] = (float)$filters['price_to'];
        }
        
        // Uygun kapasiteyi filtrele
        if (!empty($filters['available_only'])) {
            $sql .= ' AND (e.total_capacity - COALESCE((SELECT SUM(quantity) FROM tickets WHERE event_id = e.id), 0)) > 0';
        }
        
        // Sıralama
        $sortBy = $filters['sort_by'] ?? 'date_asc';
        switch ($sortBy) {
            case 'price_asc':
                $sql .= ' ORDER BY e.price ASC, e.event_date ASC';
                break;
            case 'price_desc':
                $sql .= ' ORDER BY e.price DESC, e.event_date ASC';
                break;
            case 'popularity':
                $sql .= ' ORDER BY tickets_sold DESC, e.event_date ASC';
                break;
            case 'date_desc':
                $sql .= ' ORDER BY e.event_date DESC';
                break;
            case 'date_asc':
            default:
                $sql .= ' ORDER BY e.event_date ASC';
                break;
        }
        
        // Sayfalama
        if (!empty($filters['limit'])) {
            $limit = (int)$filters['limit'];
            $offset = !empty($filters['offset']) ? (int)$filters['offset'] : 0;
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Etkinlik arama hatası: ' . $e->getMessage());
        return [];
    }
}

/**
 * Yüklenen etkinlik görselini kaydeder veya girilen URL'yi döner.
 *
 * @param array $files Upload dosya verileri
 * @param string $imageUrl Kullanıcı tarafından girilen görsel URL'si
 * @param string $existingImageUrl Mevcut etkinlik görseli
 * @param string $defaultImage Varsayılan görsel URL'si
 * @return string Görsel yolu veya URL
 */
function get_uploaded_event_image_details(array $files, string $imageUrl = '', string $existingImageUrl = '', string $defaultImage = 'https://placehold.co/800x400/1a1a2e/7c3aed?text=Etkinlik'): array
{
    $imageUrl = trim($imageUrl);
    $existingImageUrl = trim($existingImageUrl);

    if (!isset($files['image_file']) || $files['image_file']['error'] === UPLOAD_ERR_NO_FILE) {
        if ($imageUrl !== '' && !is_safe_image_url($imageUrl)) {
            return [
                'path' => $existingImageUrl !== '' ? $existingImageUrl : $defaultImage,
                'error' => 'Geçersiz görsel URLsi. Lütfen yalnızca http(s) ya da geçerli bir dosya yolu kullanın.',
                'file_uploaded' => false,
            ];
        }
    }

    if (!isset($files['image_file'])) {
        if ($imageUrl !== '') {
            return ['path' => $imageUrl, 'error' => null, 'file_uploaded' => false];
        }
        if ($existingImageUrl !== '') {
            return ['path' => $existingImageUrl, 'error' => null, 'file_uploaded' => false];
        }
        return ['path' => $defaultImage, 'error' => null, 'file_uploaded' => false];
    }

    $file = $files['image_file'];
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        if ($imageUrl !== '') {
            return ['path' => $imageUrl, 'error' => null, 'file_uploaded' => false];
        }
        if ($existingImageUrl !== '') {
            return ['path' => $existingImageUrl, 'error' => null, 'file_uploaded' => false];
        }
        return ['path' => $defaultImage, 'error' => null, 'file_uploaded' => false];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['path' => $existingImageUrl !== '' ? $existingImageUrl : $defaultImage, 'error' => upload_error_message($file['error']), 'file_uploaded' => true];
    }

    $uploadDir = __DIR__ . '/../assets/images/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        error_log('Görsel klasörü oluşturulamadı: ' . $uploadDir);
        return ['path' => $existingImageUrl !== '' ? $existingImageUrl : $defaultImage, 'error' => 'Görsel yükleme klasörü oluşturulamadı.', 'file_uploaded' => true];
    }

    $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($extension, $allowedTypes, true)) {
        return ['path' => $existingImageUrl !== '' ? $existingImageUrl : $defaultImage, 'error' => 'Sadece JPG, PNG, GIF veya WEBP formatları desteklenir.', 'file_uploaded' => true];
    }

    $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $originalName);
    $safeName = substr($safeName, 0, 50);
    $filename = sprintf('%s-%s.%s', $safeName ?: 'event', uniqid(), $extension ?: 'jpg');
    $destination = $uploadDir . $filename;

    $moved = false;
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        $moved = true;
    } elseif (is_uploaded_file($file['tmp_name']) && rename($file['tmp_name'], $destination)) {
        $moved = true;
    } elseif (file_exists($file['tmp_name']) && copy($file['tmp_name'], $destination) && unlink($file['tmp_name'])) {
        $moved = true;
    }

    if (!$moved) {
        error_log('Görsel taşınamadı: ' . $file['tmp_name'] . ' -> ' . $destination);
        return ['path' => $existingImageUrl !== '' ? $existingImageUrl : $defaultImage, 'error' => 'Görsel sunucuya taşınamadı.', 'file_uploaded' => true];
    }

    return ['path' => 'assets/images/' . $filename, 'error' => null, 'file_uploaded' => true];
}

function handle_uploaded_event_image(array $files, string $imageUrl = '', string $existingImageUrl = '', string $defaultImage = 'https://placehold.co/800x400/1a1a2e/7c3aed?text=Etkinlik'): string
{
    return get_uploaded_event_image_details($files, $imageUrl, $existingImageUrl, $defaultImage)['path'];
}

function upload_error_message(int $errorCode): string
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Yüklediğiniz görsel çok büyük. Lütfen daha küçük bir dosya seçin.',
        UPLOAD_ERR_PARTIAL => 'Görsel yalnızca kısmen yüklendi. Lütfen tekrar deneyin.',
        UPLOAD_ERR_NO_TMP_DIR => 'Geçici klasör bulunamadı.',
        UPLOAD_ERR_CANT_WRITE => 'Sunucu üzerinde görsel yazılamadı.',
        UPLOAD_ERR_EXTENSION => 'Görsel yüklenmesi eklenti tarafından engellendi.',
        default => 'Görsel yüklenirken bir hata oluştu.'
    };
}

/**
 * Etkinlik filtreleme için mevcut fiyat aralığını al
 * 
 * @param PDO $pdo Veritabanı bağlantısı
 * @return array min ve max fiyat
 */
function get_event_price_range(PDO $pdo): array
{
    try {
        $stmt = $pdo->prepare('SELECT MIN(price) as min_price, MAX(price) as max_price FROM events WHERE status = ?');
        $stmt->execute(['active']);
        $result = $stmt->fetch();
        
        return [
            'min' => (float)($result['min_price'] ?? 0),
            'max' => (float)($result['max_price'] ?? 0),
        ];
    } catch (PDOException $e) {
        error_log('Fiyat aralığı alma hatası: ' . $e->getMessage());
        return ['min' => 0, 'max' => 0];
    }
}

/**
 * Temel URL'i döndürür
 *
 * @return string Temel URL
 */
function base_url(string $path = ''): string
{
    $base = rtrim(BASE_URL, '/') . '/';
    $path = ltrim($path, '/');
    return $path === '' ? $base : $base . $path;
}

/**
 * Göreli veya mutlak URL'leri resolve eder
 *
 * @param string $path
 * @return string
 */
function resolve_url(string $path): string
{
    $trimmed = trim($path);
    if ($trimmed === '') {
        return '';
    }

    if (strpos($trimmed, '//') === 0) {
        return $trimmed;
    }

    $parts = parse_url($trimmed);
    if ($parts !== false && !empty($parts['scheme'])) {
        return $trimmed;
    }

    return base_url($trimmed);
}

/**
 * HTML-escape yardımcı fonksiyon
 *
 * @param mixed $value
 * @return string
 */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Status badge HTML döndürür
 *
 * @param string $status
 * @return string
 */
function status_badge(string $status): string
{
    $map = [
        'active'    => ['label' => 'Aktif', 'class' => 'badge-success'],
        'cancelled' => ['label' => 'İptal', 'class' => 'badge-danger'],
        'completed' => ['label' => 'Tamamlandı', 'class' => 'badge-warning'],
        'draft'     => ['label' => 'Taslak', 'class' => 'badge-muted'],
    ];

    $s = strtolower($status);
    $info = $map[$s] ?? ['label' => ucfirst($s), 'class' => 'badge-muted'];

    return '<span class="badge ' . e($info['class']) . '">' . e($info['label']) . '</span>';
}

/**
 * Flash mesaj ayarla
 *
 * @param string $name    Mesajın adı
 * @param string $message Mesaj içeriği
 * @param string $type    Mesaj tipi (success, error, warning, info)
 * @return void
 */
function set_flash(string $a, string $b, ?string $c = null): void
{
    if (!isset($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }

    // Backwards-compatible: set_flash('success', 'Mesaj') veya set_flash('name','mesaj','type')
    $commonTypes = ['success', 'error', 'warning', 'info'];

    if (in_array($a, $commonTypes, true)) {
        $type = $a;
        $message = $b;
        $name = $c ?? uniqid('flash_', true);
    } else {
        $name = $a;
        $message = $b;
        $type = $c ?? 'info';
    }

    $_SESSION['flash'][$name] = ['message' => $message, 'type' => $type];
}

/**
 * Flash mesajı al ve sil
 *
 * @param string $name Mesajın adı
 * @return array|null  Mesaj verisi (message, type) veya null
 */
function get_flash(?string $name = null): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }

    if ($name !== null) {
        if (isset($_SESSION['flash'][$name])) {
            $flash = $_SESSION['flash'][$name];
            unset($_SESSION['flash'][$name]);
            return $flash;
        }
        return null;
    }

    // Eğer isim verilmemişse, ilk flash mesajı döndür ve sil
    foreach ($_SESSION['flash'] as $key => $data) {
        $flash = $data;
        unset($_SESSION['flash'][$key]);
        return $flash;
    }

    return null;
}
