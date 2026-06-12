<?php
require_once __DIR__ . '/../config/database.php';

/**
 * Recalculates and updates the status column for all rates based on today's date.
 * Call this once per request (or via a daily cron/scheduler).
 */
function syncRateStatuses(): void {
    $db = getDB();
    // Thresholds match the dashboard image card labels:
    //   Active        > 6 months remaining
    //   Expiring Soon   3–6 months remaining
    //   Critical Expiry 0–3 months remaining
    //   Expired         past effective_through date
    $db->query("
        UPDATE rates SET status = CASE
            WHEN effective_through <  CURDATE()                              THEN 'Expired'
            WHEN effective_through <= DATE_ADD(CURDATE(), INTERVAL 3 MONTH) THEN 'Critical Expiry'
            WHEN effective_through <= DATE_ADD(CURDATE(), INTERVAL 6 MONTH) THEN 'Expiring Soon'
            ELSE 'Active'
        END
    ");
}

/**
 * Returns counts per status for the summary cards.
 */
function getStatusCounts(): array {
    $db = getDB();
    $result = $db->query("
        SELECT status, COUNT(*) AS total
        FROM rates
        GROUP BY status
    ");
    $counts = ['Active' => 0, 'Expiring Soon' => 0, 'Critical Expiry' => 0, 'Expired' => 0];
    while ($row = $result->fetch_assoc()) {
        $counts[$row['status']] = (int)$row['total'];
    }
    return $counts;
}

/**
 * Returns rates with optional filters applied.
 *
 * @param array $filters  Keys: search, status, cc_id, type, expiry_from, expiry_to
 */
function getRates(array $filters = []): array {
    $db   = getDB();
    $sql  = "
        SELECT r.id, r.rate_code, r.rate_description,
               cc.name AS customer_carrier, r.customer_carrier_id, r.type,
               r.effective_from, r.effective_through, r.status, r.notes,
               DATEDIFF(r.effective_through, CURDATE()) AS days_to_expiry
        FROM rates r
        JOIN customers_carriers cc ON cc.id = r.customer_carrier_id
        WHERE 1=1
    ";
    $params = [];
    $types  = '';

    if (!empty($filters['search'])) {
        $sql     .= " AND (r.rate_code LIKE ? OR r.rate_description LIKE ? OR cc.name LIKE ?)";
        $like     = '%' . $filters['search'] . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types   .= 'sss';
    }
    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $sql     .= " AND r.status = ?";
        $params[] = $filters['status'];
        $types   .= 's';
    }
    if (!empty($filters['cc_id'])) {
        $sql     .= " AND r.customer_carrier_id = ?";
        $params[] = (int)$filters['cc_id'];
        $types   .= 'i';
    }
    if (!empty($filters['type']) && $filters['type'] !== 'all') {
        $sql     .= " AND r.type = ?";
        $params[] = $filters['type'];
        $types   .= 's';
    }
    if (!empty($filters['expiry_from'])) {
        $sql     .= " AND r.effective_through >= ?";
        $params[] = $filters['expiry_from'];
        $types   .= 's';
    }
    if (!empty($filters['expiry_to'])) {
        $sql     .= " AND r.effective_through <= ?";
        $params[] = $filters['expiry_to'];
        $types   .= 's';
    }

    $sql .= " ORDER BY r.effective_through ASC";

    $stmt = $db->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Returns all customers/carriers for the filter dropdown.
 */
function getCustomersCarriers(): array {
    $db = getDB();
    return $db->query("SELECT id, name, type FROM customers_carriers WHERE is_active = 1 ORDER BY name")
              ->fetch_all(MYSQLI_ASSOC);
}

/**
 * Returns unread dashboard-channel alerts for the banner.
 * "Unread" = logged today or within the last 7 days and channel = 'dashboard'.
 */
function getDashboardAlerts(): array {
    $db = getDB();
    return $db->query("
        SELECT n.id, n.message, n.sent_at,
               r.rate_code, r.status AS rate_status
        FROM   notifications n
        JOIN   rates r ON r.id = n.rate_id
        WHERE  n.channel = 'dashboard'
          AND  n.status  = 'sent'
          AND  n.sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER  BY n.sent_at DESC
        LIMIT  10
    ")->fetch_all(MYSQLI_ASSOC);
}

/**
 * Returns total count of unread (recent) dashboard alerts for the nav badge.
 */
function getDashboardAlertCount(): int {
    $db = getDB();
    return (int) $db->query("
        SELECT COUNT(*) AS cnt
        FROM   notifications
        WHERE  channel = 'dashboard'
          AND  status  = 'sent'
          AND  sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ")->fetch_assoc()['cnt'];
}

/**
 * Maps a status string to a CSS badge class.
 */
function statusBadgeClass(string $status): string {
    return match ($status) {
        'Active'          => 'badge-active',
        'Expiring Soon'   => 'badge-expiring',
        'Critical Expiry' => 'badge-critical',
        'Expired'         => 'badge-expired',
        default           => 'badge-secondary',
    };
}

/**
 * Formats days_to_expiry into a human-readable label.
 */
function formatDaysLabel(int $days): string {
    if ($days < 0)  return abs($days) . ' days ago';
    if ($days === 0) return 'Today';
    return $days . ' days';
}
