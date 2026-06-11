<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

start_secure_session();
require_login();

// Expire old reservations
expire_old_reservations($pdo);

$user_id = $_SESSION['user']['id'];

// Fetch active reservations (pending, not expired)
$stmt = $pdo->prepare("
    SELECT r.*, e.title AS event_title, e.event_date, e.venue, e.city, e.price, e.image_url,
           c.name AS category_name, c.icon AS category_icon
    FROM reservations r
    JOIN events e ON r.event_id = e.id
    LEFT JOIN categories c ON e.category_id = c.id
    WHERE r.user_id = ? AND r.status = 'pending' AND r.expires_at > NOW()
    ORDER BY r.reserved_at DESC
");
$stmt->execute([$user_id]);
$active_reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch confirmed tickets
// If the `refunds` table doesn't exist yet (fresh install), avoid referencing it to prevent SQL errors.
$hasRefunds = false;
try {
    $check = $pdo->query("SHOW TABLES LIKE 'refunds'");
    $hasRefunds = (bool)$check->fetch();
} catch (Exception $e) {
    $hasRefunds = false;
}

if ($hasRefunds) {
    $sql = "SELECT t.*, e.title AS event_title, e.event_date, e.venue, e.city, e.image_url, e.status AS event_status,
                   c.name AS category_name, c.icon AS category_icon,
                   (SELECT COUNT(1) FROM refunds r WHERE r.ticket_id = t.id) AS active_refund_count
            FROM tickets t
            JOIN events e ON t.event_id = e.id
            LEFT JOIN categories c ON e.category_id = c.id
            WHERE t.user_id = ?
            ORDER BY t.purchased_at DESC";
} else {
    $sql = "SELECT t.*, e.title AS event_title, e.event_date, e.venue, e.city, e.image_url, e.status AS event_status,
                   c.name AS category_name, c.icon AS category_icon
            FROM tickets t
            JOIN events e ON t.event_id = e.id
            LEFT JOIN categories c ON e.category_id = c.id
            WHERE t.user_id = ?
            ORDER BY t.purchased_at DESC";
}

$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Biletlerim";
$current_page = "my_tickets";

require_once __DIR__ . '/includes/header.php';
?>

<section class="my-tickets-section">
    <div class="container">
        <h1 class="page-title">🎫 Biletlerim</h1>

        <!-- Active Reservations Section -->
        <div class="section-block">
            <h2 class="section-subtitle">⏱️ Aktif Rezervasyonlar</h2>

            <?php if (empty($active_reservations)): ?>
            <div class="empty-state glass-card">
                <div class="empty-icon">📭</div>
                <p>Aktif rezervasyonunuz bulunmuyor.</p>
            </div>
            <?php else: ?>
            <div class="tickets-grid">
                <?php foreach ($active_reservations as $res):
                    $res_date = new DateTime($res['event_date']);
                    $formatted_event_date = $res_date->format('d M Y, H:i');
                    $total_price = (float)$res['price'] * (int)$res['quantity'];
                ?>
                <div class="reservation-item ticket-item glass-card" data-reservation-id="<?php echo (int)$res['id']; ?>">
                    <div class="ticket-item-image">
                        <img src="<?php echo htmlspecialchars($res['image_url']); ?>" alt="<?php echo htmlspecialchars($res['event_title']); ?>" loading="lazy">
                        <span class="ticket-status-badge" style="background: rgba(239, 68, 68, 0.8); color: white;">
                            ⏱️ Bekliyor
                        </span>
                    </div>
                    <div class="ticket-item-body">
                        <h3 class="ticket-item-title">
                            <?php echo htmlspecialchars($res['category_icon'] ?? '🎭'); ?>
                            <?php echo htmlspecialchars($res['event_title']); ?>
                        </h3>
                        <div class="ticket-item-meta">
                            <span>📅 <?php echo $formatted_event_date; ?></span>
                            <span>📍 <?php echo htmlspecialchars($res['venue']); ?>, <?php echo htmlspecialchars($res['city']); ?></span>
                        </div>
                        <div class="reservation-details-info" style="margin-top: 10px; font-size: 0.9rem; color: rgba(255,255,255,0.8); display: flex; justify-content: space-between;">
                            <p style="margin:0;"><strong>Adet:</strong> <?php echo (int)$res['quantity']; ?></p>
                            <p style="margin:0;"><strong>Toplam:</strong> ₺<?php echo number_format($total_price, 2, ',', '.'); ?></p>
                        </div>
                        <div class="reservation-timer-section" style="margin-top: 10px; text-align: center; font-weight: bold; color: var(--accent-pink);">
                            <span class="reservation-timer-label">Kalan Süre: </span>
                            <span class="reservation-timer-display timer-countdown" 
                                  data-expires="<?php echo htmlspecialchars($res['expires_at']); ?>">
                                --:--
                            </span>
                        </div>
                        <div class="reservation-item-actions" style="margin-top: 15px; display: flex; gap: 10px;">
                            <button type="button" class="btn btn-primary btn-sm confirm-reservation-btn" style="flex: 1;"
                                    data-reservation-id="<?php echo (int)$res['id']; ?>">
                                ✅ Satın Al
                            </button>
                            <button type="button" class="btn btn-outline btn-sm cancel-reservation-btn" style="flex: 1;"
                                    data-reservation-id="<?php echo (int)$res['id']; ?>">
                                ❌ İptal Et
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- My Tickets Section -->
        <div class="section-block">
            <h2 class="section-subtitle">🎟️ Biletlerim</h2>

            <?php if (empty($tickets)): ?>
            <div class="empty-state glass-card">
                <div class="empty-icon">🎭</div>
                <p>Henüz biletiniz bulunmuyor.</p>
                <a href="/ilterhoca/" class="btn btn-primary">Etkinliklere Göz At</a>
            </div>
            <?php else: ?>
            <div class="tickets-grid">
                <?php foreach ($tickets as $ticket):
                    $ticket_event_date = new DateTime($ticket['event_date']);
                    $now = new DateTime();
                    $is_upcoming = $ticket_event_date > $now;
                    $status_class = $is_upcoming ? 'upcoming' : 'completed';
                    $status_text = $is_upcoming ? 'Yaklaşan' : 'Tamamlandı';
                    $formatted_ticket_date = $ticket_event_date->format('d M Y, H:i');
                    $purchased_date = new DateTime($ticket['purchased_at']);
                    $formatted_purchased = $purchased_date->format('d M Y, H:i');
                ?>
                <div class="ticket-item glass-card">
                    <div class="ticket-item-image">
                        <img src="<?php echo htmlspecialchars($ticket['image_url']); ?>" 
                             alt="<?php echo htmlspecialchars($ticket['event_title']); ?>" loading="lazy">
                        <span class="ticket-status-badge badge-<?php echo $status_class; ?>">
                            <?php echo $status_text; ?>
                        </span>
                    </div>
                    <div class="ticket-item-body">
                        <h3 class="ticket-item-title">
                            <?php echo htmlspecialchars($ticket['category_icon'] ?? '🎭'); ?>
                            <?php echo htmlspecialchars($ticket['event_title']); ?>
                        </h3>
                        <div class="ticket-item-meta">
                            <span>📅 <?php echo $formatted_ticket_date; ?></span>
                            <span>📍 <?php echo htmlspecialchars($ticket['venue']); ?>, <?php echo htmlspecialchars($ticket['city']); ?></span>
                        </div>
                        <div class="ticket-code-section">
                            <span class="ticket-code-label-sm">Bilet Kodu:</span>
                            <span class="ticket-code-copyable" data-code="<?php echo htmlspecialchars($ticket['ticket_code']); ?>" title="Kopyalamak için tıklayın">
                                <?php echo htmlspecialchars($ticket['ticket_code']); ?>
                            </span>
                        </div>
                        <div class="ticket-item-footer">
                            <div class="ticket-item-info">
                                <span>Adet: <?php echo (int)$ticket['quantity']; ?></span>
                                <span>Toplam: ₺<?php echo number_format((float)$ticket['total_price'], 2, ',', '.'); ?></span>
                            </div>
                            <div class="ticket-purchase-date">
                                <span>🛒 <?php echo $formatted_purchased; ?></span>
                            </div>
                        </div>
                        
                        <!-- Refund Request Button -->
                        <div style="margin-top: 12px;">
                            <?php
                                // İade yalnızca etkinlikten en az 24 saat önce kabul edilir
                                $now = new DateTime();
                                $allow_refund = false;
                                try {
                                    $event_dt = new DateTime($ticket['event_date']);
                                    $cutoff = (clone $now)->modify('+24 hours');
                                    if ($event_dt > $cutoff) {
                                        $allow_refund = true;
                                    }
                                } catch (Exception $e) {
                                    $allow_refund = false;
                                }

                                $has_active_refund = !empty($ticket['active_refund_count']);

                                if ($allow_refund && !$has_active_refund): ?>
                                    <button type="button" class="btn btn-outline btn-sm request-refund-btn" style="width: 100%;"
                                            data-ticket-id="<?php echo (int)$ticket['id']; ?>"
                                            data-ticket-code="<?php echo htmlspecialchars($ticket['ticket_code']); ?>"
                                            data-event-title="<?php echo htmlspecialchars($ticket['event_title']); ?>"
                                            data-ticket-price="<?php echo (float)$ticket['total_price']; ?>">
                                        💳 İade Talep Et
                                    </button>
                                <?php elseif ($has_active_refund): ?>
                                    <button type="button" class="btn btn-outline btn-sm" style="width:100%; opacity:0.6; cursor:not-allowed; pointer-events: none;" disabled aria-disabled="true" tabindex="-1">
                                        📨 İade Talebi Oluşturuldu
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-outline btn-sm" style="width:100%; opacity:0.6; cursor:not-allowed; pointer-events: none;" disabled aria-disabled="true" tabindex="-1">
                                        💳 İade Talebi Kapalı (Etkinliğe 24 saatten az kaldı veya geçmiş)
                                    </button>
                                <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize countdown timers for active reservations
    const timerDisplays = document.querySelectorAll('.reservation-timer-display');
    const timers = [];

    timerDisplays.forEach(function(display) {
        const expiresAt = display.dataset.expires;
        if (!expiresAt) return;

        const expiryTime = new Date(expiresAt.replace(' ', 'T')).getTime();
        
        const updateTimer = function() {
            const now = Date.now();
            const diff = expiryTime - now;

            if (isNaN(diff)) {
                display.textContent = 'Hata';
                return true; // Stop timer
            }

            if (diff <= 0) {
                display.textContent = '00:00';
                const item = display.closest('.reservation-item');
                if (item) {
                    item.style.opacity = '0.5';
                    const actions = item.querySelector('.reservation-item-actions');
                    if (actions) actions.innerHTML = '<span class="text-muted">Süre doldu</span>';
                }
                return true; // Stop timer
            }

            const minutes = Math.floor(diff / 60000);
            const seconds = Math.floor((diff % 60000) / 1000);
            display.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
            return false;
        };

        if (!updateTimer()) {
            const timer = setInterval(function() {
                if (updateTimer()) clearInterval(timer);
            }, 1000);
            timers.push(timer);
        }
    });

    // Simulated Payment Modal variables
    let currentReservationId = null;
    let confirmBtnSource = null;

    // Credit Card Input Formatting & Preview Update
    const cardHolderInput = document.getElementById('card-holder');
    const cardNumberInput = document.getElementById('card-number');
    const cardExpiryInput = document.getElementById('card-expiry');

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

    // Confirm reservation buttons -> Opens Simulated Payment Modal
    document.querySelectorAll('.confirm-reservation-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const reservationId = parseInt(this.dataset.reservationId);
            if (!reservationId) return;

            currentReservationId = reservationId;
            confirmBtnSource = this;

            // Set payment summary total price from the reservation item
            const item = this.closest('.reservation-item');
            const totalText = item ? item.querySelector('.reservation-details-info p:nth-child(2)').textContent.replace('Toplam: ', '') : '₺0,00';
            const payTotalEl = document.getElementById('payment-total-price');
            if (payTotalEl) payTotalEl.textContent = totalText;

            // Reset form
            const form = document.getElementById('payment-form');
            if (form) form.reset();
            if (cardHolderDisplay) cardHolderDisplay.textContent = 'AD SOYAD';
            if (cardNumDisplay) cardNumDisplay.textContent = '•••• •••• •••• ••••';
            if (cardExpiryDisplay) cardExpiryDisplay.textContent = 'AA/YY';

            // Open Modal
            ModalManager.open('payment-modal');
        });
    });

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
                    ModalManager.close('payment-modal');
                    showToast('Ödemeniz başarıyla alındı ve biletiniz onaylandı!', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(result.message || 'Satın alma başarısız.', 'error');
                    paySubmitBtn.disabled = false;
                    paySubmitBtn.innerHTML = '💳 Ödemeyi Tamamla ve Bilet Al';
                    if (confirmBtnSource) {
                        confirmBtnSource.disabled = false;
                        confirmBtnSource.textContent = '✅ Satın Al';
                    }
                }
            } catch (err) {
                showToast('Ödeme onaylanırken bir hata oluştu.', 'error');
                paySubmitBtn.disabled = false;
                paySubmitBtn.innerHTML = '💳 Ödemeyi Tamamla ve Bilet Al';
                if (confirmBtnSource) {
                    confirmBtnSource.disabled = false;
                    confirmBtnSource.textContent = '✅ Satın Al';
                }
            }
        });
    }

    // Cancel reservation buttons
    document.querySelectorAll('.cancel-reservation-btn').forEach(function(btn) {
        btn.addEventListener('click', async function() {
            const reservationId = parseInt(this.dataset.reservationId);
            if (!reservationId) return;

            this.disabled = true;
            this.textContent = '⏳ İptal ediliyor...';

            try {
                const result = await API.cancelReservation(reservationId);
                if (result.success) {
                    showToast('Rezervasyon iptal edildi.', 'success');
                    const item = this.closest('.reservation-item');
                    if (item) {
                        item.style.transition = 'opacity 0.5s, transform 0.5s';
                        item.style.opacity = '0';
                        item.style.transform = 'translateX(-20px)';
                        setTimeout(() => item.remove(), 500);
                    }
                } else {
                    showToast(result.message || 'İptal edilemedi.', 'error');
                    this.disabled = false;
                    this.textContent = '❌ İptal Et';
                }
            } catch (err) {
                showToast('Bir hata oluştu.', 'error');
                this.disabled = false;
                this.textContent = '❌ İptal Et';
            }
        });
    });

    // Copy ticket code to clipboard
    document.querySelectorAll('.ticket-code-copyable').forEach(function(el) {
        el.addEventListener('click', function() {
            const code = this.dataset.code;
            if (!code) return;

            navigator.clipboard.writeText(code).then(() => {
                const original = this.textContent;
                this.textContent = '✅ Kopyalandı!';
                this.classList.add('copied');
                setTimeout(() => {
                    this.textContent = original;
                    this.classList.remove('copied');
                }, 2000);
            }).catch(() => {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = code;
                textArea.style.position = 'fixed';
                textArea.style.opacity = '0';
                document.body.appendChild(textArea);
                textArea.select();
                try {
                    document.execCommand('copy');
                    const original = this.textContent;
                    this.textContent = '✅ Kopyalandı!';
                    this.classList.add('copied');
                    setTimeout(() => {
                        this.textContent = original;
                        this.classList.remove('copied');
                    }, 2000);
                } catch (e) {}
                document.body.removeChild(textArea);
            });
        });
    });

    // Refund Request Modal Handling
    let currentRefundTicketId = null;
    let currentRefundAmount = 0;

    document.querySelectorAll('.request-refund-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            currentRefundTicketId = parseInt(this.dataset.ticketId);
            currentRefundAmount = parseFloat(this.dataset.ticketPrice);
            const ticketCode = this.dataset.ticketCode;
            const eventTitle = this.dataset.eventTitle;

            document.getElementById('refund-ticket-code-display').textContent = ticketCode;
            document.getElementById('refund-event-title-display').textContent = eventTitle;
            const refundTicketIdEl = document.getElementById('refund-ticket-id');
            if (refundTicketIdEl) {
                refundTicketIdEl.value = currentRefundTicketId;
            }
            const refundAmountEl = document.getElementById('refund-amount');
            if (refundAmountEl) {
                refundAmountEl.value = currentRefundAmount.toFixed(2);
                refundAmountEl.setAttribute('readonly', 'readonly');
            }
            document.getElementById('refund-reason').value = '';
            document.getElementById('refund-description').value = '';

            ModalManager.open('refund-modal');
        });
    });

    // Refund Form Submission
    const refundSubmitBtn = document.getElementById('refund-submit-btn');
    if (refundSubmitBtn) {
        refundSubmitBtn.addEventListener('click', async function(e) {
            e.preventDefault();
            
            const refundTicketId = parseInt(document.getElementById('refund-ticket-id').value, 10);
            const reason = document.getElementById('refund-reason').value.trim();
            const description = document.getElementById('refund-description').value.trim();

            if (!refundTicketId || isNaN(refundTicketId)) {
                showToast('Geçerli bir bilet seçilmedi. Lütfen tekrar deneyin.', 'error');
                return;
            }

            // Amount is fixed to ticket price (server-enforced). Only validate reason.
            if (!reason) {
                showToast('İade nedeni belirtiniz.', 'error');
                return;
            }

            refundSubmitBtn.disabled = true;
            refundSubmitBtn.innerHTML = '⏳ Gönderiliyor...';

            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

                const response = await fetch('/ilterhoca/api/request_refund.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
                    body: JSON.stringify({
                        ticket_id: refundTicketId,
                        reason: reason,
                        description: description
                    })
                });

                const responseText = await response.text();
                let result;

                try {
                    result = JSON.parse(responseText);
                } catch (parseError) {
                    console.error('request_refund parse error:', parseError, 'responseText:', responseText);
                    throw new Error('Sunucudan geçersiz yanıt alındı.');
                }

                if (!response.ok) {
                    console.error('request_refund failed:', response.status, result);
                    throw new Error(result.message || 'Sunucudan hata yanıtı alındı.');
                }

                if (result.success) {
                    showToast('✅ İade talebiniz başarıyla oluşturuldu!', 'success');
                    ModalManager.close('refund-modal');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('❌ ' + (result.message || 'İade talebiniz oluşturulamadı.'), 'error');
                }
            } catch (err) {
                console.error('İade talebi sırasında hata:', err);
                showToast('❌ Bir hata oluştu. Lütfen daha sonra tekrar deneyin.', 'error');
            } finally {
                refundSubmitBtn.disabled = false;
                refundSubmitBtn.innerHTML = '📤 İade Talebini Gönder';
            }
        });
    }
});
</script>

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

