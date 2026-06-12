<?php
require_once __DIR__ . '/../includes/functions.php';

$pageTitle = 'Notification History';
$db        = getDB();

$stages = $db->query(
    "SELECT * FROM notification_schedule ORDER BY stage"
)->fetch_all(MYSQLI_ASSOC);

$notifications = $db->query("
    SELECT n.id, n.channel, n.recipient_email, n.message, n.status, n.sent_at,
           r.rate_code, r.rate_description, r.status AS rate_status,
           ns.label AS stage_label, ns.days_before
    FROM   notifications n
    JOIN   rates r              ON r.id  = n.rate_id
    JOIN   notification_schedule ns ON ns.id = n.schedule_id
    ORDER  BY n.sent_at DESC
")->fetch_all(MYSQLI_ASSOC);

// Summary counts
$totalSent    = count(array_filter($notifications, fn($n) => $n['status'] === 'sent'));
$totalPending = count(array_filter($notifications, fn($n) => $n['status'] === 'pending'));
$totalFailed  = count(array_filter($notifications, fn($n) => $n['status'] === 'failed'));

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Notification History</h1>
        <p class="page-subtitle">Log of all expiry reminder notifications sent across all channels</p>
    </div>
    <div class="page-actions">
        <a href="/rate-expiry-dashboard/api/send_notifications.php"
           target="_blank" class="btn btn-primary">&#9993; Run Notifications Now</a>
    </div>
</div>

<!-- Summary counts -->
<div class="cards-grid" style="grid-template-columns: repeat(3,1fr); max-width:600px; margin-bottom:24px">
    <div class="card card-active">
        <div class="card-count"><?= $totalSent ?></div>
        <div class="card-label">Sent</div>
        <div class="card-sublabel">Delivered successfully</div>
    </div>
    <div class="card card-expiring">
        <div class="card-count"><?= $totalPending ?></div>
        <div class="card-label">Pending</div>
        <div class="card-sublabel">Queued for delivery</div>
    </div>
    <div class="card card-expired">
        <div class="card-count"><?= $totalFailed ?></div>
        <div class="card-label">Failed</div>
        <div class="card-sublabel">Delivery errors</div>
    </div>
</div>

<!-- Four-stage schedule reference -->
<div class="section-card" style="margin-bottom:24px">
    <div class="section-title">Four-Stage Reminder Schedule</div>
    <div class="schedule-grid">
        <?php
        $stageClass = ['stage-1','stage-2','stage-3','stage-4'];
        foreach ($stages as $i => $s):
        ?>
        <div class="schedule-item <?= $stageClass[$i] ?>">
            <div class="schedule-stage">Stage <?= $s['stage'] ?></div>
            <div class="schedule-label"><?= htmlspecialchars($s['label']) ?></div>
            <div class="schedule-days"><?= $s['days_before'] ?> days before expiry</div>
            <div class="schedule-desc"><?= htmlspecialchars($s['description']) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Notifications log table -->
<div class="table-wrapper">
    <table class="rates-table">
        <thead>
            <tr>
                <th>Rate Code</th>
                <th>Rate Status</th>
                <th>Stage</th>
                <th>Channel</th>
                <th>Recipient</th>
                <th>Notif. Status</th>
                <th>Sent At</th>
                <th>Message</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($notifications)): ?>
            <tr><td colspan="8" class="empty-state">No notifications have been sent yet.</td></tr>
        <?php else: ?>
            <?php foreach ($notifications as $n): ?>
            <tr>
                <td class="rate-code"><?= htmlspecialchars($n['rate_code']) ?></td>
                <td><span class="badge <?= statusBadgeClass($n['rate_status']) ?>"><?= $n['rate_status'] ?></span></td>
                <td><?= htmlspecialchars($n['stage_label']) ?></td>
                <td><span class="channel-badge channel-<?= $n['channel'] ?>"><?= ucfirst($n['channel']) ?></span></td>
                <td><?= $n['recipient_email']
                        ? htmlspecialchars($n['recipient_email'])
                        : '<span class="text-muted">—</span>' ?></td>
                <td><span class="badge badge-notif-<?= $n['status'] ?>"><?= ucfirst($n['status']) ?></span></td>
                <td><?= $n['sent_at'] ? date('M d, Y H:i', strtotime($n['sent_at'])) : '—' ?></td>
                <td class="message-cell" title="<?= htmlspecialchars($n['message'] ?? '') ?>">
                    <?= htmlspecialchars(mb_strimwidth($n['message'] ?? '', 0, 65, '…')) ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
