<?php
/**
 * Bilet-Geç Süperadmin - Aktivite Logları
 */

define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/activity_log.php';

start_secure_session();
require_superadmin();
ensure_user_schema($pdo);

global $pdo;

$action_filter = trim($_GET['action'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$total = count_activity_logs($pdo, $action_filter);
$total_pages = max(1, (int)ceil($total / $limit));
$logs = get_activity_logs($pdo, $action_filter, $limit, $offset);

$action_options = [
    '' => 'Tüm Aksiyonlar',
    'login' => 'Oturum Açma',
    'event_create' => 'Etkinlik Oluşturma',
    'event_update' => 'Etkinlik Güncelleme',
    'event_delete' => 'Etkinlik Silme',
    'event_status' => 'Etkinlik Durum Değişimi',
    'refund_approve' => 'İade Onayı',
    'refund_reject' => 'İade Reddi',
    'refund_complete' => 'İade Tamamlama',
    'user_role_change' => 'Kullanıcı Rol Değişimi',
];

$page_title = 'Aktivite Logları';
$current_page = 'activity_logs';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/_sidebar.php';
?>

<div class="admin-content">
    <div class="admin-header">
        <h1 class="page-title">📋 Aktivite Logları</h1>
        <p>Toplam <?php echo $total; ?> kayıt</p>
    </div>

    <div class="admin-toolbar">
        <form method="GET" class="admin-filter-form">
            <select name="action" class="form-input">
                <?php foreach ($action_options as $val => $label): ?>
                    <option value="<?php echo e($val); ?>" <?php echo $action_filter === $val ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Filtrele</button>
        </form>
    </div>

    <div class="glass-card admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Tarih</th>
                    <th>Kullanıcı</th>
                    <th>Aksiyon</th>
                    <th>Hedef</th>
                    <th>Detay</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="6" class="text-center">Log kaydı bulunamadı.</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $details = $log['details'] ? json_decode($log['details'], true) : [];
                        $detailsStr = is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : '';
                        ?>
                        <tr>
                            <td><?php echo date('d.m.Y H:i:s', strtotime($log['created_at'])); ?></td>
                            <td><?php echo e($log['actor_name']); ?><br><small><?php echo e($log['actor_email']); ?></small></td>
                            <td><?php echo e(activity_action_label($log['action'])); ?></td>
                            <td><?php echo e(($log['target_type'] ?? '') . ($log['target_id'] ? ' #' . $log['target_id'] : '')); ?></td>
                            <td class="log-details"><?php echo e($detailsStr); ?></td>
                            <td><?php echo e($log['ip_address'] ?? '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
        <div class="pagination-bar">
            <?php for ($i = 1; $i <= min($total_pages, 10); $i++): ?>
                <a href="?page=<?php echo $i; ?>&action=<?php echo urlencode($action_filter); ?>"
                   class="btn btn-sm <?php echo $i === $page ? 'btn-primary' : 'btn-outline'; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_sidebar_footer.php'; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