<!-- Refund Request Modal -->
<div class="modal-overlay" id="refund-modal">
    <div class="modal glass-card" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">💳 İade Talebini Oluştur</h3>
            <button class="modal-close" data-modal-close>&times;</button>
        </div>
        <div class="modal-body">
            <div style="background: rgba(255,255,255,0.03); padding: 15px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); margin-bottom: 20px;">
                <p style="margin: 0 0 8px 0; font-size: 0.85rem; color: rgba(255,255,255,0.6);">📍 Etkinlik</p>
                <p id="refund-event-title-display" style="margin: 0; font-weight: 500; color: #fff;">-</p>

                <p style="margin: 12px 0 8px 0; font-size: 0.85rem; color: rgba(255,255,255,0.6);">🎟️ Bilet Kodu</p>
                <p id="refund-ticket-code-display" style="margin: 0; font-family: monospace; color: var(--accent-secondary);">-</p>
            </div>

            <form onsubmit="return false;">
                <input type="hidden" id="refund-ticket-id" value="">
                <div class="form-group mb-3">
                    <label class="form-label" for="refund-amount">İade Tutarı (₺)</label>
                    <input type="number" id="refund-amount" class="form-control glass-input" placeholder="0.00" step="0.01" min="0.01" required readonly>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label" for="refund-reason">İade Nedeni *</label>
                    <select id="refund-reason" class="form-control glass-input" required>
                        <option value="">Seçiniz...</option>
                        <option value="Planlama Değişikliği">📅 Planlama Değişikliği</option>
                        <option value="Etkinliğin Iptali">❌ Etkinliğin İptali</option>
                        <option value="Katılamayacağım">🚫 Katılamayacağım</option>
                        <option value="Bilet Satın Alma Hatası">⚠️ Hata/Yanlış Bilet</option>
                        <option value="Diğer Sebepler">❓ Diğer Sebepler</option>
                    </select>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label" for="refund-description">Ek Açıklama</label>
                    <textarea id="refund-description" class="form-control glass-input" placeholder="Detaylı açıklama ekleyiniz..." style="resize: vertical; height: 80px;"></textarea>
                </div>

                <div style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 0.85rem; color: rgba(255,255,255,0.8); line-height: 1.4;">
                    <p style="margin: 0 0 8px 0;"><strong>ℹ️ İade Koşulları:</strong></p>
                    <ul style="margin: 0; padding-left: 20px;">
                        <li>İade talebiniz incelenmek üzere yöneticilere iletilecektir.</li>
                        <li>Onay sonrasında 2-5 iş günü içinde geri ödeme yapılacaktır.</li>
                        <li>Etkinlikten 24 saat önce iptal iadeler kabul edilmektedir.</li>
                    </ul>
                </div>

                <button type="button" class="btn btn-primary btn-block" id="refund-submit-btn">
                    📤 İade Talebini Gönder
                </button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
