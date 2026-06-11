<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

start_secure_session();
ensure_guest_schema($pdo);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Yöntem desteklenmiyor.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$token = isset($input['token']) ? trim($input['token']) : '';

if (empty($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Token eksik.']);
    exit;
}

// CSRF doğrulama
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!$csrf) {
    $csrf = $input['csrf_token'] ?? null;
}
if (!verify_csrf_token($csrf ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Geçersiz CSRF token.']);
    exit;
}

try {
    // Verify guest reservation with token
    $stmt = $pdo->prepare("
        SELECT * FROM guest_reservations 
        WHERE token = ? AND expires_at > NOW() AND status = 'pending'
    ");
    $stmt->execute([$token]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reservation) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Geçersiz veya süresi dolmuş token.']);
        exit;
    }

    // Check event exists
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$reservation['event_id']]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Etkinlik bulunamadı.']);
        exit;
    }

    // Check availability
    $remaining = get_remaining_capacity($pdo, $reservation['event_id']);
    if ($remaining < $reservation['quantity']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Yeterli bilet kalmadı.']);
        exit;
    }

    // Find existing user by email, or create a guest user if none exists.
    $guest_user_id = null;
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$reservation['email']]);
    $existing_user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing_user) {
        $guest_user_id = $existing_user['id'];
    } else {
        // Create guest user
        $name = $reservation['name'] ?: 'Guest User';
        $stmt = $pdo->prepare("
            INSERT INTO users (email, full_name, password_hash, is_guest, created_at)
            VALUES (?, ?, ?, 1, NOW())
        ");
        // Use a dummy hash for guest users
        $dummy_hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
        $stmt->execute([$reservation['email'], $name, $dummy_hash]);
        $guest_user_id = $pdo->lastInsertId();
    }

    // Create a confirmed reservation record so ticket rows can safely reference it
    $stmt = $pdo->prepare(
        'INSERT INTO reservations (user_id, event_id, quantity, status, reserved_at, confirmed_at) VALUES (?, ?, ?, ?, NOW(), NOW())'
    );
    $stmt->execute([$guest_user_id, $reservation['event_id'], $reservation['quantity'], 'confirmed']);
    $confirmed_reservation_id = $pdo->lastInsertId();

    // Create tickets
    $ticket_codes = [];
    for ($i = 0; $i < $reservation['quantity']; $i++) {
        $ticket_code = strtoupper(bin2hex(random_bytes(6)));
        
        $stmt = $pdo->prepare(
            'INSERT INTO tickets (reservation_id, user_id, event_id, ticket_code, quantity, total_price, purchased_at) VALUES (?, ?, ?, ?, 1, ?, NOW())'
        );
        $stmt->execute([
            $confirmed_reservation_id,
            $guest_user_id,
            $reservation['event_id'],
            $ticket_code,
            round($reservation['total_price'] / max(1, $reservation['quantity']), 2)
        ]);
        $ticket_codes[] = $ticket_code;
    }

    // Mark reservation as completed
    $stmt = $pdo->prepare("
        UPDATE guest_reservations 
        SET status = 'completed', user_id = ?
        WHERE token = ?
    ");
    $stmt->execute([$guest_user_id, $token]);

    // Send confirmation email
    $subject = "🎫 Bilet Satın Alma Onayı - " . htmlspecialchars($event['title']);
    $event_date = date('d M Y H:i', strtotime($event['event_date']));
    
    $tickets_html = '';
    foreach ($ticket_codes as $code) {
        $tickets_html .= "<p style='margin: 8px 0;'><strong>🎫 Bilet Kodu:</strong> <code style='background-color: #f5f5f5; padding: 5px 10px; border-radius: 3px; font-family: monospace;'>" . htmlspecialchars($code) . "</code></p>";
    }

    $message = "
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; background-color: #f5f5f5; }
            .container { max-width: 600px; margin: 20px auto; background-color: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
            .header { text-align: center; color: #0f172a; margin-bottom: 30px; }
            .header h1 { margin: 0; font-size: 1.8rem; color: #10b981; }
            .content { color: #333; line-height: 1.6; }
            .details { background-color: #f9f9f9; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981; }
            .details p { margin: 8px 0; }
            .details strong { color: #0f172a; }
            .tickets-section { background-color: #f0fdf4; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 0.9rem; color: #666; text-align: center; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>✅ Ödeme Başarılı!</h1>
                <p>Biletiniz hazırlandı</p>
            </div>
            
            <div class='content'>
                " . ($reservation['name'] ? "<p>Merhaba <strong>" . htmlspecialchars($reservation['name']) . "</strong>,</p>" : "<p>Merhaba,</p>") . "
                
                <p>Bilet satın alma işleminiz başarıyla tamamlanmıştır. Lütfen bilet kodlarınızı saklayın.</p>
                
                <div class='details'>
                    <p><strong>📍 Etkinlik:</strong> " . htmlspecialchars($event['title']) . "</p>
                    <p><strong>📅 Tarih:</strong> " . $event_date . "</p>
                    <p><strong>💰 Ödenen Tutar:</strong> ₺" . number_format($reservation['total_price'], 2, ',', '.') . "</p>
                </div>

                <div class='tickets-section'>
                    <h3 style='margin-top: 0; color: #10b981;'>🎫 Bilet Kodlarınız:</h3>
                    " . $tickets_html . "
                    <p style='margin-top: 15px; font-size: 0.9rem; color: #666;'>
                        Bu kodları etkinlik günü kapıda gösteriniz. Çıktı alarak veya telefon ekranından gösterebilirsiniz.
                    </p>
                </div>
                
                <p style='background-color: #fff8e1; padding: 15px; border-radius: 5px; border-left: 4px solid #f59e0b; margin: 20px 0;'>
                    <strong>⚠️ Önemli:</strong> Bilet kodlarınızı kimseyle paylaşmayın. Her bir kod bir bilete karşılık gelir.
                </p>
            </div>
            
            <div class='footer'>
                <p>Herhangi bir sorun yaşarsanız lütfen destek ekibimizle iletişime geçin.</p>
                <p style='margin-top: 15px; color: #999;'>&copy; 2026 Bilet-Geç. Tüm hakları saklıdır.</p>
            </div>
        </div>
    </body>
    </html>
    ";

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: noreply@biletgec.com\r\n";

    // Send email
    send_mail_message($reservation['email'], $subject, $message);

    echo json_encode([
        'success' => true,
        'message' => 'Ödeme başarıyla alındı.',
        'token' => $token,
        'ticket_codes' => $ticket_codes
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Bir hata oluştu: ' . $e->getMessage()
    ]);
}
