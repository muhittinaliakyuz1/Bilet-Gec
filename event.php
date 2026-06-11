<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

start_secure_session();

// Expire old reservations on page load
expire_old_reservations($pdo);

// Get event ID
$event_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($event_id <= 0) {
    header('Location: /ilterhoca/');
    exit;
}

// Fetch event with category info
$stmt = $pdo->prepare("
    SELECT e.*, c.name AS category_name, c.icon AS category_icon, c.slug AS category_slug
    FROM events e
    LEFT JOIN categories c ON e.category_id = c.id
    WHERE e.id = ?
");
$stmt->execute([$event_id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    header('Location: /ilterhoca/');
    exit;
}

// Calculate remaining capacity
$remaining = get_remaining_capacity($pdo, $event_id);
$total = (int)$event['total_capacity'];
$sold = $total - $remaining;
$percent_sold = $total > 0 ? ($sold / $total) * 100 : 0;
$percent_remaining = $total > 0 ? ($remaining / $total) * 100 : 0;

// Color code
if ($remaining <= 0) {
    $remaining_color = 'sold-out';
    $remaining_text = 'Tükendi';
} elseif ($percent_remaining <= 20) {
    $remaining_color = 'red';
    $remaining_text = $remaining . ' bilet kaldı';
} elseif ($percent_remaining <= 50) {
    $remaining_color = 'yellow';
    $remaining_text = $remaining . ' bilet kaldı';
} else {
    $remaining_color = 'green';
    $remaining_text = $remaining . ' bilet kaldı';
}

// Format dates
$event_date_obj = new DateTime($event['event_date']);
$formatted_date = $event_date_obj->format('d M Y');
$formatted_time = $event_date_obj->format('H:i');
$formatted_full = $event_date_obj->format('d M Y, H:i');

$end_date_text = '';
if (!empty($event['end_date'])) {
    $end_date_obj = new DateTime($event['end_date']);
    $end_date_text = $end_date_obj->format('d M Y, H:i');
}

$is_logged_in = is_logged_in();
$user_id = $is_logged_in ? $_SESSION['user']['id'] : 0;

// Check if user already has an active reservation for this event
$active_reservation = null;
if ($is_logged_in) {
    $stmt = $pdo->prepare("
        SELECT * FROM reservations 
        WHERE user_id = ? AND event_id = ? AND status = 'pending' AND expires_at > NOW()
        ORDER BY reserved_at DESC LIMIT 1
    ");
    $stmt->execute([$user_id, $event_id]);
    $active_reservation = $stmt->fetch(PDO::FETCH_ASSOC);
}

$page_title = htmlspecialchars($event['title']);
$current_page = "event";

require_once __DIR__ . '/includes/header.php';
?>

<!-- Event Header -->
<section class="event-hero">
    <div class="event-hero-image">
        <img src="<?php echo htmlspecialchars($event['image_url']); ?>" alt="<?php echo htmlspecialchars($event['title']); ?>">
        <div class="event-hero-overlay"></div>
    </div>
    <div class="event-hero-content container">
        <span class="event-hero-category badge">
            <?php echo htmlspecialchars($event['category_icon']); ?> <?php echo htmlspecialchars($event['category_name']); ?>
        </span>
        <h1 class="event-hero-title"><?php echo htmlspecialchars($event['title']); ?></h1>
        <div class="event-hero-meta">
            <span class="event-hero-meta-item">📅 <?php echo $formatted_full; ?></span>
            <span class="event-hero-meta-item">📍 <?php echo htmlspecialchars($event['venue']); ?>, <?php echo htmlspecialchars($event['city']); ?></span>
            <span class="event-hero-meta-item">👤 <?php echo htmlspecialchars($event['organizer']); ?></span>
        </div>
    </div>
</section>

<!-- Event Content -->
<section class="event-content-section">
    <div class="container">
        <div class="event-layout">
            <!-- Left Column -->
            <div class="event-main">
                <div class="event-description glass-card">
                    <h2>Etkinlik Hakkında</h2>
                    <div class="event-description-text">
                        <?php echo nl2br(htmlspecialchars($event['description'])); ?>
                    </div>
                </div>

                <div class="event-details glass-card">
                    <h2>Etkinlik Detayları</h2>
                    <ul class="event-details-list">
                        <li>
                            <span class="detail-icon">📅</span>
                            <span class="detail-label">Tarih:</span>
                            <span class="detail-value"><?php echo $formatted_date; ?></span>
                        </li>
                        <li>
                            <span class="detail-icon">🕐</span>
                            <span class="detail-label">Saat:</span>
                            <span class="detail-value"><?php echo $formatted_time; ?></span>
                        </li>
                        <?php if ($end_date_text): ?>
                        <li>
                            <span class="detail-icon">🏁</span>
                            <span class="detail-label">Bitiş:</span>
                            <span class="detail-value"><?php echo $end_date_text; ?></span>
                        </li>
                        <?php endif; ?>
                        <li>
                            <span class="detail-icon">📍</span>
                            <span class="detail-label">Mekan:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($event['venue']); ?></span>
                        </li>
                        <li>
                            <span class="detail-icon">🏙️</span>
                            <span class="detail-label">Şehir:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($event['city']); ?></span>
                        </li>
                        <li>
                            <span class="detail-icon">👤</span>
                            <span class="detail-label">Organizatör:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($event['organizer']); ?></span>
                        </li>
                    </ul>
                </div>

                <div class="event-venue-info glass-card">
                    <h2>📍 Mekan Bilgisi</h2>
                    <div class="venue-map-placeholder">
                        <div class="venue-map-icon">🗺️</div>
                        <p><strong><?php echo htmlspecialchars($event['venue']); ?></strong></p>
                        <p><?php echo htmlspecialchars($event['city']); ?></p>
                    </div>
                </div>
            </div>

            <!-- Right Column (Sticky) -->
            <div class="event-sidebar">
                <!-- Ticket Purchase Card -->
                <div class="ticket-card glass-card" id="ticket-card">
                    <div class="ticket-card-header">
                        <span class="ticket-price-label">Bilet Fiyatı</span>
                        <span class="ticket-price">₺<?php echo number_format((float)$event['price'], 2, ',', '.'); ?></span>
                    </div>

                    <div class="ticket-remaining">
                        <span class="remaining-badge remaining-<?php echo $remaining_color; ?>">
                            <span id="remaining-count"><?php echo $remaining; ?></span> / <?php echo $total; ?>
                        </span>
                        <span class="remaining-label"><?php echo $remaining_text; ?></span>
                    </div>

                    <div class="capacity-bar">
                        <div class="capacity-bar-fill" style="width: <?php echo $percent_sold; ?>%"></div>
                    </div>
                    <div class="capacity-bar-labels">
                        <span>Satılan: <?php echo $sold; ?></span>
                        <span>Kalan: <?php echo $remaining; ?></span>
                    </div>

                    <?php if ($remaining > 0 && $is_logged_in): ?>
                    <div class="ticket-purchase-form" id="purchase-form">
                        <div class="quantity-selector">
                            <label>Adet:</label>
                            <div class="quantity-controls">
                                <button type="button" class="qty-btn qty-minus" id="qty-minus">−</button>
                                <input type="number" id="quantity" class="qty-input" value="1" min="1" max="<?php echo min($remaining, 10); ?>">
                                <button type="button" class="qty-btn qty-plus" id="qty-plus">+</button>
                            </div>
                        </div>
                        <div class="ticket-total">
                            <span class="total-label">Toplam:</span>
                            <span class="total-price" id="total-price">₺<?php echo number_format((float)$event['price'], 2, ',', '.'); ?></span>
                        </div>
                        <button type="button" class="btn btn-primary btn-block" id="reserve-btn">
                            🎫 Rezerve Et
                        </button>
                    </div>
                    <?php elseif ($remaining <= 0): ?>
                    <div class="ticket-sold-out">
                        <button class="btn btn-disabled btn-block" disabled>🚫 Biletler Tükendi</button>
                    </div>
                    <?php else: ?>
                    <div class="ticket-login-required">
                        <p>Bilet almak için giriş yapın</p>
                        <button type="button" class="btn btn-primary btn-block" id="ticket-info-btn" onclick="openTicketInfoModal()">🎫 Bilet Al</button>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Reservation Timer Area (hidden by default) -->
                <div class="reservation-area glass-card" id="reservation-area" style="display: none;">
                    <div class="reservation-header">
                        <h3>⏱️ Rezervasyon Aktif</h3>
                    </div>
                    <div class="reservation-timer-info">
                        <p>Biletiniz <span id="timer-display" class="timer-countdown">--:--</span> süreyle rezerve edildi.</p>
                    </div>
                    <div class="reservation-details" id="reservation-details">
                        <p><strong>Adet:</strong> <span id="res-quantity">-</span></p>
                        <p><strong>Toplam:</strong> <span id="res-total">-</span></p>
                    </div>
                    <div class="reservation-actions">
                        <button type="button" class="btn btn-primary btn-block" id="confirm-btn">✅ Satın Al</button>
                        <button type="button" class="btn btn-outline btn-block" id="cancel-btn">❌ İptal Et</button>
                    </div>
                </div>

                <!-- Guest Checkout Section (hidden by default) -->
                <div class="guest-checkout glass-card" id="guest-checkout-section" style="display: none;">
                    <div class="checkout-header">
                        <h3>💳 Hesapsız Bilet Satın Al</h3>
                        <p style="color: var(--text-secondary); margin: 8px 0 0 0; font-size: 0.9rem;">E-posta ile bilet işleminiz tamamlanacak</p>
                    </div>
                    
                    <form id="guest-checkout-form" style="margin-top: 20px;">
                        <div class="form-group mb-3">
                            <label class="form-label" for="guest-email">E-Posta Adresi *</label>
                            <input type="email" id="guest-email" class="form-control glass-input" placeholder="ornek@gmail.com" required>
                        </div>

                        <div class="form-group mb-3">
                            <label class="form-label" for="guest-name">Ad Soyad (İsteğe Bağlı)</label>
                            <input type="text" id="guest-name" class="form-control glass-input" placeholder="Ad Soyad">
                        </div>

                        <div class="form-group mb-3">
                            <label class="form-label" for="guest-quantity">Bilet Adet *</label>
                            <div class="quantity-controls" style="display: flex; align-items: center; gap: 10px;">
                                <button type="button" class="qty-btn qty-minus" id="guest-qty-minus">−</button>
                                <input type="number" id="guest-quantity" class="qty-input" value="1" min="1" max="<?php echo min($remaining, 10); ?>" required style="flex: 1;">
                                <button type="button" class="qty-btn qty-plus" id="guest-qty-plus">+</button>
                            </div>
                        </div>

                        <div class="ticket-total mb-4" style="background: rgba(255,255,255,0.03); padding: 15px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.05);">
                            <span class="total-label">Toplam Tutar:</span>
                            <span class="total-price" id="guest-total-price">₺<?php echo number_format((float)$event['price'], 2, ',', '.'); ?></span>
                        </div>

                        <button type="submit" class="btn btn-primary btn-block" id="guest-submit-btn">
                            💳 Ödeme Sayfasına Git
                        </button>

                        <button type="button" class="btn btn-outline btn-block" id="guest-cancel-btn" style="margin-top: 10px;">
                            ← Geri Dön
                        </button>
                    </form>
                </div>

                <!-- Purchase Success Area (hidden by default) -->
                <div class="purchase-success glass-card" id="purchase-success" style="display: none;">
                    <div class="success-animation">
                        <div class="confetti-wrapper">
                            <span class="confetti">🎉</span>
                            <span class="confetti">🎊</span>
                            <span class="confetti">✨</span>
                        </div>
                        <div class="success-icon">✅</div>
                    </div>
                    <h3>Bilet Satın Alındı!</h3>
                    <p>Biletiniz başarıyla oluşturuldu.</p>
                    <div class="ticket-code-display">
                        <span class="ticket-code-label">Bilet Kodu:</span>
                        <span class="ticket-code-value" id="ticket-code">-</span>
                    </div>
                    <a href="my_tickets.php" class="btn btn-primary btn-block">🎫 Biletlerime Git</a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Payment Modal -->
<div class="modal-overlay" id="payment-modal">
    <div class="modal glass-card" style="max-width: 450px;">
        <div class="modal-header">
            <h3 class="modal-title">💳 Güvenli Ödeme (Simülasyon)</h3>
            <button class="modal-close" data-modal-close>&times;</button>
        </div>
        <div class="modal-body">
            <div class="payment-summary mb-4" style="background: rgba(255,255,255,0.03); padding: 15px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.05);">
                <p style="margin: 0; font-size: 0.95rem; display: flex; justify-content: space-between;">
                    <span>Bilet Tutarı:</span> 
                    <strong id="payment-total-price" style="color: var(--accent-secondary); font-size: 1.1rem;">₺0,00</strong>
                </p>
                <p style="margin: 8px 0 0 0; font-size: 0.75rem; color: var(--text-muted); line-height: 1.4;">
                    ℹ️ Bu bir simülasyondur. Gerçek bir ödeme tahsil edilmeyecektir. Alanları rastgele doldurabilirsiniz.
                </p>
            </div>
            
            <form id="payment-form" onsubmit="return false;">
                <!-- Virtual Credit Card Design -->
                <div class="virtual-card mb-4" style="background: linear-gradient(135deg, #1e1b4b, #312e81); border-radius: 12px; padding: 20px; box-shadow: 0 8px 24px rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.1); position: relative; overflow: hidden; height: 160px; display: flex; flex-direction: column; justify-content: space-between;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-size: 1.1rem; font-weight: bold; color: white; letter-spacing: 1px;">BİLET-GEÇ CARD</span>
                        <span style="font-size: 1.5rem;">⚡</span>
                    </div>
                    <div id="card-num-display" style="font-family: monospace; font-size: 1.25rem; color: white; letter-spacing: 2px; text-shadow: 1px 1px 2px rgba(0,0,0,0.5); margin: 15px 0;">•••• •••• •••• ••••</div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-end; color: rgba(255,255,255,0.8); font-size: 0.75rem; text-transform: uppercase;">
                        <div>
                            <div style="font-size: 0.6rem; color: rgba(255,255,255,0.5); margin-bottom: 2px;">KART SAHİBİ</div>
                            <div id="card-holder-display" style="font-weight: 500; letter-spacing: 1px;">AD SOYAD</div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 0.6rem; color: rgba(255,255,255,0.5); margin-bottom: 2px;">SKT</div>
                            <div id="card-expiry-display" style="font-weight: 500;">AA/YY</div>
                        </div>
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label" style="font-size: 0.75rem; letter-spacing: 1px; color: var(--text-secondary);" for="card-holder">KART SAHİBİ</label>
                    <input type="text" id="card-holder" class="form-control glass-input" placeholder="Kart Üzerindeki İsim" required>
                </div>
                
                <div class="form-group mb-3">
                    <label class="form-label" style="font-size: 0.75rem; letter-spacing: 1px; color: var(--text-secondary);" for="card-number">KART NUMARASI</label>
                    <input type="text" id="card-number" class="form-control glass-input" placeholder="0000 0000 0000 0000" maxlength="19" required>
                </div>

                <div class="grid-2" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group mb-3">
                        <label class="form-label" style="font-size: 0.75rem; letter-spacing: 1px; color: var(--text-secondary);" for="card-expiry">SON KULLANMA</label>
                        <input type="text" id="card-expiry" class="form-control glass-input" placeholder="AA/YY" maxlength="5" required>
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label" style="font-size: 0.75rem; letter-spacing: 1px; color: var(--text-secondary);" for="card-cvv">CVV</label>
                        <input type="password" id="card-cvv" class="form-control glass-input" placeholder="000" maxlength="3" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block mt-3" id="pay-submit-btn">
                    💳 Ödemeyi Tamamla ve Bilet Al
                </button>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const eventId = <?php echo (int)$event_id; ?>;
    const unitPrice = <?php echo (float)$event['price']; ?>;
    const maxQty = <?php echo min($remaining, 10); ?>;
    let currentReservationId = null;
    let reservationTimer = null;

    // Quantity selector
    const qtyInput = document.getElementById('quantity');
    const qtyMinus = document.getElementById('qty-minus');
    const qtyPlus = document.getElementById('qty-plus');
    const totalPriceEl = document.getElementById('total-price');

    function updateTotalPrice() {
        if (!qtyInput) return;
        const qty = parseInt(qtyInput.value) || 1;
        const total = qty * unitPrice;
        if (totalPriceEl) {
            totalPriceEl.textContent = '₺' + total.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    }

    if (qtyMinus) {
        qtyMinus.addEventListener('click', function() {
            let val = parseInt(qtyInput.value) || 1;
            if (val > 1) {
                qtyInput.value = val - 1;
                updateTotalPrice();
            }
        });
    }

    if (qtyPlus) {
        qtyPlus.addEventListener('click', function() {
            let val = parseInt(qtyInput.value) || 1;
            if (val < maxQty) {
                qtyInput.value = val + 1;
                updateTotalPrice();
            }
        });
    }

    if (qtyInput) {
        qtyInput.addEventListener('change', function() {
            let val = parseInt(this.value) || 1;
            if (val < 1) val = 1;
            if (val > maxQty) val = maxQty;
            this.value = val;
            updateTotalPrice();
        });
    }

    // Reserve button
    const reserveBtn = document.getElementById('reserve-btn');
    if (reserveBtn) {
        reserveBtn.addEventListener('click', async function() {
            const qty = parseInt(qtyInput.value) || 1;
            reserveBtn.disabled = true;
            reserveBtn.textContent = '⏳ Rezerve ediliyor...';

            try {
                const result = await API.reserveTicket(eventId, qty);
                if (result.success) {
                    currentReservationId = result.reservation_id;
                    showReservationArea(result, qty);
                } else {
                    showToast(result.message || 'Rezervasyon yapılamadı.', 'error');
                    reserveBtn.disabled = false;
                    reserveBtn.textContent = '🎫 Rezerve Et';
                }
            } catch (err) {
                showToast('Bir hata oluştu. Lütfen tekrar deneyin.', 'error');
                reserveBtn.disabled = false;
                reserveBtn.textContent = '🎫 Rezerve Et';
            }
        });
    }

    function showReservationArea(data, qty) {
        const purchaseForm = document.getElementById('purchase-form');
        const reservationArea = document.getElementById('reservation-area');
        const resQty = document.getElementById('res-quantity');
        const resTotal = document.getElementById('res-total');

        if (purchaseForm) purchaseForm.style.display = 'none';
        if (reservationArea) reservationArea.style.display = 'block';

        const total = qty * unitPrice;
        if (resQty) resQty.textContent = qty;
        if (resTotal) resTotal.textContent = '₺' + total.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        // Start countdown timer
        if (data.expires_at) {
            startCountdownTimer(data.expires_at);
        }
    }

    function startCountdownTimer(expiresAt) {
        const timerDisplay = document.getElementById('timer-display');
        const expiryTime = new Date(expiresAt).getTime();

        if (reservationTimer) clearInterval(reservationTimer);

        reservationTimer = setInterval(function() {
            const now = Date.now();
            const diff = expiryTime - now;

            if (diff <= 0) {
                clearInterval(reservationTimer);
                timerDisplay.textContent = '00:00';
                showToast('Rezervasyon süresi doldu.', 'error');
                setTimeout(() => location.reload(), 2000);
                return;
            }

            const minutes = Math.floor(diff / 60000);
            const seconds = Math.floor((diff % 60000) / 1000);
            timerDisplay.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
        }, 1000);
    }

    // Credit Card Input Formatting & Preview Update
    const cardHolderInput = document.getElementById('card-holder');
    const cardNumberInput = document.getElementById('card-number');
    const cardExpiryInput = document.getElementById('card-expiry');
    const cardCvvInput = document.getElementById('card-cvv');

    const cardHolderDisplay = document.getElementById('card-holder-display');
    const cardNumDisplay = document.getElementById('card-num-display');
    const cardExpiryDisplay = document.getElementById('card-expiry-display');

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

    // Confirm purchase button -> Opens Simulated Payment Modal
    const confirmBtn = document.getElementById('confirm-btn');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
            if (!currentReservationId) return;
            
            // Set payment summary total price
            const resTotalText = document.getElementById('res-total')?.textContent || '₺0,00';
            const payTotalEl = document.getElementById('payment-total-price');
            if (payTotalEl) payTotalEl.textContent = resTotalText;

            // Reset form
            const form = document.getElementById('payment-form');
            if (form) form.reset();
            if (cardHolderDisplay) cardHolderDisplay.textContent = 'AD SOYAD';
            if (cardNumDisplay) cardNumDisplay.textContent = '•••• •••• •••• ••••';
            if (cardExpiryDisplay) cardExpiryDisplay.textContent = 'AA/YY';

            // Open Modal
            ModalManager.open('payment-modal');
        });
    }

    // Pay Submit Button inside Modal
    const paySubmitBtn = document.getElementById('pay-submit-btn');
    if (paySubmitBtn) {
        paySubmitBtn.addEventListener('click', async function(e) {
            const form = document.getElementById('payment-form');
            if (!form || !form.checkValidity()) {
                form?.reportValidity();
                return;
            }

            paySubmitBtn.disabled = true;
            paySubmitBtn.innerHTML = '⏳ Ödeme İşleniyor...';

            try {
                const result = await API.confirmPurchase(currentReservationId);
                if (result.success) {
                    if (reservationTimer) clearInterval(reservationTimer);
                    ModalManager.close('payment-modal');
                    showPurchaseSuccess(result);
                    showToast('Ödemeniz başarıyla alındı ve biletiniz onaylandı!', 'success');
                } else {
                    showToast(result.message || 'Satın alma başarısız.', 'error');
                    paySubmitBtn.disabled = false;
                    paySubmitBtn.innerHTML = '💳 Ödemeyi Tamamla ve Bilet Al';
                }
            } catch (err) {
                showToast('Ödeme onaylanırken bir hata oluştu.', 'error');
                paySubmitBtn.disabled = false;
                paySubmitBtn.innerHTML = '💳 Ödemeyi Tamamla ve Bilet Al';
            }
        });
    }

    // Cancel reservation button
    const cancelBtn = document.getElementById('cancel-btn');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', async function() {
            if (!currentReservationId) return;
            cancelBtn.disabled = true;
            cancelBtn.textContent = '⏳ İptal ediliyor...';

            try {
                const result = await API.cancelReservation(currentReservationId);
                if (result.success) {
                    if (reservationTimer) clearInterval(reservationTimer);
                    showToast('Rezervasyon iptal edildi.', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(result.message || 'İptal edilemedi.', 'error');
                    cancelBtn.disabled = false;
                    cancelBtn.textContent = '❌ İptal Et';
                }
            } catch (err) {
                showToast('Bir hata oluştu.', 'error');
                cancelBtn.disabled = false;
                cancelBtn.textContent = '❌ İptal Et';
            }
        });
    }

    function showPurchaseSuccess(data) {
        const reservationArea = document.getElementById('reservation-area');
        const purchaseSuccess = document.getElementById('purchase-success');
        const ticketCode = document.getElementById('ticket-code');
        const ticketCard = document.getElementById('ticket-card');

        if (reservationArea) reservationArea.style.display = 'none';
        if (ticketCard) ticketCard.style.display = 'none';
        if (purchaseSuccess) purchaseSuccess.style.display = 'block';
        if (ticketCode && data.ticket_code) ticketCode.textContent = data.ticket_code;

        // Update remaining count
        const remainingEl = document.getElementById('remaining-count');
        if (remainingEl && typeof data.remaining !== 'undefined') {
            remainingEl.textContent = data.remaining;
        }
    }

    // Check for existing active reservation on page load
    <?php if ($active_reservation): ?>
    (function() {
        currentReservationId = <?php echo (int)$active_reservation['id']; ?>;
        const qty = <?php echo (int)$active_reservation['quantity']; ?>;
        const expiresAt = '<?php echo $active_reservation['expires_at']; ?>';

        const purchaseForm = document.getElementById('purchase-form');
        const reservationArea = document.getElementById('reservation-area');
        const resQty = document.getElementById('res-quantity');
        const resTotal = document.getElementById('res-total');

        if (purchaseForm) purchaseForm.style.display = 'none';
        if (reservationArea) reservationArea.style.display = 'block';

        const total = qty * unitPrice;
        if (resQty) resQty.textContent = qty;
        if (resTotal) resTotal.textContent = '₺' + total.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        startCountdownTimer(expiresAt);
    })();
    <?php endif; ?>

    // Availability poller - update remaining count every 30s
    setInterval(async function() {
        try {
            const resp = await fetch('/ilterhoca/api/check_availability.php?event_id=' + eventId);
            const data = await resp.json();
            if (data.success) {
                const remainingEl = document.getElementById('remaining-count');
                if (remainingEl) remainingEl.textContent = data.remaining;
            }
        } catch(e) {}
    }, 30000);

    // Guest Checkout Form Handler
    const guestQtyInput = document.getElementById('guest-quantity');
    const guestQtyMinus = document.getElementById('guest-qty-minus');
    const guestQtyPlus = document.getElementById('guest-qty-plus');
    const guestTotalPrice = document.getElementById('guest-total-price');
    const guestSubmitBtn = document.getElementById('guest-submit-btn');
    const guestCancelBtn = document.getElementById('guest-cancel-btn');
    const guestCheckoutForm = document.getElementById('guest-checkout-form');

    function updateGuestTotalPrice() {
        if (!guestQtyInput) return;
        const qty = parseInt(guestQtyInput.value) || 1;
        const total = qty * unitPrice;
        if (guestTotalPrice) {
            guestTotalPrice.textContent = '₺' + total.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    }

    if (guestQtyMinus) {
        guestQtyMinus.addEventListener('click', function() {
            let val = parseInt(guestQtyInput.value) || 1;
            if (val > 1) {
                guestQtyInput.value = val - 1;
                updateGuestTotalPrice();
            }
        });
    }

    if (guestQtyPlus) {
        guestQtyPlus.addEventListener('click', function() {
            let val = parseInt(guestQtyInput.value) || 1;
            if (val < maxQty) {
                guestQtyInput.value = val + 1;
                updateGuestTotalPrice();
            }
        });
    }

    if (guestQtyInput) {
        guestQtyInput.addEventListener('change', function() {
            let val = parseInt(this.value) || 1;
            if (val < 1) val = 1;
            if (val > maxQty) val = maxQty;
            this.value = val;
            updateGuestTotalPrice();
        });
    }

    if (guestCancelBtn) {
        guestCancelBtn.addEventListener('click', function() {
            const guestCheckoutSection = document.getElementById('guest-checkout-section');
            if (guestCheckoutSection) {
                guestCheckoutSection.style.display = 'none';
                guestCheckoutForm.reset();
            }
        });
    }

    if (guestCheckoutForm) {
        guestCheckoutForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const email = document.getElementById('guest-email').value.trim();
            const name = document.getElementById('guest-name').value.trim();
            const qty = parseInt(guestQtyInput.value) || 1;

            if (!email) {
                showToast('Lütfen e-posta adresinizi girin.', 'error');
                return;
            }

            guestSubmitBtn.disabled = true;
            guestSubmitBtn.textContent = '⏳ İşleniyor...';

            try {
                // Create guest reservation and send confirmation email
                const response = await fetch('/ilterhoca/api/guest_checkout.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        event_id: eventId,
                        email: email,
                        name: name,
                        quantity: qty
                    })
                });

                const clone = response.clone();
                let result;

                try {
                    result = await response.json();
                } catch (jsonError) {
                    const text = await clone.text();
                    showToast('Sunucu yanıtı okunamadı: ' + (text || response.statusText), 'error');
                    guestSubmitBtn.disabled = false;
                    guestSubmitBtn.textContent = '💳 Ödeme Sayfasına Git';
                    return;
                }

                if (result.success) {
                    showToast(result.message || 'Rezervasyon oluşturuldu. Ödeme sayfasına yönlendiriliyorsunuz.', 'success');
                    const guestCheckoutSection = document.getElementById('guest-checkout-section');
                    if (guestCheckoutSection) {
                        guestCheckoutSection.style.display = 'none';
                    }
                    guestCheckoutForm.reset();
                    if (result.payment_url) {
                        setTimeout(() => {
                            window.location.href = result.payment_url;
                        }, 1200);
                    } else {
                        setTimeout(() => {
                            showToast('Lütfen e-postanızı kontrol edin.', 'info');
                        }, 2000);
                    }
                } else {
                    showToast(result.message || 'Bir hata oluştu.', 'error');
                    guestSubmitBtn.disabled = false;
                    guestSubmitBtn.textContent = '💳 Ödeme Sayfasına Git';
                }
            } catch (err) {
                showToast('İşlem sırasında bir hata oluştu: ' + (err.message || ''), 'error');
                guestSubmitBtn.disabled = false;
                guestSubmitBtn.textContent = '💳 Ödeme Sayfasına Git';
            }
        });
    }
});
</script>

