<?php
/**
 * Bilet-Geç Süperadmin - Kullanıcı Yönetimi
 */

define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/activity_log.php';

start_secure_session();
require_superadmin();
ensure_user_schema($pdo);

global $pdo;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        set_flash('error', 'Güvenlik doğrulaması başarısız.');
        header('Location: ' . base_url('admin/manage_users.php'));
        exit;
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'change_role') {
        $target_id = (int)($_POST['user_id'] ?? 0);
        $new_role = $_POST['new_role'] ?? '';
        $allowed_roles = ['user', 'firma'];

        if ($target_id <= 0 || !in_array($new_role, $allowed_roles, true)) {
            set_flash('error', 'Geçersiz parametre.');
            header('Location: ' . base_url('admin/manage_users.php'));
            exit;
        }

        if ($target_id === get_current_user_id()) {
            set_flash('error', 'Kendi rolünüzü değiştiremezsiniz.');
            header('Location: ' . base_url('admin/manage_users.php'));
            exit;
        }

        $stmt = $pdo->prepare('SELECT id, full_name, email, role FROM users WHERE id = ?');
        $stmt->execute([$target_id]);
        $target = $stmt->fetch();

        if (!$target || $target['role'] === 'superadmin') {
            set_flash('error', 'Bu kullanıcının rolü değiştirilemez.');
            header('Location: ' . base_url('admin/manage_users.php'));
            exit;
        }

        $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
        if ($stmt->execute([$new_role, $target_id])) {
            log_activity($pdo, get_current_user_id(), 'user_role_change', 'user', $target_id, [
                'email' => $target['email'],
                'old_role' => $target['role'],
                'new_role' => $new_role,
            ]);
            set_flash('success', $target['full_name'] . ' kullanıcısının rolü "' . $new_role . '" olarak güncellendi.');
        } else {
            set_flash('error', 'Rol güncellenemedi.');
        }
    }

    header('Location: ' . base_url('admin/manage_users.php'));
    exit;
}

$role_filter = $_GET['role'] ?? '';
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

$sql = 'SELECT id, full_name, email, phone, role, email_verified, created_at FROM users WHERE 1=1';
$countSql = 'SELECT COUNT(*) FROM users WHERE 1=1';
$params = [];

if ($role_filter !== '' && in_array($role_filter, ['user', 'firma', 'superadmin'], true)) {
    $sql .= ' AND role = ?';
    $countSql .= ' AND role = ?';
    $params[] = $role_filter;
}

if ($search !== '') {
    $sql .= ' AND (full_name LIKE ? OR email LIKE ?)';
    $countSql .= ' AND (full_name LIKE ? OR email LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

$sql .= ' ORDER BY created_at DESC LIMIT ? OFFSET ?';

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$total_pages = max(1, (int)ceil($total / $limit));

$listParams = array_merge($params, [$limit, $offset]);
$stmt = $pdo->prepare($sql);
$stmt->execute($listParams);
$users = $stmt->fetchAll();

$csrf_token = generate_csrf_token();
$flash = get_flash();
$roleLabels = ['superadmin' => 'Süperadmin', 'firma' => 'Firma', 'user' => 'Kullanıcı', 'admin' => 'Firma'];

$page_title = 'Kullanıcı Yönetimi';
$current_page = 'manage_users';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/_sidebar.php';
?>

<div class="admin-content">
    <div class="admin-header">
        <h1 class="page-title">👥 Kullanıcı Yönetimi</h1>
        <p>Toplam <?php echo $total; ?> kullanıcı</p>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'error'; ?>">
            <span><?php echo e($flash['message']); ?></span>
        </div>
    <?php endif; ?>

    <div class="admin-toolbar">
        <form method="GET" class="admin-filter-form">
            <input type="text" name="q" class="form-input" placeholder="Ad veya e-posta ara..." value="<?php echo e($search); ?>">
            <select name="role" class="form-input">
                <option value="">Tüm Roller</option>
                <option value="user" <?php echo $role_filter === 'user' ? 'selected' : ''; ?>>Kullanıcı</option>
                <option value="firma" <?php echo $role_filter === 'firma' ? 'selected' : ''; ?>>Firma</option>
                <option value="superadmin" <?php echo $role_filter === 'superadmin' ? 'selected' : ''; ?>>Süperadmin</option>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Filtrele</button>
        </form>
    </div>

    <div class="glass-card admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Ad</th>
                    <th>E-posta</th>
                    <th>Rol</th>
                    <th>Kayıt</th>
                    <th>İşlem</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="5" class="text-center">Kullanıcı bulunamadı.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?php echo e($u['full_name']); ?></td>
                            <td><?php echo e($u['email']); ?></td>
                            <td><span class="role-badge role-<?php echo e($u['role']); ?>"><?php echo e($roleLabels[$u['role']] ?? $u['role']); ?></span></td>
                            <td><?php echo date('d.m.Y', strtotime($u['created_at'])); ?></td>
                            <td>
                                <?php if ($u['role'] !== 'superadmin' && (int)$u['id'] !== get_current_user_id()): ?>
                                    <form method="POST" class="inline-role-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                        <input type="hidden" name="action" value="change_role">
                                        <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                        <?php if ($u['role'] === 'user' || $u['role'] === 'admin'): ?>
                                            <input type="hidden" name="new_role" value="firma">
                                            <button type="submit" class="btn btn-sm btn-primary" onclick="return confirm('Bu kullanıcıyı firma yapmak istediğinize emin misiniz?');">Firma Yap</button>
                                        <?php elseif ($u['role'] === 'firma'): ?>
                                            <input type="hidden" name="new_role" value="user">
                                            <button type="submit" class="btn btn-sm btn-secondary" onclick="return confirm('Bu firmayı kullanıcı yapmak istediğinize emin misiniz?');">Kullanıcı Yap</button>
                                        <?php endif; ?>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
        <div class="pagination-bar">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?page=<?php echo $i; ?>&role=<?php echo urlencode($role_filter); ?>&q=<?php echo urlencode($search); ?>"
                   class="btn btn-sm <?php echo $i === $page ? 'btn-primary' : 'btn-outline'; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_sidebar_footer.php'; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
