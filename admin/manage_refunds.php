<?php
/**
 * Bilet-Geç Admin Panel - İade Yönetimi
 */

define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/refund_functions.php';

start_secure_session();
require_admin();

global $pdo;

$page_title = "İade Yönetimi";
$current_page = "manage_refunds";

// Get filter parameter
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$valid_statuses = ['pending', 'approved', 'rejected', 'completed'];
if (!empty($status_filter) && !in_array($status_filter, $valid_statuses)) {
    $status_filter = '';
}

// Get pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Fetch refunds
$refunds = get_all_refunds($pdo, $status_filter, $limit, $offset);

// Get statistics
$stats = get_refund_statistics($pdo);

// Get total count
$sql = 'SELECT COUNT(*) as total FROM refunds WHERE 1=1';
if (!empty($status_filter)) {
    $sql .= ' AND status = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$status_filter]);
} else {
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
}
$total_count = (int)$stmt->fetch()['total'];
$total_pages = ceil($total_count / $limit);

require_once __DIR__ . '/../includes/header.php';
?>

<section class="manage-refunds-section">
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
            <h1 class="page-title">💳 İade Yönetimi</h1>
            <div style="font-size: 0.9rem; color: rgba(255,255,255,0.7);">
                Toplam İade Talebi: <strong><?php echo $total_count; ?></strong>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px;">
            <div class="glass-card" style="padding: 20px; text-align: center; background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3);">
                <div style="font-size: 0.9rem; color: rgba(255,255,255,0.7); margin-bottom: 10px;">Beklemede</div>
                <div style="font-size: 1.8rem; font-weight: bold; color: #60a5fa;">
                    <?php echo (int)$stats['pending']['count']; ?>
                </div>
                <div style="font-size: 0.8rem; color: rgba(255,255,255,0.5); margin-top: 5px;">
                    ₺<?php echo number_format($stats['pending']['total'], 2, ',', '.'); ?>
                </div>
            </div>

            <div class="glass-card" style="padding: 20px; text-align: center; background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.3);">
                <div style="font-size: 0.9rem; color: rgba(255,255,255,0.7); margin-bottom: 10px;">Onaylanan</div>
                <div style="font-size: 1.8rem; font-weight: bold; color: #4ade80;">
                    <?php echo (int)$stats['approved']['count']; ?>
                </div>
                <div style="font-size: 0.8rem; color: rgba(255,255,255,0.5); margin-top: 5px;">
                    ₺<?php echo number_format($stats['approved']['total'], 2, ',', '.'); ?>
                </div>
            </div>

            <div class="glass-card" style="padding: 20px; text-align: center; background: rgba(6, 182, 212, 0.1); border: 1px solid rgba(6, 182, 212, 0.3);">
                <div style="font-size: 0.9rem; color: rgba(255,255,255,0.7); margin-bottom: 10px;">Tamamlanan</div>
                <div style="font-size: 1.8rem; font-weight: bold; color: #22d3ee;">
                    <?php echo (int)$stats['completed']['count']; ?>
                </div>
                <div style="font-size: 0.8rem; color: rgba(255,255,255,0.5); margin-top: 5px;">
                    ₺<?php echo number_format($stats['completed']['total'], 2, ',', '.'); ?>
                </div>
            </div>

            <div class="glass-card" style="padding: 20px; text-align: center; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3);">
                <div style="font-size: 0.9rem; color: rgba(255,255,255,0.7); margin-bottom: 10px;">Reddedilen</div>
                <div style="font-size: 1.8rem; font-weight: bold; color: #f87171;">
                    <?php echo (int)$stats['rejected']['count']; ?>
                </div>
                <div style="font-size: 0.8rem; color: rgba(255,255,255,0.5); margin-top: 5px;">
                    ₺<?php echo number_format($stats['rejected']['total'], 2, ',', '.'); ?>
                </div>
            </div>
        </div>

        <!-- Filter Buttons -->
        <div style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="?status=" class="btn <?php echo empty($status_filter) ? 'btn-primary' : 'btn-outline'; ?> btn-sm">
                Tümü
            </a>
            <a href="?status=pending" class="btn <?php echo $status_filter === 'pending' ? 'btn-primary' : 'btn-outline'; ?> btn-sm">
                ⏳ Beklemede (<?php echo (int)$stats['pending']['count']; ?>)
            </a>
            <a href="?status=approved" class="btn <?php echo $status_filter === 'approved' ? 'btn-primary' : 'btn-outline'; ?> btn-sm">
                ✅ Onaylanan (<?php echo (int)$stats['approved']['count']; ?>)
            </a>
            <a href="?status=completed" class="btn <?php echo $status_filter === 'completed' ? 'btn-primary' : 'btn-outline'; ?> btn-sm">
                💳 Tamamlanan (<?php echo (int)$stats['completed']['count']; ?>)
            </a>
            <a href="?status=rejected" class="btn <?php echo $status_filter === 'rejected' ? 'btn-primary' : 'btn-outline'; ?> btn-sm">
                ❌ Reddedilen (<?php echo (int)$stats['rejected']['count']; ?>)
            </a>
        </div>

        <!-- Refunds Table -->
        <?php if (empty($refunds)): ?>
            <div class="empty-state glass-card" style="padding: 60px 20px;">
                <div class="empty-icon">📋</div>
                <p>İade talebiniz bulunmamaktadır.</p>
            </div>
        <?php else: ?>
            <div class="glass-card" style="overflow-x: auto; padding: 0;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                    <thead>
                        <tr style="border-bottom: 2px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.05);">
                            <th style="padding: 15px; text-align: left; color: rgba(255,255,255,0.7);">Kullanıcı</th>
                            <th style="padding: 15px; text-align: left; color: rgba(255,255,255,0.7);">Etkinlik</th>
                            <th style="padding: 15px; text-align: left; color: rgba(255,255,255,0.7);">Bilet Kodu</th>
                            <th style="padding: 15px; text-align: right; color: rgba(255,255,255,0.7);">Tutar</th>
                            <th style="padding: 15px; text-align: center; color: rgba(255,255,255,0.7);">Durum</th>
                            <th style="padding: 15px; text-align: center; color: rgba(255,255,255,0.7);">Tarih</th>
                            <th style="padding: 15px; text-align: center; color: rgba(255,255,255,0.7);">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($refunds as $refund): 
                            $status_colors = [
                                'pending' => ['bg' => 'rgba(59, 130, 246, 0.2)', 'border' => 'rgba(59, 130, 246, 0.5)', 'label' => '⏳ Beklemede'],
                                'approved' => ['bg' => 'rgba(34, 197, 94, 0.2)', 'border' => 'rgba(34, 197, 94, 0.5)', 'label' => '✅ Onaylanan'],
                                'rejected' => ['bg' => 'rgba(239, 68, 68, 0.2)', 'border' => 'rgba(239, 68, 68, 0.5)', 'label' => '❌ Reddedilen'],
                                'completed' => ['bg' => 'rgba(6, 182, 212, 0.2)', 'border' => 'rgba(6, 182, 212, 0.5)', 'label' => '💳 Tamamlanan'],
                            ];
                            $status_info = $status_colors[$refund['status']] ?? $status_colors['pending'];
                        ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                <td style="padding: 15px;">
                                    <div style="font-weight: 500; color: #fff;"><?php echo htmlspecialchars($refund['full_name']); ?></div>
                                    <div style="font-size: 0.8rem; color: rgba(255,255,255,0.5);"><?php echo htmlspecialchars($refund['email']); ?></div>
                                </td>
                                <td style="padding: 15px;">
                                    <div style="color: #fff;"><?php echo htmlspecialchars($refund['event_title']); ?></div>
                                    <div style="font-size: 0.8rem; color: rgba(255,255,255,0.5);">
                                        <?php echo date('d M Y', strtotime($refund['event_date'])); ?>
                                    </div>
                                </td>
                                <td style="padding: 15px;">
                                    <code style="background: rgba(0,0,0,0.3); padding: 5px 10px; border-radius: 4px; font-size: 0.85rem;">
                                        <?php echo htmlspecialchars($refund['ticket_code']); ?>
                                    </code>
                                </td>
                                <td style="padding: 15px; text-align: right; font-weight: 500; color: #4ade80;">
                                    ₺<?php echo number_format($refund['refund_amount'], 2, ',', '.'); ?>
                                </td>
                                <td style="padding: 15px; text-align: center;">
                                    <span style="background: <?php echo $status_info['bg']; ?>; border: 1px solid <?php echo $status_info['border']; ?>; padding: 6px 12px; border-radius: 6px; font-size: 0.85rem; display: inline-block;">
                                        <?php echo $status_info['label']; ?>
                                    </span>
                                </td>
                                <td style="padding: 15px; text-align: center; font-size: 0.85rem; color: rgba(255,255,255,0.6);">
                                    <?php echo date('d M Y H:i', strtotime($refund['requested_at'])); ?>
                                </td>
                                <td style="padding: 15px; text-align: center;">
                                    <button class="refund-action-btn" 
                                            data-refund-id="<?php echo (int)$refund['id']; ?>"
                                            data-status="<?php echo htmlspecialchars($refund['status']); ?>"
                                            style="padding: 6px 12px; background: rgba(59, 130, 246, 0.3); border: 1px solid rgba(59, 130, 246, 0.5); color: #60a5fa; border-radius: 6px; cursor: pointer; font-size: 0.85rem;">
                                        Yönet
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div style="margin-top: 20px; display: flex; justify-content: center; gap: 10px; flex-wrap: wrap;">
                    <?php if ($page > 1): ?>
                        <a href="?page=1<?php echo !empty($status_filter) ? '&status=' . $status_filter : ''; ?>" class="btn btn-outline btn-sm">«</a>
                        <a href="?page=<?php echo $page - 1; ?><?php echo !empty($status_filter) ? '&status=' . $status_filter : ''; ?>" class="btn btn-outline btn-sm">‹</a>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <a href="?page=<?php echo $i; ?><?php echo !empty($status_filter) ? '&status=' . $status_filter : ''; ?>" 
                           class="btn <?php echo $i === $page ? 'btn-primary' : 'btn-outline'; ?> btn-sm">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?><?php echo !empty($status_filter) ? '&status=' . $status_filter : ''; ?>" class="btn btn-outline btn-sm">›</a>
                        <a href="?page=<?php echo $total_pages; ?><?php echo !empty($status_filter) ? '&status=' . $status_filter : ''; ?>" class="btn btn-outline btn-sm">»</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<!-- Refund Action Modal -->
