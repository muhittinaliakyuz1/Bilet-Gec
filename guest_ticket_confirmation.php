<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

start_secure_session();
ensure_guest_schema($pdo);

// Get token from URL
$token = isset($_GET['token']) ? trim($_GET['token']) : '';

if (empty($token)) {
    header('Location: /ilterhoca/');
    exit;
}

// Get completed reservation
$stmt = $pdo->prepare("
    SELECT gr.*, e.title AS event_title, e.event_date, e.venue, e.city, u.email 
    FROM guest_reservations gr
    JOIN events e ON gr.event_id = e.id
    LEFT JOIN users u ON gr.user_id = u.id
    WHERE gr.token = ? AND gr.status = 'completed'
");
$stmt->execute([$token]);
$reservation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reservation) {
    $page_title = "Bilet Bulunamadı";
    $current_page = "confirmation";
    require_once __DIR__ . '/includes/header.php';
    ?>
    
    <section class="error-section" style="min-height: 70vh; display: flex; align-items: center; justify-content: center;">
        <div class="container">
            <div class="glass-card" style="text-align: center; padding: 40px; max-width: 500px; margin: 0 auto;">
                <div style="font-size: 3rem; margin-bottom: 20px;">❌</div>
                <h1 style="margin-bottom: 10px;">Bilet Bulunamadı</h1>
                <p style="color: var(--text-secondary); margin-bottom: 30px;">
                    İstediğiniz bilet bulunamadı veya süresi dolmuştur.
                </p>
                <a href="/ilterhoca/" class="btn btn-primary">← Ana Sayfaya Dön</a>
            </div>
        </div>
    </section>

    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Get tickets
$stmt = $pdo->prepare("
    SELECT ticket_code FROM tickets 
    WHERE event_id = ? AND user_id = ? AND purchased_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    LIMIT ?
");
$stmt->execute([$reservation['event_id'], $reservation['user_id'], $reservation['quantity']]);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Bilet Onayı";
$current_page = "confirmation";

require_once __DIR__ . '/includes/header.php';
?>

<section class="confirmation-section" style="padding: 60px 20px;">
    <div class="container" style="max-width: 700px;">
        <!-- Success Animation -->
        <div style="text-align: center; margin-bottom: 40px;">
            <div style="display: inline-block; position: relative;">
                <div style="font-size: 4rem; animation: bounce 1s ease-in-out infinite;">✅</div>
            </div>
        </div>

        <!-- Main Card -->
        <div class="glass-card" style="padding: 40px;">
            <!-- Header -->
            <div style="text-align: center; margin-bottom: 30px;">
                <h1 style="margin: 0 0 10px 0; color: var(--accent-primary); font-size: 1.8rem;">Bilet Satın Alma Başarılı!</h1>
                <p style="color: var(--text-secondary); margin: 0;">Biletiniz hazırlandı ve e-postanıza gönderildi</p>
            </div>

            <!-- Divider -->
            <div style="height: 1px; background: rgba(255,255,255,0.1); margin: 30px 0;"></div>

            <!-- Event Info -->
            <div style="margin-bottom: 30px;">
                <h3 style="margin-top: 0; margin-bottom: 15px; color: var(--text-primary);">📍 Etkinlik Bilgileri</h3>
                <div style="background: rgba(255,255,255,0.02); padding: 15px; border-radius: 8px; border-left: 4px solid var(--accent-primary);">
                    <p style="margin: 8px 0;"><strong>Etkinlik:</strong> <?php echo htmlspecialchars($reservation['event_title']); ?></p>
                    <p style="margin: 8px 0;"><strong>Tarih & Saat:</strong> <?php echo date('d M Y H:i', strtotime($reservation['event_date'])); ?></p>
                    <p style="margin: 8px 0;"><strong>Mekan:</strong> <?php echo htmlspecialchars($reservation['venue']); ?>, <?php echo htmlspecialchars($reservation['city']); ?></p>
                    <p style="margin: 8px 0;"><strong>Toplam Tutar:</strong> <span style="color: var(--accent-secondary); font-weight: bold; font-size: 1.1rem;">₺<?php echo number_format($reservation['total_price'], 2, ',', '.'); ?></span></p>
                </div>
            </div>

            <!-- Ticket Codes -->
            <div style="margin-bottom: 30px;">
                <h3 style="margin-top: 0; margin-bottom: 15px; color: var(--text-primary);">🎫 Bilet Kodlarınız</h3>
                <div style="background: #f0fdf4; padding: 20px; border-radius: 8px; border-left: 4px solid var(--accent-primary);">
                    <?php foreach ($tickets as $index => $ticket): ?>
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px; background: white; border-radius: 6px; margin-bottom: 8px;">
                            <div>
                                <span style="color: var(--text-secondary); font-size: 0.9rem;">Bilet <?php echo $index + 1; ?></span>
                                <div style="font-family: monospace; font-size: 1.1rem; font-weight: bold; color: var(--accent-primary); letter-spacing: 1px; margin-top: 4px;">
                                    <?php echo htmlspecialchars($ticket['ticket_code']); ?>
                                </div>
                            </div>
                            <button type="button" onclick="copyTicketCode('<?php echo htmlspecialchars($ticket['ticket_code']); ?>')" style="background: var(--accent-primary); color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 0.9rem; font-weight: 500;">
                                📋 Kopyala
                            </button>
                        </div>
                    <?php endforeach; ?>
                    <p style="margin: 15px 0 0 0; font-size: 0.85rem; color: var(--text-secondary); line-height: 1.5;">
                        ℹ️ Bu kodları etkinlik günü kapıda gösteriniz. Çıktı alarak veya telefon ekranından gösterebilirsiniz. Kodları kimseyle paylaşmayın.
                    </p>
                </div>
            </div>

            <!-- Contact Info -->
            <div style="background: rgba(255,255,255,0.03); padding: 15px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); margin-bottom: 30px;">
                <p style="margin: 8px 0;"><strong>Satın Alan:</strong> <?php echo htmlspecialchars($reservation['name'] ?: $reservation['email']); ?></p>
                <p style="margin: 8px 0;"><strong>E-posta:</strong> <?php echo htmlspecialchars($reservation['email']); ?></p>
            </div>

            <!-- Actions -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                <a href="/ilterhoca/" class="btn btn-outline" style="text-align: center;">← Ana Sayfaya Dön</a>
                <a href="javascript:window.print()" class="btn btn-primary" style="text-align: center;">🖨️ Yazdır</a>
            </div>
        </div>

        <!-- Info Box -->
        <div style="text-align: center; margin-top: 30px;">
            <p style="color: var(--text-secondary); font-size: 0.9rem; margin: 0;">
                Bilet kodlarınız <strong>e-postanızda</strong> da bulunmaktadır.<br>
                <strong>Herhangi bir sorun yaşarsanız:</strong> <a href="mailto:info@biletgec.com" style="color: var(--accent-primary); text-decoration: none;">info@biletgec.com</a>
            </p>
        </div>
    </div>
</section>

<style>
@keyframes bounce {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-10px); }
}

@media print {
    .navbar, .site-footer, .back-to-top, button[onclick*="print"] { display: none !important; }
    .confirmation-section { padding: 20px; }
}
</style>

<script>
function copyTicketCode(code) {
    navigator.clipboard.writeText(code).then(() => {
        showToast('Bilet kodu kopyalandı!', 'success');
    }).catch(() => {
        showToast('Kopyalama başarısız.', 'error');
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
