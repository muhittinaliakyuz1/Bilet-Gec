
<?php
/**
 * İADE (REFUND) SİSTEMİ FONKSİYONLARI
 * Bilet iadesi talepleri, onaylanması ve geri ödeme işlemlemeleri
 */

/**
 * Iade talebini oluştur
 *
 * @param PDO $pdo Veritabanı bağlantısı
 * @param int $ticket_id Bilet ID
 * @param int $user_id Kullanıcı ID
 * @param float $refund_amount İade tutarı
 * @param string $reason İade nedeni
 * @param string $description Detaylı açıklama
 * @return array|false Iade verileri veya false
 */
function create_refund_request(PDO $pdo, int $ticket_id, int $user_id, float $refund_amount, 
                               string $reason, string $description = ''): array|false
{
    try {
        $pdo->beginTransaction();

        // Bileti ve etkinliği al
        $stmt = $pdo->prepare(
            'SELECT t.*, e.id AS event_id, e.title AS event_title 
             FROM tickets t 
             JOIN events e ON t.event_id = e.id 
             WHERE t.id = ? AND t.user_id = ?'
        );
        $stmt->execute([$ticket_id, $user_id]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            $pdo->rollBack();
            return false;
        }

        // Daha önce bu bilet için herhangi bir iade talebinin olup olmadığını kontrol et
        $stmt = $pdo->prepare(
            'SELECT id FROM refunds 
             WHERE ticket_id = ?'
        );
        $stmt->execute([$ticket_id]);
        if ($stmt->fetch()) {
            $pdo->rollBack();
            return false; // Zaten iade talebine sahip
        }

        // Kullanıcının gönderdiği tutar yerine biletin kayıtlı toplam tutarını kullan
        $refund_amount = (float)$ticket['total_price'];

        // Iade talebini oluştur
        $stmt = $pdo->prepare(
            'INSERT INTO refunds 
             (ticket_id, user_id, event_id, original_amount, refund_amount, reason, description, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, "pending")'
        );
        $stmt->execute([
            $ticket_id,
            $user_id,
            $ticket['event_id'],
            $ticket['total_price'],
            $refund_amount,
            $reason,
            $description
        ]);

        $refund_id = (int)$pdo->lastInsertId();

        // Durumu kaydet (audit trail)
        $stmt = $pdo->prepare(
            'INSERT INTO refund_status_log (refund_id, old_status, new_status, comment)
             VALUES (?, NULL, "pending", "İade talebı oluşturuldu")'
        );
        $stmt->execute([$refund_id]);

        $pdo->commit();

        // Iade verisini döndür
        $stmt = $pdo->prepare('SELECT * FROM refunds WHERE id = ?');
        $stmt->execute([$refund_id]);
        return $stmt->fetch();

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('İade talebı oluşturma hatası: ' . $e->getMessage());
        return false;
    }
}

/**
 * İade talebini onayla
 *
 * @param PDO $pdo Veritabanı bağlantısı
 * @param int $refund_id İade ID
 * @param int $admin_id Admin kullanıcı ID
 * @param string $refund_method İade yöntemi (card, wallet, bank_transfer)
 * @return array|false Güncellenmiş iade verileri veya false
 */
function approve_refund(PDO $pdo, int $refund_id, int $admin_id, 
                       string $refund_method = 'card'): array|false
{
    try {
        $pdo->beginTransaction();

        // İade talebini al
        $stmt = $pdo->prepare('SELECT * FROM refunds WHERE id = ?');
        $stmt->execute([$refund_id]);
        $refund = $stmt->fetch();

        if (!$refund || $refund['status'] !== 'pending') {
            $pdo->rollBack();
            return false;
        }

        // Iade talebini onayla
        $stmt = $pdo->prepare(
            'UPDATE refunds 
             SET status = "approved", processed_by = ?, processed_at = NOW(), refund_method = ?
             WHERE id = ?'
        );
        $stmt->execute([$admin_id, $refund_method, $refund_id]);

        // Durumu kaydet (audit trail)
        $stmt = $pdo->prepare(
            'INSERT INTO refund_status_log (refund_id, old_status, new_status, changed_by, comment)
             VALUES (?, "pending", "approved", ?, "İade talebı onaylandı")'
        );
        $stmt->execute([$refund_id, $admin_id]);

        $pdo->commit();

        // Güncellenmiş iade verisini döndür
        $stmt = $pdo->prepare('SELECT * FROM refunds WHERE id = ?');
        $stmt->execute([$refund_id]);
        return $stmt->fetch();

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('İade onaylama hatası: ' . $e->getMessage());
        return false;
    }
}

