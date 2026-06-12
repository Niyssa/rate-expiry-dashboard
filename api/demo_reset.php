<?php
// ============================================================
// Demo Reset — targeted or full
//
// Preview (no changes):
//   http://localhost/rate-expiry-dashboard/api/demo_reset.php
//
// Reset ONE rate (triggers fresh email on next run):
//   http://localhost/rate-expiry-dashboard/api/demo_reset.php?rate_code=XXS-JHB-BKK
//
// Reset ALL (clears entire notification history):
//   http://localhost/rate-expiry-dashboard/api/demo_reset.php?all=1
// ============================================================

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/mail.php';

header('Content-Type: text/plain; charset=utf-8');

$db = getDB();
syncRateStatuses();

$targetCode = trim($_GET['rate_code'] ?? '');
$resetAll   = isset($_GET['all']) && $_GET['all'] === '1';

echo "=== Demo Reset — " . date('Y-m-d H:i:s') . " ===\n\n";

// ── Full reset ────────────────────────────────────────────────
if ($resetAll) {
    $before = (int)$db->query("SELECT COUNT(*) AS c FROM notifications")->fetch_assoc()['c'];
    $db->query("DELETE FROM rate_acknowledgements");
    $db->query("DELETE FROM notifications");
    echo "ALL notifications cleared ($before rows).\n";
    echo "All acknowledgements cleared.\n\n";
    echo "Next run will send ALL due notifications.\n";
    echo "Run: http://localhost/rate-expiry-dashboard/api/send_notifications.php\n";
    exit;
}

// ── Single-rate reset ─────────────────────────────────────────
if ($targetCode !== '') {
    $s = $db->prepare("SELECT id, rate_code, rate_description, effective_through, status,
                              DATEDIFF(effective_through, CURDATE()) AS days_left
                       FROM rates WHERE rate_code = ?");
    $s->bind_param('s', $targetCode);
    $s->execute();
    $rate = $s->get_result()->fetch_assoc();
    $s->close();

    if (!$rate) {
        echo "ERROR: Rate code '$targetCode' not found.\n";
        exit(1);
    }

    $before = (int)$db->query(
        "SELECT COUNT(*) AS c FROM notifications WHERE rate_id = {$rate['id']}"
    )->fetch_assoc()['c'];

    $db->query("DELETE FROM rate_acknowledgements WHERE rate_id = {$rate['id']}");
    $db->query("DELETE FROM notifications WHERE rate_id = {$rate['id']}");

    echo "Reset: {$rate['rate_code']} — {$rate['rate_description']}\n";
    echo "  Status        : {$rate['status']}\n";
    echo "  Effective thru: {$rate['effective_through']}\n";
    echo "  Days left     : {$rate['days_left']}\n";
    echo "  Rows cleared  : $before\n\n";

    // Show what will fire
    $stages = $db->query("SELECT * FROM notification_schedule ORDER BY stage")->fetch_all(MYSQLI_ASSOC);
    echo "Notifications that will fire on next run:\n";
    $daysLeft = (int)$rate['days_left'];
    foreach ($stages as $st) {
        if ($daysLeft <= $st['days_before']) {
            echo "  ✓ {$st['label']} (≤{$st['days_before']} days)\n";
        }
    }
    if ($daysLeft < 0) {
        echo "  ✓ Escalation (rate is expired)\n";
    }

    echo "\nRun: http://localhost/rate-expiry-dashboard/api/send_notifications.php\n";
    exit;
}

// ── Preview only (no changes) ─────────────────────────────────
$rates = $db->query("
    SELECT r.id, r.rate_code, r.rate_description, r.effective_through, r.status,
           DATEDIFF(r.effective_through, CURDATE()) AS days_left,
           cc.email AS cc_email
    FROM   rates r
    JOIN   customers_carriers cc ON cc.id = r.customer_carrier_id
    WHERE  DATEDIFF(r.effective_through, CURDATE()) <= 120
    ORDER  BY days_left ASC
")->fetch_all(MYSQLI_ASSOC);

$stages = $db->query("SELECT * FROM notification_schedule ORDER BY stage")->fetch_all(MYSQLI_ASSOC);

echo "--- Rates in notification window (≤120 days) ---\n";
foreach ($rates as $r) {
    $daysLeft  = (int)$r['days_left'];
    $sent      = (int)$db->query(
        "SELECT COUNT(*) AS c FROM notifications WHERE rate_id = {$r['id']}"
    )->fetch_assoc()['c'];
    $label     = $daysLeft < 0 ? abs($daysLeft) . ' days EXPIRED' : "$daysLeft days left";
    $sentLabel = $sent > 0 ? " [$sent notifs sent]" : " [no history]";

    echo "\n  {$r['rate_code']} ({$r['status']}) — $label$sentLabel\n";
    foreach ($stages as $st) {
        if ($daysLeft <= $st['days_before']) {
            $alreadySent = (int)$db->query(
                "SELECT COUNT(*) AS c FROM notifications
                 WHERE rate_id = {$r['id']} AND schedule_id = {$st['id']} AND channel = 'email' AND status = 'sent'"
            )->fetch_assoc()['c'];
            $mark = $alreadySent ? '  [DONE]' : '  [PENDING]';
            echo "    $mark {$st['label']}\n";
        }
    }
}

echo "\n--- To demo a live email ---\n";
echo "1. Pick a rate code from the list above\n";
echo "2. Visit: http://localhost/rate-expiry-dashboard/api/demo_reset.php?rate_code=<CODE>\n";
echo "3. Then run: http://localhost/rate-expiry-dashboard/api/send_notifications.php\n\n";
echo "MAIL_ENABLED : " . (MAIL_ENABLED ? 'YES (real emails)' : 'NO (log-only)') . "\n";
echo "Recipient    : " . (MAIL_TEST_RECIPIENT ?: '(using DB emails)') . "\n";