<div id="refund-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 1000; align-items: center; justify-content: center;">
    <div class="glass-card" style="max-width: 500px; width: 90%; padding: 30px; max-height: 90vh; overflow-y: auto;">
        <h2 id="modal-title" style="margin-top: 0; margin-bottom: 20px;">İade Talebini Yönet</h2>
        
        <div id="pending-actions">
            <p style="margin-bottom: 15px; color: rgba(255,255,255,0.8);">Bu iade talebini onayla veya reddet:</p>
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 8px; color: rgba(255,255,255,0.8);">İade Yöntemi:</label>
                <select id="refund-method" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.2); background: rgba(0,0,0,0.3); color: #fff;">
                    <option value="card">Kredi Kartı</option>
                    <option value="wallet">E-Cüzdan</option>
                    <option value="bank_transfer">Banka Transferi</option>
                </select>
            </div>
            <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                <button class="btn btn-primary" onclick="approveRefund()" style="flex: 1;">✅ Onayla</button>
                <button class="btn btn-outline" onclick="showRejectForm()" style="flex: 1;">❌ Reddet</button>
            </div>
        </div>

        <div id="reject-form" style="display: none;">
            <label style="display: block; margin-bottom: 8px; color: rgba(255,255,255,0.8);">Reddetme Nedeni:</label>
            <textarea id="rejection-reason" placeholder="Neden reddettiğinizi açıklayın..." style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.2); background: rgba(0,0,0,0.3); color: #fff; height: 100px; resize: vertical; margin-bottom: 15px;"></textarea>
            <div style="display: flex; gap: 10px;">
                <button class="btn btn-primary" onclick="rejectRefund()" style="flex: 1;">Reddet</button>
                <button class="btn btn-outline" onclick="hidRejectForm()" style="flex: 1;">İptal</button>
            </div>
        </div>

        <div id="approved-actions" style="display: none;">
            <p style="margin-bottom: 15px; color: rgba(255,255,255,0.8);">Bu iade talebini tamamlandı olarak işaretle:</p>
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 8px; color: rgba(255,255,255,0.8);">İşlem ID (İsteğe Bağlı):</label>
                <input id="transaction-id" type="text" placeholder="Transaction ID..." style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.2); background: rgba(0,0,0,0.3); color: #fff;">
            </div>
            <button class="btn btn-primary" onclick="completeRefund()" style="width: 100%;">💳 Tamamla</button>
        </div>

        <button onclick="closeRefundModal()" style="position: absolute; top: 15px; right: 15px; background: none; border: none; color: rgba(255,255,255,0.6); font-size: 1.5rem; cursor: pointer;">✕</button>
    </div>
