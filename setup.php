<?php
/**
 * Bilet-Geç - Veritabanı Kurulum Scripti
 * Veritabanını, tabloları, varsayılan verileri oluşturur.
 * Birden fazla kez güvenle çalıştırılabilir (DROP IF EXISTS).
 */

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'biletgec';

$messages = [];
$hasError = false;

try {
    // Önce veritabanı olmadan bağlan
    $pdo = new PDO("mysql:host={$db_host};charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // ──────────────────────────────────────────
    // 1. Veritabanı oluştur
    // ──────────────────────────────────────────
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$db_name}`");
    $messages[] = ['success', 'Veritabanı "biletgec" oluşturuldu veya zaten mevcut.'];

    // ──────────────────────────────────────────
    // 2. Tabloları oluştur (DROP IF EXISTS)
    // ──────────────────────────────────────────

    // Önce foreign key içeren tabloları sil
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec('DROP TABLE IF EXISTS refund_status_log');
    $pdo->exec('DROP TABLE IF EXISTS refunds');
    $pdo->exec('DROP TABLE IF EXISTS referral_usages');
    $pdo->exec('DROP TABLE IF EXISTS point_transactions');
    $pdo->exec('DROP TABLE IF EXISTS loyalty_points');
    $pdo->exec('DROP TABLE IF EXISTS referral_codes');
    $pdo->exec('DROP TABLE IF EXISTS coupon_usages');
    $pdo->exec('DROP TABLE IF EXISTS coupons');
    $pdo->exec('DROP TABLE IF EXISTS cache');
    $pdo->exec('DROP TABLE IF EXISTS email_verification_log');
    $pdo->exec('DROP TABLE IF EXISTS event_price_history');
    $pdo->exec('DROP TABLE IF EXISTS tickets');
    $pdo->exec('DROP TABLE IF EXISTS guest_reservations');
    $pdo->exec('DROP TABLE IF EXISTS reservations');
    $pdo->exec('DROP TABLE IF EXISTS events');
    $pdo->exec('DROP TABLE IF EXISTS categories');
    $pdo->exec('DROP TABLE IF EXISTS users');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $messages[] = ['success', 'Eski tablolar temizlendi.'];

    // users tablosu
    $pdo->exec("
        CREATE TABLE users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            phone VARCHAR(20) DEFAULT NULL,
            is_guest TINYINT(1) NOT NULL DEFAULT 0,
            email_verified TINYINT(1) NOT NULL DEFAULT 0,
            verification_token VARCHAR(128) DEFAULT NULL,
            reset_token VARCHAR(128) DEFAULT NULL,
            reset_expires DATETIME DEFAULT NULL,
            verified_at DATETIME DEFAULT NULL,
            role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY idx_users_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = ['success', 'Tablo oluşturuldu: users'];

    // categories tablosu
    $pdo->exec("
        CREATE TABLE categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            icon VARCHAR(10) NOT NULL DEFAULT '',
            slug VARCHAR(100) NOT NULL,
            UNIQUE KEY idx_categories_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = ['success', 'Tablo oluşturuldu: categories'];

    // events tablosu
    $pdo->exec("
        CREATE TABLE events (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            category_id INT UNSIGNED DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            short_description VARCHAR(500) DEFAULT NULL,
            venue VARCHAR(255) NOT NULL,
            city VARCHAR(100) NOT NULL,
            event_date DATETIME NOT NULL,
            end_date DATETIME DEFAULT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total_capacity INT UNSIGNED NOT NULL DEFAULT 0,
            image_url VARCHAR(500) DEFAULT NULL,
            organizer VARCHAR(255) DEFAULT NULL,
            status ENUM('active', 'cancelled', 'completed') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_events_category (category_id),
            INDEX idx_events_status (status),
            INDEX idx_events_date (event_date),
            INDEX idx_events_city (city),
            INDEX idx_events_price (price),
            INDEX idx_events_combined (status, event_date),
            CONSTRAINT fk_events_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = ['success', 'Tablo oluşturuldu: events'];

    // reservations tablosu
    $pdo->exec("
        CREATE TABLE reservations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            event_id INT UNSIGNED NOT NULL,
            quantity INT UNSIGNED NOT NULL DEFAULT 1,
            status ENUM('pending', 'confirmed', 'expired', 'cancelled') NOT NULL DEFAULT 'pending',
            reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME DEFAULT NULL,
            confirmed_at DATETIME DEFAULT NULL,
            INDEX idx_reservations_user (user_id),
            INDEX idx_reservations_event (event_id),
            INDEX idx_reservations_status (status),
            INDEX idx_reservations_expires (expires_at),
            CONSTRAINT fk_reservations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_reservations_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = ['success', 'Tablo oluşturuldu: reservations'];

    // guest_reservations tablosu
    $pdo->exec("
        CREATE TABLE guest_reservations (
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
            INDEX idx_guest_reservations_status (status),
            CONSTRAINT fk_guest_reservations_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_guest_reservations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = ['success', 'Tablo oluşturuldu: guest_reservations'];

    // tickets tablosu
    $pdo->exec("
        CREATE TABLE tickets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reservation_id INT UNSIGNED DEFAULT NULL,
            user_id INT UNSIGNED NOT NULL,
            event_id INT UNSIGNED NOT NULL,
            ticket_code VARCHAR(20) NOT NULL,
            quantity INT UNSIGNED NOT NULL DEFAULT 1,
            total_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            purchased_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY idx_tickets_code (ticket_code),
            INDEX idx_tickets_user (user_id),
            INDEX idx_tickets_event (event_id),
            CONSTRAINT fk_tickets_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT fk_tickets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_tickets_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = ['success', 'Tablo oluşturuldu: tickets'];

    // loyalty_points tablosu
    $pdo->exec("
        CREATE TABLE loyalty_points (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            points INT NOT NULL DEFAULT 0,
            total_spent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            last_purchase_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_loyalty_user (user_id),
            INDEX idx_loyalty_points (points),
            CONSTRAINT fk_loyalty_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = ['success', 'Tablo oluşturuldu: loyalty_points'];

    // point_transactions tablosu
    $pdo->exec("
        CREATE TABLE point_transactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            ticket_id INT UNSIGNED DEFAULT NULL,
            coupon_id INT UNSIGNED DEFAULT NULL,
            amount INT NOT NULL,
            transaction_type ENUM('earn', 'redeem', 'expire', 'bonus') NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_point_user (user_id),
            INDEX idx_point_type (transaction_type),
            INDEX idx_point_created (created_at),
            CONSTRAINT fk_point_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_point_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = ['success', 'Tablo oluşturuldu: point_transactions'];

    // referral_codes tablosu
    $pdo->exec("
        CREATE TABLE referral_codes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            code VARCHAR(20) NOT NULL,
            reward_type ENUM('points', 'discount') NOT NULL DEFAULT 'points',
            reward_amount INT DEFAULT 0,
            reward_discount DECIMAL(10,2) DEFAULT 0.00,
            usage_limit INT DEFAULT NULL,
            usage_count INT NOT NULL DEFAULT 0,
            expires_at DATETIME DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY idx_referral_code (code),
            INDEX idx_referral_user (user_id),
            INDEX idx_referral_active (is_active),
            CONSTRAINT fk_referral_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = ['success', 'Tablo oluşturuldu: referral_codes'];

    // referral_usages tablosu
    $pdo->exec("
        CREATE TABLE referral_usages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            referral_code_id INT UNSIGNED NOT NULL,
            referred_user_id INT UNSIGNED DEFAULT NULL,
            ticket_id INT UNSIGNED DEFAULT NULL,
            reward_earned INT NOT NULL DEFAULT 0,
            used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_referral_code (referral_code_id),
            INDEX idx_referral_referred (referred_user_id),
            INDEX idx_referral_ticket (ticket_id),
            CONSTRAINT fk_referral_code FOREIGN KEY (referral_code_id) REFERENCES referral_codes(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_referral_referred FOREIGN KEY (referred_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT fk_referral_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = ['success', 'Tablo oluşturuldu: referral_usages'];

    // coupons tablosu
    $pdo->exec("
        CREATE TABLE coupons (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(20) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            discount_type ENUM('fixed', 'percentage') NOT NULL DEFAULT 'fixed',
            discount_value DECIMAL(10,2) NOT NULL,
            minimum_order_amount DECIMAL(10,2) DEFAULT 0.00,
            maximum_discount DECIMAL(10,2) DEFAULT NULL,
            usage_limit INT DEFAULT NULL,
            usage_count INT NOT NULL DEFAULT 0,
            per_user_limit INT DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            category_id INT UNSIGNED DEFAULT NULL,
            applies_to_event_id INT UNSIGNED DEFAULT NULL,
            starts_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            created_by INT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY idx_coupon_code (code),
            INDEX idx_coupon_active (is_active),
            INDEX idx_coupon_expires (expires_at),
            INDEX idx_coupon_created (created_at),
            CONSTRAINT fk_coupon_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT fk_coupon_event FOREIGN KEY (applies_to_event_id) REFERENCES events(id) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT fk_coupon_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = ['success', 'Tablo oluşturuldu: coupons'];

    // coupon_usages tablosu
    $pdo->exec("
        CREATE TABLE coupon_usages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            coupon_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            ticket_id INT UNSIGNED NOT NULL,
            discount_amount DECIMAL(10,2) NOT NULL,
            used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_coupon_usage_coupon (coupon_id),
            INDEX idx_coupon_usage_user (user_id),
            INDEX idx_coupon_usage_ticket (ticket_id),
            INDEX idx_coupon_usage_created (used_at),
            CONSTRAINT fk_coupon_usage_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_coupon_usage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_coupon_usage_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = ['success', 'Tablo oluşturuldu: coupon_usages'];

    // cache tablosu
    $pdo->exec("
        CREATE TABLE cache (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cache_key VARCHAR(255) NOT NULL,
            cache_value LONGTEXT NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY idx_cache_key (cache_key),
            INDEX idx_cache_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = ['success', 'Tablo oluşturuldu: cache'];

    // email_verification_log tablosu
    $pdo->exec("
        CREATE TABLE email_verification_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            email VARCHAR(255) NOT NULL,
            token VARCHAR(128) NOT NULL,
            attempt_count INT NOT NULL DEFAULT 1,
            last_sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            verified_at DATETIME DEFAULT NULL,
            is_verified TINYINT(1) NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email_log_user (user_id),
            INDEX idx_email_log_token (token),
            INDEX idx_email_log_verified (is_verified),
            INDEX idx_email_log_expires (expires_at),
            CONSTRAINT fk_email_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = ['success', 'Tablo oluşturuldu: email_verification_log'];

    // event_price_history tablosu (fiyat değişim izlemesi)
    $pdo->exec("
        CREATE TABLE event_price_history (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_id INT UNSIGNED NOT NULL,
            old_price DECIMAL(10,2) NOT NULL,
            new_price DECIMAL(10,2) NOT NULL,
            changed_by INT UNSIGNED DEFAULT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_price_history_event (event_id),
            INDEX idx_price_history_changed (changed_at),
            CONSTRAINT fk_price_history_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_price_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = ['success', 'Tablo oluşturuldu: event_price_history'];

    // refunds tablosu (iade talepleri)
    $pdo->exec("
        CREATE TABLE refunds (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            event_id INT UNSIGNED NOT NULL,
            original_amount DECIMAL(10,2) NOT NULL,
            refund_amount DECIMAL(10,2) NOT NULL,
            reason VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            status ENUM('pending', 'approved', 'rejected', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
            rejection_reason VARCHAR(255) DEFAULT NULL,
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME DEFAULT NULL,
            processed_by INT UNSIGNED DEFAULT NULL,
            refund_method ENUM('card', 'wallet', 'bank_transfer') DEFAULT 'card',
            transaction_id VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_refund_ticket (ticket_id),
            INDEX idx_refund_user (user_id),
            INDEX idx_refund_event (event_id),
            INDEX idx_refund_status (status),
            INDEX idx_refund_requested (requested_at),
            CONSTRAINT fk_refund_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_refund_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_refund_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_refund_processor FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = ['success', 'Tablo oluşturuldu: refunds'];

    // refund_status_log tablosu (iade durumu değişim geçmişi - audit trail)
    $pdo->exec("
        CREATE TABLE refund_status_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            refund_id INT UNSIGNED NOT NULL,
            old_status ENUM('pending', 'approved', 'rejected', 'completed', 'cancelled') DEFAULT NULL,
            new_status ENUM('pending', 'approved', 'rejected', 'completed', 'cancelled') NOT NULL,
            changed_by INT UNSIGNED DEFAULT NULL,
            comment TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status_log_refund (refund_id),
            INDEX idx_status_log_status (new_status),
            INDEX idx_status_log_created (created_at),
            CONSTRAINT fk_status_log_refund FOREIGN KEY (refund_id) REFERENCES refunds(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_status_log_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = ['success', 'Tablo oluşturuldu: refund_status_log'];

    // ──────────────────────────────────────────
    // 3. Varsayılan kategoriler
    // ──────────────────────────────────────────
    $categories = [
        ['Tiyatro',         '🎭', 'tiyatro'],
        ['Konser',          '🎵', 'konser'],
        ['Atölye',          '🎨', 'atolye'],
        ['Yoga & Wellness', '🧘', 'yoga-wellness'],
        ['Şehir Turu',      '🚶', 'sehir-turu'],
        ['Workshop',        '💡', 'workshop'],
        ['Stand-up',        '😂', 'stand-up'],
        ['Sinema',          '🎬', 'sinema'],
    ];

    $stmt = $pdo->prepare('INSERT INTO categories (name, icon, slug) VALUES (?, ?, ?)');
    foreach ($categories as $cat) {
        $stmt->execute($cat);
    }
    $messages[] = ['success', count($categories) . ' kategori eklendi.'];

    // ──────────────────────────────────────────
    // 4. Admin kullanıcı
    // ──────────────────────────────────────────
    $admin_hash = password_hash('admin123', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare(
        'INSERT INTO users (full_name, email, password_hash, phone, role, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute(['Admin', 'admin@biletgec.com', $admin_hash, '05001234567', 'admin']);
    $messages[] = ['success', 'Admin kullanıcı oluşturuldu: admin@biletgec.com / admin123'];

    // ──────────────────────────────────────────
    // 5. Demo etkinlikler (12 adet)
    // ──────────────────────────────────────────
    $events = [
        [
            'category_id'       => 1, // Tiyatro
            'title'             => 'Hamlet - Shakespeare Klasiği',
            'description'       => 'William Shakespeare\'ın ölümsüz eseri Hamlet, İstanbul Devlet Tiyatrosu\'nun usta oyuncuları tarafından sahneye taşınıyor. Danimarka Prensi Hamlet\'in intikam, sadakat ve varoluş arasındaki çarpıcı yolculuğunu kaçırmayın. Oyun 2 perde ve 1 ara ile yaklaşık 2.5 saat sürmektedir.',
            'short_description' => 'Shakespeare\'ın başyapıtı Hamlet, usta kadrosuyla İstanbul\'da sahnede.',
            'venue'             => 'İstanbul Devlet Tiyatrosu - Büyük Sahne',
            'city'              => 'İstanbul',
            'event_date'        => '2026-06-05 20:00:00',
            'end_date'          => '2026-06-05 22:30:00',
            'price'             => 250.00,
            'total_capacity'    => 300,
            'image_url'         => 'assets/images/event_1.jpg',
            'organizer'         => 'İstanbul Devlet Tiyatrosu',
        ],
        [
            'category_id'       => 2, // Konser
            'title'             => 'Caz Festivali - Açık Hava Konseri',
            'description'       => 'İstanbul\'un en güzel açık hava mekanında unutulmaz bir caz gecesi! Türkiye\'nin ve dünya caz sahnesinin önde gelen sanatçıları, yaz akşamının büyüleyici atmosferinde performans sergileyecek. Konser öncesi kokteyl ve artisan yiyecek stantları da alanında sizleri bekliyor olacak.',
            'short_description' => 'Açık havada unutulmaz bir caz gecesi, yıldızların altında müzik keyfi.',
            'venue'             => 'Harbiye Açık Hava Tiyatrosu',
            'city'              => 'İstanbul',
            'event_date'        => '2026-06-12 21:00:00',
            'end_date'          => '2026-06-12 23:30:00',
            'price'             => 350.00,
            'total_capacity'    => 500,
            'image_url'         => 'assets/images/event_2.jpg',
            'organizer'         => 'Caz Derneği İstanbul',
        ],
        [
            'category_id'       => 3, // Atölye
            'title'             => 'Seramik Sanatı Atölyesi',
            'description'       => 'Elleriyle yaratmanın keyfini çıkar! Bu atölyede seramiğin temel tekniklerini öğrenecek, kendi kaseni ve tabağını tasarlayacaksınız. Tüm malzemeler organizatör tarafından karşılanır. Başlangıç seviyesindeki katılımcılar için de uygundur. Atölye sonunda eserleriniz fırınlanarak size teslim edilecektir.',
            'short_description' => 'Kendi seramik eserlerinizi yaratın, tüm malzemeler dahil.',
            'venue'             => 'Çankaya Sanat Merkezi',
            'city'              => 'Ankara',
            'event_date'        => '2026-06-18 14:00:00',
            'end_date'          => '2026-06-18 17:00:00',
            'price'             => 180.00,
            'total_capacity'    => 20,
            'image_url'         => 'assets/images/event_3.jpg',
            'organizer'         => 'Ankara Sanat Atölyesi',
        ],
        [
            'category_id'       => 4, // Yoga & Wellness
            'title'             => 'Gün Doğumu Yoga Kampı',
            'description'       => 'Antalya\'nın turkuaz sularına bakan muhteşem bir terasta gün doğumunda yoga yapın. Deneyimli eğitmenler eşliğinde Hatha ve Vinyasa yoga seansları, meditasyon ve nefes teknikleri çalışılacak. Kahvaltı dahildir. Tüm seviyelere uygundur.',
            'short_description' => 'Deniz manzaralı terasta gün doğumuyla yoga ve meditasyon.',
            'venue'             => 'Kaleiçi Wellness Terası',
            'city'              => 'Antalya',
            'event_date'        => '2026-06-22 06:00:00',
            'end_date'          => '2026-06-22 09:00:00',
            'price'             => 120.00,
            'total_capacity'    => 30,
            'image_url'         => 'assets/images/event_4.jpg',
            'organizer'         => 'Zen Yoga Antalya',
        ],
        [
            'category_id'       => 5, // Şehir Turu
            'title'             => 'Tarihi Yarımada Yürüyüş Turu',
            'description'       => 'İstanbul\'un kalbinde 3000 yıllık tarihin izlerini keşfedin. Ayasofya, Sultanahmet Camii, Yerebatan Sarnıcı ve Topkapı Sarayı çevresinde profesyonel rehber eşliğinde yürüyüş turu. Tur sırasında Osmanlı ve Bizans dönemlerine ait anekdotlar ve bilinen hikayelerin ötesindeki gizli detayları öğreneceksiniz.',
            'short_description' => 'Profesyonel rehber eşliğinde Sultanahmet ve çevresini keşfedin.',
            'venue'             => 'Sultanahmet Meydanı Buluşma Noktası',
            'city'              => 'İstanbul',
            'event_date'        => '2026-06-28 10:00:00',
            'end_date'          => '2026-06-28 13:00:00',
            'price'             => 75.00,
            'total_capacity'    => 25,
            'image_url'         => 'assets/images/event_5.jpg',
            'organizer'         => 'İstanbul Kültür Turları',
        ],
        [
            'category_id'       => 6, // Workshop
            'title'             => 'Yapay Zekâ ve Prompt Mühendisliği Workshop',
            'description'       => 'Yapay zekâ araçlarını profesyonel hayatınızda nasıl verimli kullanabileceğinizi öğrenin. ChatGPT, Midjourney ve diğer AI araçlarıyla etkili prompt yazma teknikleri, iş süreçlerinde otomasyon ve üretkenlik artırma stratejileri bu workshopta ele alınacak. Katılımcılara sertifika verilecektir.',
            'short_description' => 'AI araçlarını etkili kullanma ve prompt yazma teknikleri.',
            'venue'             => 'Workinton Levent',
            'city'              => 'İstanbul',
            'event_date'        => '2026-07-03 10:00:00',
            'end_date'          => '2026-07-03 16:00:00',
            'price'             => 500.00,
            'total_capacity'    => 40,
            'image_url'         => 'assets/images/event_6.jpg',
            'organizer'         => 'TechAcademy TR',
        ],
        [
            'category_id'       => 7, // Stand-up
            'title'             => 'Komedi Gecesi - Açık Mikrofon',
            'description'       => 'Türkiye\'nin en sevilen stand-up komedyenlerinin sahne aldığı eğlenceli bir gece! Hem tanınmış isimler hem de yükselen yetenekler sahnede olacak. Açık mikrofon bölümünde siz de sahneye çıkma şansı yakalayabilirsiniz. Bar menüsü mevcuttur.',
            'short_description' => 'Sevilen komedyenlerle kahkaha dolu bir akşam.',
            'venue'             => 'BKM Mutfak Ankara',
            'city'              => 'Ankara',
            'event_date'        => '2026-07-10 20:30:00',
            'end_date'          => '2026-07-10 23:00:00',
            'price'             => 150.00,
            'total_capacity'    => 120,
            'image_url'         => 'assets/images/event_7.jpg',
            'organizer'         => 'BKM Mutfak',
        ],
        [
            'category_id'       => 8, // Sinema
            'title'             => 'Açık Hava Sinema Gecesi - Klasik Film Gösterimi',
            'description'       => 'Yaz akşamının serin esintisinde nostaljik bir açık hava sinema deneyimi yaşayın. Bu hafta klasik Yeşilçam filmlerinden bir seçki gösterilecek. Mısır patlatma ve içecekler alanda satışta olacaktır. Kendi minderinizi veya sandalyenizi getirebilirsiniz.',
            'short_description' => 'Yıldızların altında nostaljik Yeşilçam film keyfi.',
            'venue'             => 'Kordon Açık Hava Sinema Alanı',
            'city'              => 'İzmir',
            'event_date'        => '2026-07-15 21:30:00',
            'end_date'          => '2026-07-15 23:45:00',
            'price'             => 50.00,
            'total_capacity'    => 200,
            'image_url'         => 'assets/images/event_8.jpg',
            'organizer'         => 'İzmir Sinema Derneği',
        ],
        [
            'category_id'       => 2, // Konser
            'title'             => 'Akustik Gece - Anadolu Rock',
            'description'       => 'Anadolu Rock\'un efsanevi şarkıları, genç ve yetenekli müzisyenler tarafından akustik düzenlemelerle yeniden yorumlanıyor. Barış Manço, Cem Karaca, Erkin Koray ve Moğollar\'ın klasikleri samimi bir ortamda seslendirilecek. Sınırlı sayıda koltuk.',
            'short_description' => 'Anadolu Rock klasikleri akustik düzenlemelerle sahnede.',
            'venue'             => 'Bursa Kültürpark Amfi Tiyatro',
            'city'              => 'Bursa',
            'event_date'        => '2026-07-20 20:00:00',
            'end_date'          => '2026-07-20 22:30:00',
            'price'             => 200.00,
            'total_capacity'    => 150,
            'image_url'         => 'assets/images/event_9.jpg',
            'organizer'         => 'Bursa Müzik Derneği',
        ],
        [
            'category_id'       => 3, // Atölye
            'title'             => 'Ebru Sanatı Deneyimi',
            'description'       => 'Suyun üzerine resim yapma sanatı olan Ebru\'yu deneyimli ustalardan öğrenin. Geleneksel Osmanlı sanatı olan Ebru\'nun temel tekniklerini uygulamalı olarak çalışacak, kendi eserlerinizi oluşturacaksınız. Atölye sonunda eserler kurutularak teslim edilir. Her yaştan katılımcıya uygundur.',
            'short_description' => 'Geleneksel Türk Ebru sanatını ustalardan öğrenin.',
            'venue'             => 'Osmanlı Sanat Evi',
            'city'              => 'İstanbul',
            'event_date'        => '2026-07-25 11:00:00',
            'end_date'          => '2026-07-25 14:00:00',
            'price'             => 160.00,
            'total_capacity'    => 15,
            'image_url'         => 'assets/images/event_10.jpg',
            'organizer'         => 'Osmanlı Sanat Evi',
        ],
        [
            'category_id'       => 1, // Tiyatro
            'title'             => 'Kukla Tiyatrosu - Karagöz ve Hacivat',
            'description'       => 'Çocuklar ve yetişkinler için geleneksel Karagöz ve Hacivat gösteri. Osmanlı döneminden günümüze ulaşan bu eşsiz sanat formunu deneyimli kukla ustaları sunuyor. Gösteride hem geleneksel hikayeler hem de günümüze uyarlanmış komik sketchler yer alacak.',
            'short_description' => 'Geleneksel Karagöz ve Hacivat gösterisi, her yaşa uygun.',
            'venue'             => 'Antalya Kültür Merkezi',
            'city'              => 'Antalya',
            'event_date'        => '2026-08-02 15:00:00',
            'end_date'          => '2026-08-02 16:30:00',
            'price'             => 80.00,
            'total_capacity'    => 100,
            'image_url'         => 'assets/images/event_11.jpg',
            'organizer'         => 'Antalya Geleneksel Sanatlar Derneği',
        ],
        [
            'category_id'       => 4, // Yoga & Wellness
            'title'             => 'Hafta Sonu Meditasyon Retreati',
            'description'       => 'Şehrin karmaşasından uzaklaşıp iç huzuru bulmak isteyenler için tasarlanmış bir günlük meditasyon ve farkındalık çalışması. Sabah yoga seansı, rehberli meditasyon, nefes çalışmaları ve doğa yürüyüşü programda yer almaktadır. Organik öğle yemeği dahildir.',
            'short_description' => 'Bir günlük meditasyon ve farkındalık retreati, öğle yemeği dahil.',
            'venue'             => 'Uludağ Yamaç Wellness Merkezi',
            'city'              => 'Bursa',
            'event_date'        => '2026-08-10 08:00:00',
            'end_date'          => '2026-08-10 17:00:00',
            'price'             => 300.00,
            'total_capacity'    => 25,
            'image_url'         => 'assets/images/event_12.jpg',
            'organizer'         => 'Uludağ Wellness',
        ],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO events (category_id, title, description, short_description, venue, city, event_date, end_date, price, total_capacity, image_url, organizer, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );

    foreach ($events as $event) {
        $stmt->execute([
            $event['category_id'],
            $event['title'],
            $event['description'],
            $event['short_description'],
            $event['venue'],
            $event['city'],
            $event['event_date'],
            $event['end_date'],
            $event['price'],
            $event['total_capacity'],
            $event['image_url'],
            $event['organizer'],
            'active',
        ]);
    }
    $messages[] = ['success', count($events) . ' demo etkinlik eklendi.'];

    $messages[] = ['success', '✅ Kurulum başarıyla tamamlandı!'];

} catch (PDOException $e) {
    $hasError = true;
    $messages[] = ['error', 'Veritabanı hatası: ' . $e->getMessage()];
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bilet-Geç - Veritabanı Kurulumu</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0a0a1a 0%, #1a1a2e 50%, #0a0a1a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            max-width: 700px;
            width: 100%;
        }

        .card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            padding: 40px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        .logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo h1 {
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, #7c3aed, #06b6d4);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .logo p {
            color: rgba(255, 255, 255, 0.5);
            font-size: 0.9rem;
            margin-top: 5px;
        }

        .message {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 10px;
            font-size: 0.9rem;
            line-height: 1.5;
            animation: fadeIn 0.3s ease forwards;
            opacity: 0;
        }

        .message:nth-child(1) { animation-delay: 0.05s; }
        .message:nth-child(2) { animation-delay: 0.1s; }
        .message:nth-child(3) { animation-delay: 0.15s; }
        .message:nth-child(4) { animation-delay: 0.2s; }
        .message:nth-child(5) { animation-delay: 0.25s; }
        .message:nth-child(6) { animation-delay: 0.3s; }
        .message:nth-child(7) { animation-delay: 0.35s; }
        .message:nth-child(8) { animation-delay: 0.4s; }
        .message:nth-child(9) { animation-delay: 0.45s; }
        .message:nth-child(10) { animation-delay: 0.5s; }
        .message:nth-child(11) { animation-delay: 0.55s; }
        .message:nth-child(12) { animation-delay: 0.6s; }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .message.success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: #6ee7b7;
        }

        .message.error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }

        .message .icon {
            font-size: 1.1rem;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .message.success .icon::before { content: '✓'; }
        .message.error .icon::before { content: '✗'; }

        .footer {
            text-align: center;
            margin-top: 30px;
        }

        .footer a {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, #7c3aed, #06b6d4);
            color: #fff;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.95rem;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .footer a:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(124, 58, 237, 0.4);
        }

        .info {
            margin-top: 20px;
            padding: 16px 20px;
            background: rgba(124, 58, 237, 0.08);
            border: 1px solid rgba(124, 58, 237, 0.2);
            border-radius: 12px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.85rem;
            line-height: 1.7;
        }

        .info strong {
            color: #c4b5fd;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="logo">
                <h1>🎫 Bilet-Geç</h1>
                <p>Veritabanı Kurulum Sihirbazı</p>
            </div>

            <div class="messages">
                <?php foreach ($messages as $msg): ?>
                    <div class="message <?= $msg[0] ?>">
                        <span class="icon"></span>
                        <span><?= htmlspecialchars($msg[1], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (!$hasError): ?>
                <div class="info">
                    <strong>Admin Girişi:</strong><br>
                    E-posta: admin@biletgec.com<br>
                    Şifre: admin123<br><br>
                    <strong>Veritabanı:</strong> biletgec<br>
                    <strong>Tablolar:</strong> users, categories, events, reservations, tickets<br>
                    <strong>Kategoriler:</strong> 8 adet &bull; <strong>Etkinlikler:</strong> 12 adet
                </div>

                <div class="footer">
                    <a href="/ilterhoca/">Ana Sayfaya Git →</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
