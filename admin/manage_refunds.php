<?php
/**
 * Bilet-Geç Admin Panel - İade Yönetimi
 */

define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/refund_functions.php';

start_secure_session();
require_panel();

global $pdo;

$page_title = "İade Yönetimi";
$current_page = "manage_refunds";

$firma_filter = is_superadmin() ? null : get_current_user_id();

$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$valid_statuses = ['pending', 'approved', 'rejected', 'completed'];
if (!empty($status_filter) && !in_array($status_filter, $valid_statuses)) {
    $status_filter = '';
}

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$refunds = get_all_refunds($pdo, $status_filter, $limit, $offset, $firma_filter);
$stats = get_refund_statistics($pdo, $firma_filter);

if ($firma_filter !== null) {
    $countSql = 'SELECT COUNT(*) as total FROM refunds r JOIN events e ON r.event_id = e.id WHERE e.created_by = ?';
    $countParams = [$firma_filter];
    if (!empty($status_filter)) {
        $countSql .= ' AND r.status = ?';
        $countParams[] = $status_filter;
    }
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($countParams);
} else {
    $countSql = 'SELECT COUNT(*) as total FROM refunds WHERE 1=1';
    if (!empty($status_filter)) {
        $countSql .= ' AND status = ?';
        $stmt = $pdo->prepare($countSql);
        $stmt->execute([$status_filter]);
    } else {
        $stmt = $pdo->prepare($countSql);
        $stmt->execute();
    }
}
$total_count = (int)$stmt->fetch()['total'];
$total_pages = ceil($total_count / $limit);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/_sidebar.php';
?>

<div class="admin-content">
    <div class="refunds-page-header">
        <h1 class="page-title">💳 İade Yönetimi</h1>
        <div class="refunds-total">Toplam İade Talebi: <strong><?php echo $total_count; ?></strong></div>
    </div>

    <div class="refund-stats-grid">
        <div class="refund-stat-card refund-stat-pending">
            <div class="refund-stat-label">Beklemede</div>
            <div class="refund-stat-value"><?php echo (int)$stats['pending']['count']; ?></div>
            <div class="refund-stat-amount">₺<?php echo number_format($stats['pending']['total'], 2, ',', '.'); ?></div>
        </div>
        <div class="refund-stat-card refund-stat-approved">
            <div class="refund-stat-label">Onaylanan</div>
            <div class="refund-stat-value"><?php echo (int)$stats['approved']['count']; ?></div>
            <div class="refund-stat-amount">₺<?php echo number_format($stats['approved']['total'], 2, ',', '.'); ?></div>
        </div>
        <div class="refund-stat-card refund-stat-completed">
            <div class="refund-stat-label">Tamamlanan</div>
            <div class="refund-stat-value"><?php echo (int)$stats['completed']['count']; ?></div>
            <div class="refund-stat-amount">₺<?php echo number_format($stats['completed']['total'], 2, ',', '.'); ?></div>
        </div>
        <div class="refund-stat-card refund-stat-rejected">
            <div class="refund-stat-label">Reddedilen</div>
            <div class="refund-stat-value"><?php echo (int)$stats['rejected']['count']; ?></div>
            <div class="refund-stat-amount">₺<?php echo number_format($stats['rejected']['total'], 2, ',', '.'); ?></div>
        </div>
    </div>

    <div class="refund-filter-bar">
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

        <?php if (empty($refunds)): ?>
            <div class="empty-state glass-card">
                <div class="empty-icon">📋</div>
                <p>İade talebi bulunmamaktadır.</p>
            </div>
        <?php else: ?>
            <div class="glass-card admin-table-wrap">
                <table class="admin-table refunds-table">
                    <thead>
                        <tr>
                            <th>Kullanıcı</th>
                            <th>Etkinlik</th>
                            <th>Bilet Kodu</th>
                            <th>Tutar</th>
                            <th>Durum</th>
                            <th>Tarih</th>
                            <th>İşlem</th>
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
                            <tr>
                                <td>
                                    <div class="cell-primary"><?php echo htmlspecialchars($refund['full_name']); ?></div>
                                    <div class="cell-secondary"><?php echo htmlspecialchars($refund['email']); ?></div>
                                </td>
                                <td>
                                    <div class="cell-primary"><?php echo htmlspecialchars($refund['event_title']); ?></div>
                                    <div class="cell-secondary"><?php echo date('d M Y', strtotime($refund['event_date'])); ?></div>
                                </td>
                                <td><code class="ticket-code"><?php echo htmlspecialchars($refund['ticket_code']); ?></code></td>
                                <td class="text-right text-success">₺<?php echo number_format($refund['refund_amount'], 2, ',', '.'); ?></td>
                                <td class="text-center">
                                    <span class="refund-status-badge refund-status-<?php echo e($refund['status']); ?>">
                                        <?php echo $status_info['label']; ?>
                                    </span>
                                </td>
                                <td class="text-center cell-secondary"><?php echo date('d M Y H:i', strtotime($refund['requested_at'])); ?></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary refund-action-btn" 
                                            data-refund-id="<?php echo (int)$refund['id']; ?>"
                                            data-status="<?php echo htmlspecialchars($refund['status']); ?>">
                                        Yönet
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="pagination-bar">
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

<!-- Refund Action Modal -->
<div id="refund-modal" class="refund-modal-overlay">
    <div class="glass-card refund-modal-card">
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
                <label style="display: block; margin-bottom: 8px; color: rgba(255,255,255,0.8);">Ödeme Referans No (Opsiyonel):</label>
                <input id="refund-transaction-id" type="text" placeholder="Banka veya ödeme sağlayıcı referans numarası..." style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.2); background: rgba(0,0,0,0.3); color: #fff;">
                <small style="display: block; margin-top: 6px; color: rgba(255,255,255,0.55);">Boş bırakabilirsiniz; iade yine de tamamlanır.</small>
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

    fetch(APP_BASE + 'admin/api/manage_refunds.php', {
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

    fetch(APP_BASE + 'admin/api/manage_refunds.php', {
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
    const transactionInput = document.getElementById('refund-transaction-id');
    const transactionId = transactionInput ? transactionInput.value.trim() : '';
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    fetch(APP_BASE + 'admin/api/manage_refunds.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
        body: JSON.stringify({
            action: 'complete',
            refund_id: currentRefundId,
            transaction_id: transactionId
        })
    }).then(async (r) => {
        const data = await r.json().catch(() => null);
        if (!data) {
            alert('❌ Sunucudan geçersiz yanıt alındı.');
            return;
        }
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

<?php require_once __DIR__ . '/_sidebar_footer.php'; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
