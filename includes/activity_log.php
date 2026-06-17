<?php
/**
 * Bilet-Geç - Aktivite Log Yardımcıları
 */

if (!defined('ALLOWED_ACCESS')) {
    die('Doğrudan erişim yasaktır.');
}

/**
 * Aktivite log kaydı oluştur
 */
function log_activity(
    PDO $pdo,
    int $actor_id,
    string $action,
    ?string $target_type = null,
    ?int $target_id = null,
    ?array $details = null
): bool {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $pdo->prepare(
            'INSERT INTO activity_logs (actor_id, action, target_type, target_id, details, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $json = $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE) : null;
        return $stmt->execute([$actor_id, $action, $target_type, $target_id, $json, $ip]);
    } catch (PDOException $e) {
        error_log('log_activity hatası: ' . $e->getMessage());
        return false;
    }
}

/**
 * Aktivite loglarını getir (süperadmin paneli)
 */
function get_activity_logs(PDO $pdo, string $action_filter = '', int $limit = 50, int $offset = 0): array
{
    try {
        $sql = 'SELECT al.*, u.full_name AS actor_name, u.email AS actor_email
                FROM activity_logs al
                JOIN users u ON al.actor_id = u.id
                WHERE 1=1';
        $params = [];

        if ($action_filter !== '') {
            $sql .= ' AND al.action = ?';
            $params[] = $action_filter;
        }

        $sql .= ' ORDER BY al.created_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('get_activity_logs hatası: ' . $e->getMessage());
        return [];
    }
}

/**
 * Aktivite log sayısı
 */
function count_activity_logs(PDO $pdo, string $action_filter = ''): int
{
    try {
        $sql = 'SELECT COUNT(*) AS total FROM activity_logs WHERE 1=1';
        $params = [];
        if ($action_filter !== '') {
            $sql .= ' AND action = ?';
            $params[] = $action_filter;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetch()['total'];
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * İnsan okunabilir aksiyon etiketi
 */
function activity_action_label(string $action): string
{
    $labels = [
        'login'              => 'Oturum Açma',
        'event_create'       => 'Etkinlik Oluşturma',
        'event_update'       => 'Etkinlik Güncelleme',
        'event_delete'       => 'Etkinlik Silme',
        'event_status'       => 'Etkinlik Durum Değişimi',
        'refund_approve'     => 'İade Onayı',
        'refund_reject'      => 'İade Reddi',
        'refund_complete'    => 'İade Tamamlama',
        'user_role_change'   => 'Kullanıcı Rol Değişimi',
    ];
    return $labels[$action] ?? $action;
}