/**
 * İade talebini reddet
 *
 * @param PDO $pdo Veritabanı bağlantısı
 * @param int $refund_id İade ID
 * @param int $admin_id Admin kullanıcı ID
 * @param string $rejection_reason Reddetme nedeni
 * @return array|false Güncellenmiş iade verileri veya false
 */
function reject_refund(PDO $pdo, int $refund_id, int $admin_id, 
                      string $rejection_reason = ''): array|false
{
    try {
        $pdo->beginTransaction();

        // İade talebini al
        $stmt = $pdo->prepare('SELECT * FROM refunds WHERE id = ?');
        $stmt->execute([$refund_id]);
        $refund = $stmt->fetch();

        if (!$refund || $refund['status'] !== 'pending') {
            $pdo->rollBack();
            return false;
        }

        // İade talebini reddet
        $stmt = $pdo->prepare(
            'UPDATE refunds 
             SET status = "rejected", processed_by = ?, processed_at = NOW(), rejection_reason = ?
             WHERE id = ?'
        );
        $stmt->execute([$admin_id, $rejection_reason, $refund_id]);

        // Durumu kaydet (audit trail)
        $stmt = $pdo->prepare(
            'INSERT INTO refund_status_log (refund_id, old_status, new_status, changed_by, comment)
             VALUES (?, "pending", "rejected", ?, ?)'
        );
        $stmt->execute([$refund_id, $admin_id, $rejection_reason]);

        $pdo->commit();

        // Güncellenmiş iade verisini döndür
        $stmt = $pdo->prepare('SELECT * FROM refunds WHERE id = ?');
        $stmt->execute([$refund_id]);
        return $stmt->fetch();

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('İade reddetme hatası: ' . $e->getMessage());
        return false;
    }
}

/**
 * İade işlemini tamamla (geri ödemeyi gerçekleştir)
 *
 * @param PDO $pdo Veritabanı bağlantısı
 * @param int $refund_id İade ID
 * @param int $admin_id Admin kullanıcı ID
 * @param string $transaction_id Ödeme işlem ID
 * @return array|false Güncellenmiş iade verileri veya false
 */
function complete_refund(PDO $pdo, int $refund_id, int $admin_id, 
                        string $transaction_id = ''): array|false
{
    try {
        $pdo->beginTransaction();

        // İade talebini al
        $stmt = $pdo->prepare('SELECT * FROM refunds WHERE id = ?');
        $stmt->execute([$refund_id]);
        $refund = $stmt->fetch();

        if (!$refund || $refund['status'] !== 'approved') {
            $pdo->rollBack();
            return false;
        }

        // İade işlemini tamamla
        $stmt = $pdo->prepare(
            'UPDATE refunds 
             SET status = "completed", processed_by = ?, processed_at = NOW(), transaction_id = ?
             WHERE id = ?'
        );
        $stmt->execute([$admin_id, $transaction_id, $refund_id]);

        // Durumu kaydet (audit trail)
        $stmt = $pdo->prepare(
            'INSERT INTO refund_status_log (refund_id, old_status, new_status, changed_by, comment)
             VALUES (?, "approved", "completed", ?, "İade işlemi tamamlandı")'
        );
        $stmt->execute([$refund_id, $admin_id]);

        // Sadakat puanlarını geri al (eğer varsa)
        if (!empty($refund['user_id'])) {
            $stmt = $pdo->prepare(
                'SELECT t.id FROM tickets t WHERE t.id = ? LIMIT 1'
            );
            $stmt->execute([$refund['ticket_id']]);
            if ($stmt->fetch()) {
                // Biletle ilgili puanları iptal et
                add_loyalty_points($pdo, $refund['user_id'], 0, 'expire', 
                                 'İade nedeniyle puanlar iptal edildi', $refund['ticket_id']);
            }
        }

        $pdo->commit();

        // Güncellenmiş iade verisini döndür
        $stmt = $pdo->prepare('SELECT * FROM refunds WHERE id = ?');
        $stmt->execute([$refund_id]);
        return $stmt->fetch();

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('İade tamamlama hatası: ' . $e->getMessage());
        return false;
    }
}

