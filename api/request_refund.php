<?php
/**
 * Bilet-Geç API - İade Talebini Oluştur
 * POST JSON: { ticket_id, refund_amount, reason, description }
 * Returns: { success, refund_id, message }
 */

define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/refund_functions.php';

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

    // Validate required fields
    if (!isset($input['ticket_id']) || !is_numeric($input['ticket_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Geçerli bir ticket_id gereklidir.']);
        exit;
    }


    if (!isset($input['reason']) || empty(trim($input['reason']))) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'İade nedeni belirtilmelidir.']);
        exit;
    }

    $ticket_id = (int)$input['ticket_id'];
    // İade tutarı kullanıcı tarafından belirlenemez; sunucuda bilet toplam tutarı kullanılacaktır
    $refund_amount = 0.0;
    $reason = trim($input['reason']);
    $description = isset($input['description']) ? trim($input['description']) : '';

    global $pdo;

    // Verify ticket exists and belongs to user (include event date)
    $stmt = $pdo->prepare("SELECT t.*, e.event_date, e.title AS event_title FROM tickets t JOIN events e ON t.event_id = e.id WHERE t.id = ? AND t.user_id = ?");
    $stmt->execute([$ticket_id, $user_id]);
    $ticket = $stmt->fetch();

    if (!$ticket) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Bilet bulunamadı veya size ait değil.']);
        exit;
    }

    // Etkinlik tarihini kontrol et: iade yalnızca etkinlikten en az 24 saat önce kabul edilir
    try {
        if (!empty($ticket['event_date'])) {
            $eventDate = new DateTime($ticket['event_date']);
            $now = new DateTime();
            $cutoff = (clone $now)->modify('+24 hours');

            if ($eventDate <= $now) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Etkinlik geçmiş, iade talebi oluşturulamaz.']);
                exit;
            }

            if ($eventDate <= $cutoff) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'İade talepleri etkinlikten en az 24 saat önce yapılmalıdır.']);
                exit;
            }
        }
    } catch (Exception $e) {
        // Tarih işlenemezse güvenlik nedeniyle isteği reddet
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Etkinlik tarihi doğrulanamadı.']);
        exit;
    }

    // Validate refund amount
    // Sunucuda iade tutarını biletin kayıtlı toplam tutarı olarak ayarla
    $refund_amount = (float)$ticket['total_price'];

    // Create refund request
    $refund = create_refund_request($pdo, $ticket_id, $user_id, $refund_amount, $reason, $description);

    if ($refund === false) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => 'İade talebı oluşturulamadı. Bu bilet için zaten bir iade talebiniz olabilir.'
        ]);
        exit;
    }

    // Get ticket details for email
    $stmt = $pdo->prepare(
        'SELECT t.ticket_code, e.title AS event_title, e.event_date, e.venue, e.city, u.email, u.full_name
         FROM tickets t
         JOIN events e ON t.event_id = e.id
         JOIN users u ON t.user_id = u.id
         WHERE t.id = ?'
    );
    $stmt->execute([$ticket_id]);
    $details = $stmt->fetch();

    // Send confirmation email to user
    if ($details && filter_var($details['email'], FILTER_VALIDATE_EMAIL)) {
        $email = $details['email'];
        $name = trim($details['full_name'] ?: $email);
        $subject = '📋 İade Talebiniz Alındı - ' . $details['event_title'];

        $message = '<html><body style="font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0;">'
            . '<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding: 20px;">'
            . '<table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden;">'
            . '<tr><td style="background-color: #3b82f6; padding: 24px; color: #ffffff; text-align: center; font-size: 24px; font-weight: bold;">İade Talebiniz Alındı</td></tr>'
            . '<tr><td style="padding: 24px; color: #333333;">'
            . '<p style="margin: 0 0 16px;">Merhaba ' . htmlspecialchars($name) . ',</p>'
            . '<p style="margin: 0 0 16px;">İade talebiniz başarıyla alınmıştır. Talebiniz incelenmek üzere yöneticilere iletilmiştir.</p>'
            . '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px; margin: 16px 0;">'
            . '<tr><td style="padding: 16px;">'
            . '<p style="margin: 0 0 8px;"><strong>Etkinlik:</strong> ' . htmlspecialchars($details['event_title']) . '</p>'
            . '<p style="margin: 0 0 8px;"><strong>Tarih:</strong> ' . htmlspecialchars(date('d M Y H:i', strtotime($details['event_date']))) . '</p>'
            . '<p style="margin: 0 0 8px;"><strong>Bilet Kodu:</strong> ' . htmlspecialchars($details['ticket_code']) . '</p>'
            . '<p style="margin: 0 0 8px;"><strong>İade Tutarı:</strong> ₺' . number_format($refund_amount, 2, ',', '.') . '</p>'
            . '<p style="margin: 0;"><strong>İade Nedeni:</strong> ' . htmlspecialchars($reason) . '</p>'
            . '</td></tr>'
            . '</table>'
            . '<p style="margin: 16px 0 0; color: #666;">İade işlemi hakkında güncelleme alacaksınız.</p>'
            . '</td></tr>'
            . '</table>'
            . '</td></tr></table>'
            . '</body></html>';

        send_mail_message($email, $subject, $message);
    }

    // Send notification to admin
    $stmt = $pdo->prepare("SELECT email FROM users WHERE role = ? LIMIT 1");
    $stmt->execute(['admin']);
    $admin = $stmt->fetch();
    if ($admin && filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
        $subject = '⚠️ YENİ İADE TALEP - ' . $details['event_title'];
        $message = '<html><body style="font-family: Arial, sans-serif;">'
            . '<p>Yeni bir iade talebı alınmıştır:</p>'
            . '<ul>'
            . '<li><strong>Kullanıcı:</strong> ' . htmlspecialchars($details['full_name']) . '</li>'
            . '<li><strong>Etkinlik:</strong> ' . htmlspecialchars($details['event_title']) . '</li>'
            . '<li><strong>Bilet Kodu:</strong> ' . htmlspecialchars($details['ticket_code']) . '</li>'
            . '<li><strong>İade Tutarı:</strong> ₺' . number_format($refund_amount, 2, ',', '.') . '</li>'
            . '<li><strong>Nedeni:</strong> ' . htmlspecialchars($reason) . '</li>'
            . '</ul>'
            . '<p><a href="' . BASE_URL . 'admin/manage_refunds.php">Firma Panelinde İncele</a></p>'
            . '</body></html>';
        send_mail_message($admin['email'], $subject, $message);
    }

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'refund_id' => (int)$refund['id'],
        'message' => 'İade talebiniz başarıyla oluşturulmuştur. En kısa sürede incelenmek üzere yöneticilere iletilmiştir.'
    ]);

} catch (Exception $e) {
    $errorContext = json_encode([
        'ticket_id' => $input['ticket_id'] ?? null,
        'user_id' => $user_id ?? null,
        'reason' => $input['reason'] ?? null,
        'description' => $input['description'] ?? null,
    ]);
    error_log('İade talebı oluşturma hatası: ' . $e->getMessage() . ' | context: ' . $errorContext . ' | trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Bir hata oluştu. Lütfen daha sonra tekrar deneyin.']);
    exit;
}
