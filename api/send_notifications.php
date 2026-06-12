<?php
// ============================================================
// Notification Engine — All 4 Channels
//
// Channel 1: Email          — all 4 stages
// Channel 2: Dashboard Alert— all 4 stages (logged, shown as banner)
// Channel 3: Teams Alert    — Stage 3 + Stage 4 only
// Channel 4: Escalation     — auto-fires when rate is expired
//                             AND Stage 4 was sent but no action taken
//
// Run: http://localhost/rate-expiry-dashboard/api/send_notifications.php
// ============================================================

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

header('Content-Type: text/plain; charset=utf-8');

$db = getDB();
syncRateStatuses();

// Helper: check if a specific channel notification was already sent
function alreadySentChannel(mysqli $db, int $rateId, int $scheduleId, string $channel): bool {
    $s = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM notifications
         WHERE rate_id = ? AND schedule_id = ? AND channel = ? AND status = 'sent'"
    );
    if (!$s) return false;
    $s->bind_param('iis', $rateId, $scheduleId, $channel);
    $s->execute();
    $cnt = (int)$s->get_result()->fetch_assoc()['cnt'];
    $s->close();
    return $cnt > 0;
}

// Helper: log a notification result — always inserts, returns true on success
function logNotification(mysqli $db, int $rateId, int $scheduleId, string $channel,
                          string $recipient, string $message, bool $success): bool {
    $status = $success ? 'sent' : 'failed';
    // Two queries: one with sent_at, one without — avoids null bind_param quirks
    if ($success) {
        $sentAt = date('Y-m-d H:i:s');
        $s = $db->prepare(
            "INSERT INTO notifications (rate_id,schedule_id,channel,recipient_email,message,status,sent_at)
             VALUES (?,?,?,?,?,?,?)"
        );
        if (!$s) { error_log('[DB-ERROR] prepare failed: ' . $db->error); return false; }
        $s->bind_param('iisssss', $rateId, $scheduleId, $channel, $recipient, $message, $status, $sentAt);
    } else {
        $s = $db->prepare(
            "INSERT INTO notifications (rate_id,schedule_id,channel,recipient_email,message,status)
             VALUES (?,?,?,?,?,?)"
        );
        if (!$s) { error_log('[DB-ERROR] prepare failed: ' . $db->error); return false; }
        $s->bind_param('iissss', $rateId, $scheduleId, $channel, $recipient, $message, $status);
    }
    $ok = $s->execute();
    if (!$ok) error_log('[DB-ERROR] insert failed: ' . $s->error);
    $s->close();
    return $ok;
}