/**
 * Bir bilete ait tüm iade taleplerini getir
 *
 * @param PDO $pdo Veritabanı bağlantısı
 * @param int $ticket_id Bilet ID
 * @return array İade talepleri
 */
function get_ticket_refunds(PDO $pdo, int $ticket_id): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT r.*, u.full_name, u.email, e.title AS event_title
             FROM refunds r
             JOIN users u ON r.user_id = u.id
             JOIN events e ON r.event_id = e.id
             WHERE r.ticket_id = ?
             ORDER BY r.requested_at DESC'
        );
        $stmt->execute([$ticket_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Bilet iadelerini getirme hatası: ' . $e->getMessage());
        return [];
    }
}

/**
 * Bir kullanıcıya ait tüm iade taleplerini getir
 *
 * @param PDO $pdo Veritabanı bağlantısı
 * @param int $user_id Kullanıcı ID
 * @param string $status İade durumu filtresi
 * @return array İade talepleri
 */
function get_user_refunds(PDO $pdo, int $user_id, string $status = ''): array
{
    try {
        $sql = 'SELECT r.*, e.title AS event_title, e.event_date, e.venue, t.ticket_code
                FROM refunds r
                JOIN events e ON r.event_id = e.id
                JOIN tickets t ON r.ticket_id = t.id
                WHERE r.user_id = ?';
        
        $params = [$user_id];
        
        if (!empty($status)) {
            $sql .= ' AND r.status = ?';
            $params[] = $status;
        }
        
        $sql .= ' ORDER BY r.requested_at DESC';
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Kullanıcı iadelerini getirme hatası: ' . $e->getMessage());
        return [];
    }
}

/**
 * Tüm iade taleplerini getir (admin paneli için)
 *
 * @param PDO $pdo Veritabanı bağlantısı
 * @param string $status İade durumu filtresi
 * @param int $limit Limit
 * @param int $offset Offset
 * @return array İade talepleri
 */
function get_all_refunds(PDO $pdo, string $status = '', int $limit = 50, int $offset = 0): array
{
    try {
        $sql = 'SELECT r.*, u.full_name, u.email, e.title AS event_title, e.event_date, 
                        t.ticket_code, admin.full_name AS processed_by_name
                FROM refunds r
                JOIN users u ON r.user_id = u.id
                JOIN events e ON r.event_id = e.id
                JOIN tickets t ON r.ticket_id = t.id
                LEFT JOIN users admin ON r.processed_by = admin.id
                WHERE 1=1';
        
        $params = [];
        
        if (!empty($status)) {
            $sql .= ' AND r.status = ?';
            $params[] = $status;
        }
        
        $sql .= ' ORDER BY r.requested_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Tüm iadelerini getirme hatası: ' . $e->getMessage());
        return [];
    }
}

/**
 * İade durumu değişim geçmişini getir
 *
 * @param PDO $pdo Veritabanı bağlantısı
 * @param int $refund_id İade ID
 * @return array Durumu değişim geçmişi
 */
function get_refund_status_history(PDO $pdo, int $refund_id): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT rsl.*, u.full_name
             FROM refund_status_log rsl
             LEFT JOIN users u ON rsl.changed_by = u.id
             WHERE rsl.refund_id = ?
             ORDER BY rsl.created_at ASC'
        );
        $stmt->execute([$refund_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('İade durumu geçmişini getirme hatası: ' . $e->getMessage());
        return [];
    }
}

/**
 * İade istatistiklerini getir
 *
 * @param PDO $pdo Veritabanı bağlantısı
 * @return array İstatistikler
 */
function get_refund_statistics(PDO $pdo): array
{
    try {
        $stats = [];
        
        // İade istatistiklerini durum bazlı al
        $statuses = ['pending', 'approved', 'completed', 'rejected'];
        foreach ($statuses as $s) {
            $stmt = $pdo->prepare('SELECT COUNT(*) as count, COALESCE(SUM(refund_amount), 0) as total FROM refunds WHERE status = ?');
            $stmt->execute([$s]);
            $stats[$s] = $stmt->fetch();
        }
        
        return $stats;
    } catch (PDOException $e) {
        error_log('İade istatistiklerini getirme hatası: ' . $e->getMessage());
        return [];
    }
}
