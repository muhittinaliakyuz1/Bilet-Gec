<?php
/**
 * Bilet-Geç Admin API - İade Yönetimi
 * POST JSON: { action, refund_id, refund_method, transaction_id, rejection_reason }
 * Actions: approve, reject, complete
 */

define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/refund_functions.php';
require_once __DIR__ . '/../../includes/activity_log.php';

start_secure_session();
require_panel();

header('Content-Type: application/json; charset=utf-8');

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Sadece POST istekleri kabul edilir.']);
        exit;
    }

    $admin_id = get_current_user_id();

    // Parse JSON body
    $input = json_decode(file_get_contents('php://input'), true);

    if ($input === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Geçersiz JSON verisi.']);
        exit;
    }

    // CSRF doğrulaması (header: X-CSRF-Token)
    $csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X_CSRF'] ?? null;
    if (!$csrf_token || !verify_csrf_token($csrf_token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF doğrulaması başarısız.']);
        exit;
    }

    // Validate required fields
    if (!isset($input['action']) || empty($input['action'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'action parametresi gereklidir.']);
        exit;
    }

    if (!isset($input['refund_id']) || !is_numeric($input['refund_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'refund_id parametresi gereklidir.']);
        exit;
    }

    $action = strtolower(trim($input['action']));
    $refund_id = (int)$input['refund_id'];

    global $pdo;

    // Verify refund exists
    $stmt = $pdo->prepare("SELECT * FROM refunds WHERE id = ?");
    $stmt->execute([$refund_id]);
    $refund = $stmt->fetch();

    if (!$refund) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'İade talebı bulunamadı.']);
        exit;
    }

    // Firma kapsamı kontrolü
    if (!is_superadmin()) {
        $evStmt = $pdo->prepare('SELECT created_by FROM events WHERE id = ?');
        $evStmt->execute([$refund['event_id']]);
        $evRow = $evStmt->fetch();
        if (!$evRow || (int)$evRow['created_by'] !== $admin_id) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Bu iade talebine erişim yetkiniz yok.']);
            exit;
        }
    }

    switch ($action) {
        case 'approve':
            $refund_method = isset($input['refund_method']) ? trim($input['refund_method']) : 'card';
            if (!in_array($refund_method, ['card', 'wallet', 'bank_transfer'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Geçersiz refund_method.']);
                exit;
            }

            $result = approve_refund($pdo, $refund_id, $admin_id, $refund_method);
            if ($result === false) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'İade talebı onaylanamadı.']);
                exit;
            }

            // Send email to user
            $stmt = $pdo->prepare(
                'SELECT u.email, u.full_name, e.title AS event_title, t.ticket_code, r.refund_amount
                 FROM refunds r
                 JOIN users u ON r.user_id = u.id
                 JOIN events e ON r.event_id = e.id
                 JOIN tickets t ON r.ticket_id = t.id
                 WHERE r.id = ?'
            );
            $stmt->execute([$refund_id]);
            $details = $stmt->fetch();

            if ($details && filter_var($details['email'], FILTER_VALIDATE_EMAIL)) {
                $email = $details['email'];
                $name = trim($details['full_name'] ?: $email);
                $subject = '✅ İade Talebiniz Onaylandı - ' . $details['event_title'];

                $message = '<html><body style="font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0;">'
                    . '<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding: 20px;">'
                    . '<table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden;">'
                    . '<tr><td style="background-color: #10b981; padding: 24px; color: #ffffff; text-align: center; font-size: 24px; font-weight: bold;">İade Talebiniz Onaylandı</td></tr>'
                    . '<tr><td style="padding: 24px; color: #333333;">'
                    . '<p style="margin: 0 0 16px;">Merhaba ' . htmlspecialchars($name) . ',</p>'
                    . '<p style="margin: 0 0 16px;">İade talebiniz onaylanmıştır. Geri ödemeniz en kısa sürede hesabınıza yatırılacaktır.</p>'
                    . '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px; margin: 16px 0;">'
                    . '<tr><td style="padding: 16px;">'
                    . '<p style="margin: 0 0 8px;"><strong>İade Tutarı:</strong> ₺' . number_format($details['refund_amount'], 2, ',', '.') . '</p>'
                    . '<p style="margin: 0;"><strong>Yöntemi:</strong> ' . ucfirst($refund_method) . '</p>'
                    . '</td></tr>'
                    . '</table>'
                    . '<p style="margin: 16px 0 0; color: #666;">2-5 iş günü içinde geri ödemeniz alacaksınız.</p>'
                    . '</td></tr>'
                    . '</table>'
                    . '</td></tr></table>'
                    . '</body></html>';

                send_mail_message($email, $subject, $message);
            }

            log_activity($pdo, $admin_id, 'refund_approve', 'refund', $refund_id, ['method' => $refund_method]);

            echo json_encode([
                'success' => true,
                'refund' => $result,
                'message' => 'İade talebı başarıyla onaylandı.'
            ]);
            break;

        case 'reject':
            $rejection_reason = isset($input['rejection_reason']) ? trim($input['rejection_reason']) : 'Belirtilmedi';

            $result = reject_refund($pdo, $refund_id, $admin_id, $rejection_reason);
            if ($result === false) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'İade talebı reddedilemedi.']);
                exit;
            }

            // Send email to user
            $stmt = $pdo->prepare(
                'SELECT u.email, u.full_name, e.title AS event_title
                 FROM refunds r
                 JOIN users u ON r.user_id = u.id
                 JOIN events e ON r.event_id = e.id
                 WHERE r.id = ?'
            );
            $stmt->execute([$refund_id]);
            $details = $stmt->fetch();

            if ($details && filter_var($details['email'], FILTER_VALIDATE_EMAIL)) {
                $email = $details['email'];
                $name = trim($details['full_name'] ?: $email);
                $subject = '❌ İade Talebiniz Reddedildi - ' . $details['event_title'];

                $message = '<html><body style="font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0;">'
                    . '<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding: 20px;">'
                    . '<table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden;">'
                    . '<tr><td style="background-color: #ef4444; padding: 24px; color: #ffffff; text-align: center; font-size: 24px; font-weight: bold;">İade Talebiniz Reddedildi</td></tr>'
                    . '<tr><td style="padding: 24px; color: #333333;">'
                    . '<p style="margin: 0 0 16px;">Merhaba ' . htmlspecialchars($name) . ',</p>'
                    . '<p style="margin: 0 0 16px;">Ne yazık ki iade talebiniz reddedilmiştir.</p>'
                    . '<p style="margin: 0 0 16px;"><strong>Neden:</strong> ' . htmlspecialchars($rejection_reason) . '</p>'
                    . '<p style="margin: 16px 0 0; color: #666;">Sorularınız için bize iletişim kurabilirsiniz.</p>'
                    . '</td></tr>'
                    . '</table>'
                    . '</td></tr></table>'
                    . '</body></html>';

                send_mail_message($email, $subject, $message);
            }

            log_activity($pdo, $admin_id, 'refund_reject', 'refund', $refund_id, ['reason' => $rejection_reason]);

            echo json_encode([
                'success' => true,
                'refund' => $result,
                'message' => 'İade talebı başarıyla reddedildi.'
            ]);
            break;

        case 'complete':
            $transaction_id = isset($input['transaction_id']) ? trim($input['transaction_id']) : '';

            $result = complete_refund($pdo, $refund_id, $admin_id, $transaction_id);
            if ($result === false) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'İade işlemi tamamlanamadı. Talebin durumu "Onaylanan" olmalıdır.']);
                exit;
            }

            // Send email to user
            $stmt = $pdo->prepare(
                'SELECT u.email, u.full_name, e.title AS event_title, r.refund_amount
                 FROM refunds r
                 JOIN users u ON r.user_id = u.id
                 JOIN events e ON r.event_id = e.id
                 WHERE r.id = ?'
            );
            $stmt->execute([$refund_id]);
            $details = $stmt->fetch();

            if ($details && filter_var($details['email'], FILTER_VALIDATE_EMAIL)) {
                $email = $details['email'];
                $name = trim($details['full_name'] ?: $email);
                $subject = '💳 İade İşlemi Tamamlandı - ' . $details['event_title'];

                $message = '<html><body style="font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0;">'
                    . '<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding: 20px;">'
                    . '<table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden;">'
                    . '<tr><td style="background-color: #06b6d4; padding: 24px; color: #ffffff; text-align: center; font-size: 24px; font-weight: bold;">İade İşlemi Tamamlandı</td></tr>'
                    . '<tr><td style="padding: 24px; color: #333333;">'
                    . '<p style="margin: 0 0 16px;">Merhaba ' . htmlspecialchars($name) . ',</p>'
                    . '<p style="margin: 0 0 16px;">İade işleminiz tamamlanmıştır. ₺' . number_format($details['refund_amount'], 2, ',', '.') . ' geri ödenmiş durumdadır.</p>'
                    . '<p style="margin: 16px 0 0; color: #666;">2-5 iş günü içinde hesabınızda görünecektir.</p>'
                    . '</td></tr>'
                    . '</table>'
                    . '</td></tr></table>'
                    . '</body></html>';

                send_mail_message($email, $subject, $message);
            }

            log_activity($pdo, $admin_id, 'refund_complete', 'refund', $refund_id, ['transaction_id' => $transaction_id]);

            echo json_encode([
                'success' => true,
                'refund' => $result,
                'message' => 'İade işlemi başarıyla tamamlandı.'
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Geçersiz action: ' . htmlspecialchars($action)]);
            break;
    }

} catch (Exception $e) {
    error_log('Admin İade yönetimi hatası: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Bir hata oluştu. Lütfen daha sonra tekrar deneyin.']);
    exit;
}