// ── Load data ────────────────────────────────────────────────
$rates = $db->query("
    SELECT r.id, r.rate_code, r.rate_description,
           r.effective_through, r.status, r.type,
           cc.name  AS cc_name,
           cc.email AS cc_email,
           DATEDIFF(r.effective_through, CURDATE()) AS days_left
    FROM   rates r
    JOIN   customers_carriers cc ON cc.id = r.customer_carrier_id
    WHERE  DATEDIFF(r.effective_through, CURDATE()) <= 120
")->fetch_all(MYSQLI_ASSOC);

$stages = $db->query("SELECT * FROM notification_schedule ORDER BY stage")->fetch_all(MYSQLI_ASSOC);
$stage4 = end($stages); // Final Reminder stage

$testRecipient = (defined('MAIL_TEST_RECIPIENT') && MAIL_TEST_RECIPIENT !== '')
    ? MAIL_TEST_RECIPIENT : null;

// ── Report header ─────────────────────────────────────────────
echo "=== Rate Expiry Notification Run — " . date('Y-m-d H:i:s') . " ===\n";
echo "Rates in window : " . count($rates) . "\n";
echo "MAIL_ENABLED    : " . (MAIL_ENABLED ? 'YES (real emails)' : 'NO (log-only)') . "\n";
echo "TEAMS_ENABLED   : " . ((defined('TEAMS_ENABLED') && TEAMS_ENABLED) ? 'YES' : 'NO (simulated)') . "\n";
echo "Test recipient  : " . ($testRecipient ?? '(using DB emails)') . "\n\n";

$counts  = ['email' => 0, 'dashboard' => 0, 'teams' => 0, 'escalation' => 0];
$skipped = 0;
$report  = [];

// ── CHANNELS 1, 2, 3 — Stage-based notifications ─────────────
foreach ($rates as $rate) {
    $daysLeft  = (int)$rate['days_left'];
    $recipient = $testRecipient ?? ($rate['cc_email'] ?: MAIL_FROM);
    $msg       = "Rate {$rate['rate_code']} expires {$rate['effective_through']} ({$daysLeft} days). ";

    foreach ($stages as $stage) {
        if ($daysLeft > $stage['days_before']) continue;

        // ── Channel 1: Email ──────────────────────────────────
        if (!alreadySentChannel($db, $rate['id'], $stage['id'], 'email')) {
            $subject = "[Rate Expiry] {$rate['rate_code']} — {$stage['label']}";
            $body    = buildNotificationEmail($rate, $stage, $daysLeft);
            $ok      = sendEmail($recipient, $subject, $body);
            logNotification($db, $rate['id'], $stage['id'], 'email', $recipient,
                            $msg . "Stage: {$stage['label']}.", $ok);
            if ($ok) $counts['email']++;
            $report[] = ($ok ? '  [EMAIL ✓]' : '  [EMAIL ✗]')
                      . "  {$rate['rate_code']} — {$stage['label']} → {$recipient}";
        } else {
            $skipped++;
            $report[] = "  [SKIP]     {$rate['rate_code']} — {$stage['label']} (email already sent)";
        }

        // ── Channel 2: Dashboard Alert ────────────────────────
        if (!alreadySentChannel($db, $rate['id'], $stage['id'], 'dashboard')) {
            logNotification($db, $rate['id'], $stage['id'], 'dashboard', '',
                            $msg . "Dashboard alert.", true);
            $counts['dashboard']++;
            $report[] = "  [DASH  ✓]  {$rate['rate_code']} — {$stage['label']} (dashboard logged)";
        }

        // ── Channel 3: Teams Alert (Stage 3 & 4 only) ─────────
        if ($stage['stage'] >= 3
            && !alreadySentChannel($db, $rate['id'], $stage['id'], 'teams')) {
            $ok = sendTeamsAlert($rate, $stage, $daysLeft);
            logNotification($db, $rate['id'], $stage['id'], 'teams', '',
                            $msg . "Teams alert.", $ok);
            $counts['teams']++;
            $report[] = ($ok ? '  [TEAMS ✓]' : '  [TEAMS ✗]')
                      . "  {$rate['rate_code']} — {$stage['label']}";
        }
    }
}

// ── CHANNEL 4: Supervisor Escalation ─────────────────────────
// Fires when: rate is expired AND Stage 4 email was sent AND no escalation yet sent
$expiredRates = $db->query("
    SELECT r.id, r.rate_code, r.rate_description,
           r.effective_through, r.status, r.type,
           ABS(DATEDIFF(r.effective_through, CURDATE())) AS days_expired,
           cc.name AS cc_name, cc.email AS cc_email
    FROM   rates r
    JOIN   customers_carriers cc ON cc.id = r.customer_carrier_id
    WHERE  r.status = 'Expired'
")->fetch_all(MYSQLI_ASSOC);

$supervisorEmail = defined('SUPERVISOR_EMAIL') ? SUPERVISOR_EMAIL : MAIL_FROM;
// Use test recipient for supervisor too if configured
if ($testRecipient) $supervisorEmail = $testRecipient;

foreach ($expiredRates as $rate) {
    $stage4Id    = (int)$stage4['id'];
    $daysExpired = (int)$rate['days_expired'];

    // Only escalate if Stage 4 was already attempted (sent or failed) for this rate
    $chk = $db->prepare("SELECT COUNT(*) AS cnt FROM notifications WHERE rate_id=? AND schedule_id=? AND channel='email'");
    $chk->bind_param('ii', $rate['id'], $stage4Id);
    $chk->execute();
    $stage4Attempted = (int)$chk->get_result()->fetch_assoc()['cnt'];
    $chk->close();
    if (!$stage4Attempted) continue;

    // Skip if escalation already sent
    $escalated = $db->prepare("
        SELECT COUNT(*) AS cnt FROM notifications
        WHERE rate_id = ? AND channel = 'escalation' AND status = 'sent'
    ");
    $escalated->bind_param('i', $rate['id']);
    $escalated->execute();
    if ((int)$escalated->get_result()->fetch_assoc()['cnt'] > 0) {
        $skipped++;
        $report[] = "  [SKIP]     {$rate['rate_code']} — Escalation (already sent)";
        continue;
    }

    $subject = "[ESCALATION] Rate {$rate['rate_code']} expired — No Action Taken";
    $body    = buildEscalationEmail($rate, $daysExpired);
    $ok      = sendEmail($supervisorEmail, $subject, $body);

    // Log escalation (schedule_id = stage4, channel = escalation)
    logNotification($db, $rate['id'], $stage4Id, 'escalation', $supervisorEmail,
                    "AUTO-ESCALATION: {$rate['rate_code']} expired {$daysExpired} days ago. No action.", $ok);
    $counts['escalation']++;
    $report[] = ($ok ? '  [ESC   ✓]' : '  [ESC   ✗]')
              . "  {$rate['rate_code']} — Supervisor escalation → {$supervisorEmail}";
}

// ── Summary ───────────────────────────────────────────────────
echo "--- Results ---\n";
echo "Email sent      : {$counts['email']}\n";
echo "Dashboard logged: {$counts['dashboard']}\n";
echo "Teams sent      : {$counts['teams']}\n";
echo "Escalations     : {$counts['escalation']}\n";
echo "Skipped (dup)   : {$skipped}\n\n";

echo "--- Detail ---\n";
echo empty($report)
    ? "  Nothing to send — all due notifications have already been delivered.\n"
    : implode("\n", $report) . "\n";

echo "\n--- Done ---\n";
if (!MAIL_ENABLED) {
    echo "\nNOTE: MAIL_ENABLED = false — check C:\\xampp\\apache\\logs\\php_error_log\n";
}
if (defined('TEAMS_ENABLED') && !TEAMS_ENABLED) {
    echo "NOTE: TEAMS_ENABLED = false — Teams alerts were logged but not sent to webhook.\n";
    echo "      To simulate: go to https://webhook.site, copy your URL, paste in config/mail.php\n";
}
