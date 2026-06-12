<?php
// ============================================================
// Demo Fire — fires ONE Final Reminder email for a chosen rate.
//
// Preview available rates:
//   http://localhost/rate-expiry-dashboard/api/demo_fire.php
//
// Fire for a specific rate (sends 1 email to your inbox):
//   http://localhost/rate-expiry-dashboard/api/demo_fire.php?rate_code=XXS-JHB-BKK
// ============================================================

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../config/mail.php';

header('Content-Type: text/plain; charset=utf-8');

$db = getDB();
syncRateStatuses();

$targetCode = trim($_GET['rate_code'] ?? '');

// ── List available rates ──────────────────────────────────────
if ($targetCode === '') {
    echo "=== Demo Fire — " . date('Y-m-d H:i:s') . " ===\n\n";
    echo "Pick a rate to demo a live Final Reminder email:\n\n";
    $rows = $db->query("SELECT rate_code, rate_description, effective_through, status FROM rates ORDER BY effective_through")->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $row) {
        echo "  {$row['rate_code']} — {$row['status']} (expires {$row['effective_through']})\n";
    }
    echo "\nUsage:\n  http://localhost/rate-expiry-dashboard/api/demo_fire.php?rate_code=<CODE>\n";
    exit;
}

// ── Load the chosen rate ──────────────────────────────────────
$s = $db->prepare(
    "SELECT r.*, cc.name AS cc_name, cc.email AS cc_email
     FROM rates r
     JOIN customers_carriers cc ON cc.id = r.customer_carrier_id
     WHERE r.rate_code = ?"
);
$s->bind_param('s', $targetCode);
$s->execute();
$rate = $s->get_result()->fetch_assoc();
$s->close();

if (!$rate) {
    echo "ERROR: Rate code '$targetCode' not found.\n";
    exit(1);
}

$stages       = $db->query("SELECT * FROM notification_schedule ORDER BY stage")->fetch_all(MYSQLI_ASSOC);
$stage4       = end($stages);
$originalDate = $rate['effective_through'];
$newDate      = date('Y-m-d', strtotime('+5 days'));
$daysLeft     = 5;

echo "=== Demo Fire — Final Reminder for {$rate['rate_code']} ===\n";
echo date('Y-m-d H:i:s') . "\n\n";

// ── Set effective_through to 5 days from today ────────────────
$upd = $db->prepare("UPDATE rates SET effective_through = ? WHERE id = ?");
$upd->bind_param('si', $newDate, $rate['id']);
$upd->execute();
$upd->close();
echo "  Rate date      : $originalDate → $newDate\n";

// ── Clear only this rate's notification history ───────────────
$db->query("DELETE FROM rate_acknowledgements WHERE rate_id = {$rate['id']}");
$del = $db->prepare("DELETE FROM notifications WHERE rate_id = ?");
$del->bind_param('i', $rate['id']);
$del->execute();
$del->close();
echo "  Old notifs     : cleared\n";

// ── Pre-fill Stages 1–3 so the engine only fires Stage 4 ─────
$now = date('Y-m-d H:i:s');
foreach (array_slice($stages, 0, 3) as $st) {
    foreach (['email', 'dashboard'] as $ch) {
        $recp = ($ch === 'email') ? $rate['cc_email'] : '';
        $msg  = "Rate {$rate['rate_code']} — {$st['label']} (demo pre-fill).";
        $stat = 'sent';
        $ins  = $db->prepare(
            "INSERT INTO notifications (rate_id,schedule_id,channel,recipient_email,message,status,sent_at)
             VALUES (?,?,?,?,?,?,?)"
        );
        $ins->bind_param('iisssss', $rate['id'], $st['id'], $ch, $recp, $msg, $stat, $now);
        $ins->execute();
        $ins->close();
    }
}
echo "  Stages 1–3     : pre-filled as sent (no emails)\n\n";

// ── Channel 1: Email — Final Reminder ────────────────────────
$recipient = (defined('MAIL_TEST_RECIPIENT') && MAIL_TEST_RECIPIENT !== '')
    ? MAIL_TEST_RECIPIENT
    : ($rate['cc_email'] ?: MAIL_FROM);

$subject  = "[Rate Expiry] {$rate['rate_code']} — {$stage4['label']}";
$rateForEmail = array_merge($rate, ['effective_through' => $newDate]);
$body     = buildNotificationEmail($rateForEmail, $stage4, $daysLeft);
$emailOk  = sendEmail($recipient, $subject, $body);

$sentAt   = date('Y-m-d H:i:s');
$emailMsg = "Rate {$rate['rate_code']} expires $newDate ($daysLeft days). Stage: {$stage4['label']}.";
$emailSt  = $emailOk ? 'sent' : 'failed';
$ins1     = $db->prepare(
    "INSERT INTO notifications (rate_id,schedule_id,channel,recipient_email,message,status,sent_at)
     VALUES (?,?,?,?,?,?,?)"
);
$emailCh = 'email';
$ins1->bind_param('iisssss', $rate['id'], $stage4['id'], $emailCh, $recipient, $emailMsg, $emailSt, $sentAt);
$ins1->execute();
$ins1->close();

echo ($emailOk ? "[EMAIL ✓]" : "[EMAIL ✗]") . "  Final Reminder → $recipient\n";

// ── Channel 2: Dashboard ──────────────────────────────────────
$dashCh  = 'dashboard';
$dashMsg = "Rate {$rate['rate_code']} expires $newDate ($daysLeft days). Dashboard alert.";
$dashSt  = 'sent';
$recp2   = '';
$ins2    = $db->prepare(
    "INSERT INTO notifications (rate_id,schedule_id,channel,recipient_email,message,status,sent_at)
     VALUES (?,?,?,?,?,?,?)"
);
$ins2->bind_param('iisssss', $rate['id'], $stage4['id'], $dashCh, $recp2, $dashMsg, $dashSt, $sentAt);
$ins2->execute();
$ins2->close();
echo "[DASH  ✓]  Dashboard alert logged\n";

// ── Channel 3: Teams ──────────────────────────────────────────
$teamsCh = 'teams';
$teamsOk = sendTeamsAlert($rateForEmail, $stage4, $daysLeft);
$teamsSt = $teamsOk ? 'sent' : 'failed';
$teamsMsg = "Rate {$rate['rate_code']} — Teams alert.";
$recp3   = '';
$ins3    = $db->prepare(
    "INSERT INTO notifications (rate_id,schedule_id,channel,recipient_email,message,status,sent_at)
     VALUES (?,?,?,?,?,?,?)"
);
$ins3->bind_param('iisssss', $rate['id'], $stage4['id'], $teamsCh, $recp3, $teamsMsg, $teamsSt, $sentAt);
$ins3->execute();
$ins3->close();

$teamsLabel = (defined('TEAMS_ENABLED') && TEAMS_ENABLED)
    ? ($teamsOk ? "[TEAMS ✓]" : "[TEAMS ✗]")
    : "[TEAMS -]  (TEAMS_ENABLED=false — enable in config/mail.php to simulate)";
echo "$teamsLabel  Teams alert\n";

echo "\n--- Done ---\n";
echo "1 Final Reminder email sent to: $recipient\n";
echo "Check your inbox, then view the log:\n";
echo "   http://localhost/rate-expiry-dashboard/pages/notifications.php\n\n";
echo "Rate date changed: $originalDate → $newDate\n";
echo "To revert after demo — run this SQL:\n";
echo "   UPDATE rates SET effective_through='$originalDate' WHERE rate_code='{$rate['rate_code']}';\n";
