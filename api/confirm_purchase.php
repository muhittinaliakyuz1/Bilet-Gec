<?php
/**
 * Bilet-Geç API - Satın Alma Onayı
 * POST JSON: { reservation_id }
 * Returns: { success, ticket_code, message }
 */

define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

start_secure_session();

header('Content-Type: application/json; charset=utf-8');

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Sadece POST istekleri kabul edilir.']);
        exit;
    }

    // Require authentication
    require_login_api();

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

    $user_id = get_current_user_id();

    // Parse JSON body
    $input = json_decode(file_get_contents('php://input'), true);

    if ($input === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Geçersiz JSON verisi.']);
        exit;
    }

    // Validate reservation_id
    if (!isset($input['reservation_id']) || !is_numeric($input['reservation_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Geçerli bir reservation_id gereklidir.']);
        exit;
    }

    $reservation_id = (int)$input['reservation_id'];

    // Verify the reservation belongs to this user and check its status
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT id, user_id, status, expires_at 
        FROM reservations 
        WHERE id = ?
    ");
    $stmt->execute([$reservation_id]);
    $reservation = $stmt->fetch();

    if (!$reservation) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Rezervasyon bulunamadı.']);
        exit;
    }

    // Check ownership
    if ((int)$reservation['user_id'] !== $user_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Bu rezervasyon size ait değil.']);
        exit;
    }

    // Check if pending
    if ($reservation['status'] !== 'pending') {
        $status_messages = [
            'confirmed' => 'Bu rezervasyon zaten onaylanmış.',
            'expired'   => 'Bu rezervasyonun süresi dolmuş.',
            'cancelled' => 'Bu rezervasyon iptal edilmiş.',
        ];
        $msg = $status_messages[$reservation['status']] ?? 'Geçersiz rezervasyon durumu.';
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }

    // Check if expired by time
    if (strtotime($reservation['expires_at']) < time()) {
        // Mark as expired
        $stmt = $pdo->prepare("UPDATE reservations SET status = 'expired' WHERE id = ?");
        $stmt->execute([$reservation_id]);

        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Rezervasyon süresi dolmuş. Lütfen yeniden bilet ayırtın.']);
        exit;
    }

    // Confirm the reservation and create ticket
    $result = confirm_reservation($pdo, $reservation_id, $user_id);

    if ($result === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ödeme onaylanırken biletler tükendi veya rezervasyon süreniz doldu! İşlem iptal edildi.']);
        exit;
    }

    // Send confirmation email for registered users
    $stmt = $pdo->prepare(
        'SELECT r.id AS reservation_id, r.quantity, t.total_price, t.ticket_code,
                e.title AS event_title, e.event_date, e.venue, e.city,
                u.full_name, u.email
         FROM reservations r
         JOIN tickets t ON t.reservation_id = r.id
         JOIN events e ON r.event_id = e.id
         JOIN users u ON r.user_id = u.id
         WHERE r.id = ?'
    );
    $stmt->execute([$reservation_id]);
    $reservationData = $stmt->fetch();

    if ($reservationData && filter_var($reservationData['email'], FILTER_VALIDATE_EMAIL)) {
        $email = $reservationData['email'];
        $name = trim($reservationData['full_name'] ?: $email);
        $subject = '🎫 Bilet Onayınız - ' . $reservationData['event_title'];

        $ticketHtml = '<p><strong>Bilet Kodu:</strong> ' . htmlspecialchars($reservationData['ticket_code'] ?? $result['ticket_code']) . '</p>';
        if ((int)$reservationData['quantity'] > 1) {
            $ticketHtml = '<p><strong>Bilet Kodu:</strong> ' . htmlspecialchars($reservationData['ticket_code'] ?? $result['ticket_code']) . ' (toplam ' . (int)$reservationData['quantity'] . ' bilet)</p>';
        }

        $message = '<html><body style="font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0;">'
            . '<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding: 20px;">'
            . '<table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden;">'
            . '<tr><td style="background-color: #10b981; padding: 24px; color: #ffffff; text-align: center; font-size: 24px; font-weight: bold;">Bilet Onayınız</td></tr>'
            . '<tr><td style="padding: 24px; color: #333333;">'
            . '<p style="margin: 0 0 16px;">Merhaba ' . htmlspecialchars($name) . ',</p>'
            . '<p style="margin: 0 0 16px;">Rezervasyonunuz başarıyla onaylandı. Aşağıdaki bilgiler biletleriniz için geçerlidir:</p>'
            . '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px;">'
            . '<tr><td style="padding: 16px;">'
            . '<p style="margin: 0 0 8px;"><strong>Etkinlik:</strong> ' . htmlspecialchars($reservationData['event_title']) . '</p>'
            . '<p style="margin: 0 0 8px;"><strong>Tarih:</strong> ' . htmlspecialchars(date('d M Y H:i', strtotime($reservationData['event_date']))) . '</p>'
            . '<p style="margin: 0 0 8px;"><strong>Konum:</strong> ' . htmlspecialchars($reservationData['venue']) . ', ' . htmlspecialchars($reservationData['city']) . '</p>'
            . '<p style="margin: 0 0 8px;"><strong>Adet:</strong> ' . (int)$reservationData['quantity'] . '</p>'
            . '<p style="margin: 0 0 8px;"><strong>Toplam Tutar:</strong> ' . number_format((float)$reservationData['total_price'], 2, ',', '.') . ' TL</p>'
            . '<p style="margin: 0 0 0;">' . $ticketHtml . '</p>'
            . '</td></tr>'
            . '</table>'
            . '<p style="margin: 24px 0 0;">Biletlerinizi etkinlik girişinde gösterebilirsiniz. İyi eğlenceler!</p>'
            . '</td></tr>'
            . '</table>'
            . '</td></tr></table>'
            . '</body></html>';

        send_mail_message($email, $subject, $message);
    }

    echo json_encode([
        'success'     => true,
        'ticket_code' => $result['ticket_code'],
        'total_price' => $result['total_price'],
        'quantity'    => $result['quantity'],
        'message'     => 'Biletiniz onaylandı!',
    ]);

} catch (Exception $e) {
    error_log('confirm_purchase API hatası: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Sunucu hatası oluştu.']);
}