<!-- Ticket Info Modal (Login Required) -->
<div class="modal-overlay" id="ticket-info-modal">
    <div class="modal glass-card">
        <button class="modal-close" onclick="closeTicketInfoModal()">&times;</button>
        <div class="modal-body" style="text-align: center; padding: 40px 30px;">
            <!-- Icon -->
            <div style="font-size: 3.5rem; margin-bottom: 20px;">🎫</div>
            
            <!-- Title -->
            <h3 style="font-size: 1.4rem; margin-bottom: 10px; font-weight: 600;">
                <?php echo htmlspecialchars($event['title']); ?>
            </h3>
            
            <!-- Price -->
            <div style="font-size: 2rem; color: var(--accent-secondary); font-weight: 700; margin: 15px 0;">
                ₺<?php echo number_format((float)$event['price'], 2, ',', '.'); ?>
            </div>
            
            <!-- Capacity Info -->
            <div style="background: rgba(255,255,255,0.03); padding: 15px; border-radius: 8px; margin: 20px 0; border: 1px solid rgba(255,255,255,0.05);">
                <p style="margin: 0; font-size: 0.9rem; color: var(--text-secondary);">
                    <strong><?php echo $remaining; ?> / <?php echo $total; ?></strong> bilet kaldı
                </p>
            </div>
            
            <!-- Info Text -->
            <p style="color: var(--text-secondary); font-size: 0.95rem; line-height: 1.5; margin: 20px 0;">
                Bilet almak için lütfen giriş yapın veya yeni bir hesap oluşturun. Hesabınız ile rahatça bilet yönetebileceksiniz.
            </p>
            
            <!-- Buttons -->
            <div class="ticket-login-actions">
                <a href="login.php?return=<?php echo urlencode('event.php?id=' . $event_id); ?>" class="btn btn-primary btn-block">
                    🚪 Giriş Yap / Kayıt Ol
                </a>
                <p class="ticket-login-or">veya</p>
                <a href="#" onclick="startGuestCheckout(event)" class="guest-continue-link">
                    💳 Hesapsız Devam Et
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function openTicketInfoModal() {
    const modal = document.getElementById('ticket-info-modal');
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeTicketInfoModal() {
    const modal = document.getElementById('ticket-info-modal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
}

// Close modal when clicking outside
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('ticket-info-modal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeTicketInfoModal();
            }
        });
    }
});

function startGuestCheckout(e) {
    e.preventDefault();
    closeTicketInfoModal();
    
    // Show guest checkout section
    const guestCheckoutSection = document.getElementById('guest-checkout-section');
    if (guestCheckoutSection) {
        guestCheckoutSection.style.display = 'block';
        guestCheckoutSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