</div>

<script>
let currentRefundId = null;
let currentRefundStatus = null;

document.querySelectorAll('.refund-action-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        currentRefundId = parseInt(btn.dataset.refundId);
        currentRefundStatus = btn.dataset.status;
        openRefundModal();
    });
});

function openRefundModal() {
    const modal = document.getElementById('refund-modal');
    const pendingActions = document.getElementById('pending-actions');
    const approvedActions = document.getElementById('approved-actions');
    const rejectForm = document.getElementById('reject-form');

    rejectForm.style.display = 'none';
    
    if (currentRefundStatus === 'pending') {
        pendingActions.style.display = 'block';
        approvedActions.style.display = 'none';
    } else if (currentRefundStatus === 'approved') {
        pendingActions.style.display = 'none';
        approvedActions.style.display = 'block';
    }

    modal.style.display = 'flex';
}

function closeRefundModal() {
    document.getElementById('refund-modal').style.display = 'none';
    currentRefundId = null;
}

function showRejectForm() {
    document.getElementById('pending-actions').style.display = 'none';
    document.getElementById('reject-form').style.display = 'block';
}

function hideRejectForm() {
    document.getElementById('reject-form').style.display = 'none';
    document.getElementById('pending-actions').style.display = 'block';
}

function approveRefund() {
    const method = document.getElementById('refund-method').value;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    fetch('/ilterhoca/admin/api/manage_refunds.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
        body: JSON.stringify({
            action: 'approve',
            refund_id: currentRefundId,
            refund_method: method
        })
    }).then(r => r.json()).then(data => {
        if (data.success) {
            alert('✅ ' + data.message);
            location.reload();
        } else {
            alert('❌ ' + data.message);
        }
    });
}

function rejectRefund() {
    const reason = document.getElementById('rejection-reason').value;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    fetch('/ilterhoca/admin/api/manage_refunds.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
        body: JSON.stringify({
            action: 'reject',
            refund_id: currentRefundId,
            rejection_reason: reason
        })
    }).then(r => r.json()).then(data => {
        if (data.success) {
            alert('✅ ' + data.message);
            location.reload();
        } else {
            alert('❌ ' + data.message);
        }
    });
}

function completeRefund() {
    const transactionId = document.getElementById('transaction-id').value;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    fetch('/ilterhoca/admin/api/manage_refunds.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
        body: JSON.stringify({
            action: 'complete',
            refund_id: currentRefundId,
            transaction_id: transactionId
        })
    }).then(r => r.json()).then(data => {
        if (data.success) {
            alert('✅ ' + data.message);
            location.reload();
        } else {
            alert('❌ ' + data.message);
        }
    });
}

// Close modal when clicking outside
document.getElementById('refund-modal').addEventListener('click', (e) => {
    if (e.target.id === 'refund-modal') closeRefundModal();
});

// Backwards-compatible wrapper for typo in inline onclick attribute
function hidRejectForm() { hideRejectForm(); }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
