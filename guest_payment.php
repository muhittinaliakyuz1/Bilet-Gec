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

// Verify guest reservation with token
$stmt = $pdo->prepare("
    SELECT * FROM guest_reservations 
    WHERE token = ? AND expires_at > NOW()
");
$stmt->execute([$token]);
$reservation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reservation) {
    $page_title = "Geçersiz Bağlantı";
    $current_page = "guest_payment";
    require_once __DIR__ . '/includes/header.php';
    ?>
    
    <section class="error-section" style="min-height: 70vh; display: flex; align-items: center; justify-content: center;">
        <div class="container">
            <div class="glass-card" style="text-align: center; padding: 40px; max-width: 500px; margin: 0 auto;">
                <div style="font-size: 3rem; margin-bottom: 20px;">❌</div>
                <h1 style="margin-bottom: 10px;">Geçersiz veya Süresi Dolmuş Bağlantı</h1>
                <p style="color: var(--text-secondary); margin-bottom: 30px;">
                    Bu ödeme bağlantısı geçersiz ya da süresi dolmuştur. Lütfen etkinlik sayfasından yeni bir işlem başlatın.
                </p>
                <a href="/ilterhoca/" class="btn btn-primary">← Ana Sayfaya Dön</a>
            </div>
        </div>
    </section>

    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Get event info
$stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
$stmt->execute([$reservation['event_id']]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

$page_title = "Ödeme - " . htmlspecialchars($event['title']);
$current_page = "guest_payment";

require_once __DIR__ . '/includes/header.php';
?>

<section class="guest-payment-section" style="padding: 60px 20px;">
    <div class="container" style="max-width: 600px;">
        <div class="glass-card" style="padding: 30px;">
            <!-- Header -->
            <div style="text-align: center; margin-bottom: 30px;">
                <h1 style="margin: 0 0 10px 0; font-size: 1.6rem;">💳 Güvenli Ödeme</h1>
                <p style="color: var(--text-secondary); margin: 0;">Bilet satın almayı tamamlayın</p>
            </div>

            <!-- Reservation Summary -->
            <div style="background: rgba(255,255,255,0.03); padding: 20px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); margin-bottom: 30px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 12px;">
                    <span style="color: var(--text-secondary);">Etkinlik:</span>
                    <strong><?php echo htmlspecialchars($event['title']); ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 12px;">
                    <span style="color: var(--text-secondary);">Bilet Adet:</span>
                    <strong><?php echo $reservation['quantity']; ?> x ₺<?php echo number_format((float)$event['price'], 2, ',', '.'); ?></strong>
                </div>
                <div style="border-top: 1px solid rgba(255,255,255,0.1); padding-top: 12px; display: flex; justify-content: space-between;">
                    <span style="color: var(--text-secondary); font-weight: 500;">Toplam:</span>
                    <span style="color: var(--accent-primary); font-size: 1.3rem; font-weight: 700;">₺<?php echo number_format((float)$reservation['total_price'], 2, ',', '.'); ?></span>
                </div>
            </div>

            <!-- Payment Form -->
            <form id="guest-payment-form" onsubmit="return false;">
                <!-- Virtual Credit Card Design -->
                <div class="virtual-card mb-4" style="background: linear-gradient(135deg, #1e1b4b, #312e81); border-radius: 12px; padding: 20px; box-shadow: 0 8px 24px rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.1); position: relative; overflow: hidden; height: 160px; display: flex; flex-direction: column; justify-content: space-between;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-size: 1.1rem; font-weight: bold; color: white; letter-spacing: 1px;">BİLET-GEÇ CARD</span>
                        <span style="font-size: 1.5rem;">⚡</span>
                    </div>
                    <div id="guest-card-num-display" style="font-family: monospace; font-size: 1.25rem; color: white; letter-spacing: 2px; text-shadow: 1px 1px 2px rgba(0,0,0,0.5); margin: 15px 0;">•••• •••• •••• ••••</div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-end; color: rgba(255,255,255,0.8); font-size: 0.75rem; text-transform: uppercase;">
                        <div>
                            <div style="font-size: 0.6rem; color: rgba(255,255,255,0.5); margin-bottom: 2px;">KART SAHİBİ</div>
                            <div id="guest-card-holder-display" style="font-weight: 500; letter-spacing: 1px;">AD SOYAD</div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 0.6rem; color: rgba(255,255,255,0.5); margin-bottom: 2px;">SKT</div>
                            <div id="guest-card-expiry-display" style="font-weight: 500;">AA/YY</div>
                        </div>
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label" for="guest-card-holder">KART SAHİBİ</label>
                    <input type="text" id="guest-card-holder" class="form-control glass-input" placeholder="Kart Üzerindeki İsim" required>
                </div>
                
                <div class="form-group mb-3">
                    <label class="form-label" for="guest-card-number">KART NUMARASI</label>
                    <input type="text" id="guest-card-number" class="form-control glass-input" placeholder="0000 0000 0000 0000" maxlength="19" required>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label class="form-label" for="guest-card-expiry">SON KULLANMA</label>
                        <input type="text" id="guest-card-expiry" class="form-control glass-input" placeholder="AA/YY" maxlength="5" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="guest-card-cvv">CVV</label>
                        <input type="password" id="guest-card-cvv" class="form-control glass-input" placeholder="000" maxlength="3" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block" id="guest-payment-submit-btn" style="padding: 12px; font-size: 1rem;">
                    💳 Ödemeyi Tamamla
                </button>
            </form>

            <!-- Security Info -->
            <div style="text-align: center; margin-top: 20px; font-size: 0.85rem; color: var(--text-secondary);">
                <p style="margin: 0;">🔒 Bu işlem tamamen güvenlidir ve simülasyondur.</p>
                <p style="margin: 5px 0 0 0;">Gerçek bir ödeme tahsil edilmeyecektir.</p>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const token = '<?php echo htmlspecialchars($token); ?>';
    const totalPrice = <?php echo (float)$reservation['total_price']; ?>;

    // Card input handlers
    const cardHolderInput = document.getElementById('guest-card-holder');
    const cardNumberInput = document.getElementById('guest-card-number');
    const cardExpiryInput = document.getElementById('guest-card-expiry');
    const cardCvvInput = document.getElementById('guest-card-cvv');

    const cardHolderDisplay = document.getElementById('guest-card-holder-display');
    const cardNumDisplay = document.getElementById('guest-card-num-display');
    const cardExpiryDisplay = document.getElementById('guest-card-expiry-display');

    if (cardHolderInput) {
        cardHolderInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
            cardHolderDisplay.textContent = this.value || 'AD SOYAD';
        });
    }

    if (cardNumberInput) {
        cardNumberInput.addEventListener('input', function() {
            let val = this.value.replace(/\D/g, '');
            let formatted = '';
            for (let i = 0; i < val.length; i++) {
                if (i > 0 && i % 4 === 0) formatted += ' ';
                formatted += val[i];
            }
            this.value = formatted;
            cardNumDisplay.textContent = formatted || '•••• •••• •••• ••••';
        });
    }

    if (cardExpiryInput) {
        cardExpiryInput.addEventListener('input', function() {
            let val = this.value.replace(/\D/g, '');
            if (val.length >= 2) {
                this.value = val.substring(0, 2) + '/' + val.substring(2, 4);
            } else {
                this.value = val;
            }
            cardExpiryDisplay.textContent = this.value || 'AA/YY';
        });
    }

    // Form submit
    const paymentForm = document.getElementById('guest-payment-form');
    const submitBtn = document.getElementById('guest-payment-submit-btn');

    if (paymentForm) {
        paymentForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            if (!paymentForm.checkValidity()) {
                paymentForm.reportValidity();
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = '⏳ Ödeme İşleniyor...';

            try {
                const response = await fetch('/ilterhoca/api/guest_payment_confirm.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        token: token
                    })
                });

                const clone = response.clone();
                let result;

                try {
                    result = await response.json();
                } catch (jsonError) {
                    const text = await clone.text();
                    showToast('Sunucu yanıtı okunamadı: ' + (text || response.statusText), 'error');
                    submitBtn.disabled = false;
                    submitBtn.textContent = '💳 Ödemeyi Tamamla';
                    return;
                }

                if (result.success) {
                    showToast('Ödemeniz başarıyla alındı!', 'success');
                    setTimeout(() => {
                        window.location.href = '/ilterhoca/guest_ticket_confirmation.php?token=' + encodeURIComponent(token);
                    }, 1500);
                } else {
                    showToast(result.message || 'Ödeme başarısız oldu.', 'error');
                    submitBtn.disabled = false;
                    submitBtn.textContent = '💳 Ödemeyi Tamamla';
                }
            } catch (err) {
                showToast('Bir hata oluştu. Lütfen tekrar deneyin: ' + (err.message || ''), 'error');
                submitBtn.disabled = false;
                submitBtn.textContent = '💳 Ödemeyi Tamamla';
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
