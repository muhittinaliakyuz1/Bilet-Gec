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

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['event_id'], $input['email'], $input['quantity'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Eksik parametreler.']);
    exit;
}

$event_id = (int)$input['event_id'];
$email = trim($input['email']);
$name = trim($input['name'] ?? '');
$quantity = max(1, min(10, (int)$input['quantity']));

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Geçersiz e-posta adresi.']);
    exit;
}

// CSRF doğrulama
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!$csrf) {
    $raw = json_decode(file_get_contents('php://input'), true);
    $csrf = $raw['csrf_token'] ?? null;
}
if (!verify_csrf_token($csrf ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Geçersiz CSRF token.']);
    exit;
}

// Check event exists
$stmt = $pdo->prepare("SELECT id, title, price, event_date FROM events WHERE id = ?");
$stmt->execute([$event_id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Etkinlik bulunamadı.']);
    exit;
}

// Check availability
$remaining = get_remaining_capacity($pdo, $event_id);
if ($remaining < $quantity) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Yeterli bilet kalmadı.']);
    exit;
}

try {
    // Create guest reservation with unique token
    $token = bin2hex(random_bytes(32));
    $total_price = (float)$event['price'] * $quantity;

        $stmt = $pdo->prepare("
            INSERT INTO guest_reservations (event_id, email, name, quantity, total_price, token, expires_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), NOW())
        ");
        $stmt->execute([$event_id, $email, $name, $quantity, $total_price, $token]);
        $reservation_id = $pdo->lastInsertId();
    $payment_link = "http://" . $_SERVER['HTTP_HOST'] . "/ilterhoca/guest_payment.php?token=" . urlencode($token);

    // Send email with payment link
    $subject = "🎫 Bilet Satın Alma - " . htmlspecialchars($event['title']);
    $event_date = date('d M Y H:i', strtotime($event['event_date']));
    
    $message = "
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; background-color: #f5f5f5; }
            .container { max-width: 600px; margin: 20px auto; background-color: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
            .header { text-align: center; color: #0f172a; margin-bottom: 30px; }
            .header h1 { margin: 0; font-size: 1.8rem; }
            .content { color: #333; line-height: 1.6; }
            .details { background-color: #f9f9f9; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981; }
            .details p { margin: 8px 0; }
            .details strong { color: #0f172a; }
            .button { display: inline-block; background-color: #10b981; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; margin: 20px 0; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 0.9rem; color: #666; text-align: center; }
            .timer { color: #ef4444; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🎫 Bilet Satın Alma</h1>
                <p>Ödeme işlemini tamamlamak için aşağıdaki butona tıklayın</p>
            </div>
            
            <div class='content'>
                " . ($name ? "<p>Merhaba <strong>" . htmlspecialchars($name) . "</strong>,</p>" : "<p>Merhaba,</p>") . "
                
                <p>Aşağıdaki etkinlik için bilet satın almak istediğiniz için teşekkür ederiz.</p>
                
                <div class='details'>
                    <p><strong>📍 Etkinlik:</strong> " . htmlspecialchars($event['title']) . "</p>
                    <p><strong>📅 Tarih:</strong> " . $event_date . "</p>
                    <p><strong>🎫 Bilet Adet:</strong> " . $quantity . "</p>
                    <p><strong>💰 Toplam Tutar:</strong> ₺" . number_format($total_price, 2, ',', '.') . "</p>
                    <p><strong>⏰ Geçerlilik:</strong> <span class='timer'>30 dakika</span></p>
                </div>
                
                <p style='text-align: center;'>
                    <a href='" . $payment_link . "' class='button'>💳 Ödeme Sayfasına Git</a>
                </p>
                
                <p style='color: #999; font-size: 0.9rem;'>
                    Eğer butona tıklayamazsanız, aşağıdaki linki tarayıcınıza kopyalayın:<br>
                    <code style='background-color: #f5f5f5; padding: 5px 10px; border-radius: 3px;'>" . $payment_link . "</code>
                </p>
            </div>
            
            <div class='footer'>
                <p>Bu e-posta 30 dakika içinde geçerlidir. Lütfen bu süre içinde ödemeyi tamamlayın.</p>
                <p style='margin-top: 15px; color: #999;'>&copy; 2026 Bilet-Geç. Tüm hakları saklıdır.</p>
            </div>
        </div>
    </body>
    </html>
    ";

    $paymentUrl = '/ilterhoca/guest_payment.php?token=' . urlencode($token);

    echo json_encode([
        'success' => true,
        'message' => 'Rezervasyon oluşturuldu. Ödeme sayfasına yönlendiriliyorsunuz.',
        'reservation_id' => $reservation_id,
        'token' => $token,
        'payment_url' => $paymentUrl
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Bir hata oluştu: ' . $e->getMessage()
    ]);
}
