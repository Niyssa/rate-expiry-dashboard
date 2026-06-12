<?php
// ============================================================
// Mailer — wraps PHPMailer with a MAIL_ENABLED safety switch.
//
// HOW TO INSTALL PHPMailer (one-time setup):
//   1. Download: https://github.com/PHPMailer/PHPMailer/releases
//      (grab the latest .zip)
//   2. From the zip, copy only these 3 files into includes/phpmailer/:
//        src/PHPMailer.php
//        src/SMTP.php
//        src/Exception.php
//   3. Set MAIL_ENABLED = true in config/mail.php
//
// While MAIL_ENABLED = false, sendEmail() writes to the PHP error log
// so you can verify the logic without sending anything real.
// ============================================================

require_once __DIR__ . '/../config/mail.php';

function sendEmail(string $to, string $subject, string $htmlBody): bool
{
    // Redirect to test recipient if configured (demo / presentation mode)
    $actualTo = (defined('MAIL_TEST_RECIPIENT') && MAIL_TEST_RECIPIENT !== '')
        ? MAIL_TEST_RECIPIENT
        : $to;

    if (!MAIL_ENABLED) {
        // Log-only mode — check C:\xampp\apache\logs\php_error_log
        error_log("[MAIL-DISABLED] To: $actualTo | Subject: $subject");
        return true; // pretend success so the notification gets logged as 'sent'
    }

    $phpmailerDir = __DIR__ . '/phpmailer/';
    if (!file_exists($phpmailerDir . 'PHPMailer.php')) {
        error_log("[MAIL-ERROR] PHPMailer files not found in includes/phpmailer/");
        return false;
    }

    require_once $phpmailerDir . 'Exception.php';
    require_once $phpmailerDir . 'PHPMailer.php';
    require_once $phpmailerDir . 'SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_ENCRYPTION;
        $mail->Port       = MAIL_PORT;

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($actualTo);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        $mail->send();
        return true;
    } catch (\Exception $e) {
        error_log('[MAIL-ERROR] ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Sends a Microsoft Teams alert via an incoming webhook URL.
 * Uses webhook.site for demo/testing — no real Teams needed.
 * Returns true on success (or when Teams is disabled).
 */
function sendTeamsAlert(array $rate, array $stage, int $daysLeft): bool
{
    if (!defined('TEAMS_ENABLED') || !TEAMS_ENABLED) {
        error_log("[TEAMS-DISABLED] Would send Teams alert for {$rate['rate_code']} — {$stage['label']}");
        return true;
    }

    $urgency  = $daysLeft < 0 ? 'EXPIRED' : ($daysLeft <= 7 ? 'URGENT' : 'ACTION REQUIRED');
    $color    = $daysLeft < 0 ? 'dc2626' : ($daysLeft <= 7 ? 'ea580c' : 'ca8a04');
    $dayLabel = $daysLeft < 0
        ? abs($daysLeft) . ' days past expiry'
        : ($daysLeft === 0 ? 'Expires TODAY' : "$daysLeft days remaining");

    // Teams MessageCard format (compatible with webhook.site preview)
    $payload = json_encode([
        '@type'       => 'MessageCard',
        '@context'    => 'http://schema.org/extensions',
        'themeColor'  => $color,
        'summary'     => "Rate Expiry Alert — {$rate['rate_code']}",
        'title'       => "⚠️ {$urgency}: Rate {$rate['rate_code']} — {$stage['label']}",
        'sections'    => [[
            'activityTitle'    => "{$rate['rate_code']} — {$rate['rate_description']}",
            'activitySubtitle' => "{$stage['label']} | {$rate['cc_name']} ({$rate['type']})",
            'facts'            => [
                ['name' => 'Expiry Date',    'value' => date('F j, Y', strtotime($rate['effective_through']))],
                ['name' => 'Days Remaining', 'value' => $dayLabel],
                ['name' => 'Rate Status',    'value' => $rate['status']],
                ['name' => 'Stage',          'value' => "Stage {$stage['stage']} of 4"],
            ],
        ]],
        'potentialAction' => [[
            '@type' => 'OpenUri',
            'name'  => 'View Dashboard',
            'targets' => [['os' => 'default', 'uri' => 'http://localhost/rate-expiry-dashboard/']],
        ]],
    ]);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
            'content' => $payload,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);

    $result = @file_get_contents(TEAMS_WEBHOOK_URL, false, $ctx);
    if ($result === false) {
        error_log("[TEAMS-ERROR] Failed to POST to webhook for {$rate['rate_code']}");
        return false;
    }
    return true;
}

/**
 * Builds the HTML email body for a rate expiry notification.
 */
function buildNotificationEmail(array $rate, array $stage, int $daysLeft): string
{
    $urgencyLabel = match (true) {
        $daysLeft < 0  => 'EXPIRED',
        $daysLeft <= 7 => 'URGENT',
        $daysLeft <= 30 => 'ACTION REQUIRED',
        default         => 'REMINDER',
    };

    $headerColor = match (true) {
        $daysLeft < 0   => '#dc2626',
        $daysLeft <= 7  => '#ea580c',
        $daysLeft <= 30 => '#ca8a04',
        default          => '#2563eb',
    };

    $dayLabel = $daysLeft < 0
        ? abs($daysLeft) . ' days past expiry (EXPIRED)'
        : ($daysLeft === 0 ? 'Expires TODAY' : "$daysLeft days remaining");

    $expiryDate = date('F j, Y', strtotime($rate['effective_through']));

    return "
<!DOCTYPE html>
<html>
<body style='margin:0;padding:0;font-family:Arial,sans-serif;background:#f8fafc'>
<table width='100%' cellpadding='0' cellspacing='0'>
  <tr><td align='center' style='padding:32px 16px'>
    <table width='600' cellpadding='0' cellspacing='0' style='max-width:600px;width:100%'>

      <!-- Header -->
      <tr>
        <td style='background:{$headerColor};padding:24px 28px;border-radius:8px 8px 0 0'>
          <p style='margin:0;color:#fff;font-size:12px;letter-spacing:.08em;text-transform:uppercase;font-weight:700'>{$urgencyLabel} &mdash; {$stage['label']}</p>
          <h1 style='margin:6px 0 0;color:#fff;font-size:22px;font-weight:700'>Rate Expiry Notification</h1>
        </td>
      </tr>

      <!-- Body -->
      <tr>
        <td style='background:#fff;padding:28px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px'>
          <p style='margin:0 0 20px;color:#1e293b'>This is an automated reminder about the following rate card:</p>

          <table width='100%' cellpadding='0' cellspacing='0' style='border:1px solid #e2e8f0;border-radius:6px;overflow:hidden;margin-bottom:24px'>
            <tr><td style='padding:10px 14px;background:#f1f5f9;font-weight:700;font-size:13px;color:#64748b;width:170px'>Rate Code</td>
                <td style='padding:10px 14px;font-size:13px;font-family:monospace;color:#2563eb;font-weight:700'>{$rate['rate_code']}</td></tr>
            <tr><td style='padding:10px 14px;background:#f1f5f9;font-weight:700;font-size:13px;color:#64748b'>Description</td>
                <td style='padding:10px 14px;font-size:13px'>{$rate['rate_description']}</td></tr>
            <tr><td style='padding:10px 14px;background:#f1f5f9;font-weight:700;font-size:13px;color:#64748b'>Customer/Carrier</td>
                <td style='padding:10px 14px;font-size:13px'>{$rate['cc_name']} ({$rate['type']})</td></tr>
            <tr><td style='padding:10px 14px;background:#f1f5f9;font-weight:700;font-size:13px;color:#64748b'>Expiry Date</td>
                <td style='padding:10px 14px;font-size:13px;font-weight:600'>{$expiryDate}</td></tr>
            <tr><td style='padding:10px 14px;background:#f1f5f9;font-weight:700;font-size:13px;color:#64748b'>Status</td>
                <td style='padding:10px 14px;font-size:13px;font-weight:700;color:{$headerColor}'>{$dayLabel}</td></tr>
          </table>

          <p style='margin:0 0 8px;color:#1e293b'>Please log in to the Rate Expiry Dashboard to take appropriate action (renew, extend, or escalate).</p>

          <p style='margin:24px 0 0;color:#94a3b8;font-size:11px;border-top:1px solid #e2e8f0;padding-top:16px'>
            This is an automated notification sent by the Rate Expiry Dashboard.<br>
            Stage {$stage['stage']} of 4 &mdash; triggered {$stage['days_before']} days before expiry.
          </p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>";
}

/**
 * Builds the supervisor escalation email when a rate expires with no action taken.
 */
function buildEscalationEmail(array $rate, int $daysExpired): string
{
    $expiryDate = date('F j, Y', strtotime($rate['effective_through']));
    return "
<!DOCTYPE html>
<html>
<body style='margin:0;padding:0;font-family:Arial,sans-serif;background:#f8fafc'>
<table width='100%' cellpadding='0' cellspacing='0'>
  <tr><td align='center' style='padding:32px 16px'>
    <table width='600' cellpadding='0' cellspacing='0' style='max-width:600px;width:100%'>
      <tr>
        <td style='background:#7c3aed;padding:24px 28px;border-radius:8px 8px 0 0'>
          <p style='margin:0;color:#fff;font-size:12px;letter-spacing:.08em;text-transform:uppercase;font-weight:700'>AUTO-ESCALATION &mdash; SUPERVISOR ACTION REQUIRED</p>
          <h1 style='margin:6px 0 0;color:#fff;font-size:22px;font-weight:700'>Rate Expired Without Action</h1>
        </td>
      </tr>
      <tr>
        <td style='background:#fff;padding:28px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px'>
          <p style='margin:0 0 16px;color:#1e293b;font-weight:600'>This rate has expired and no renewal or acknowledgement has been recorded after the Final Reminder was sent.</p>
          <table width='100%' cellpadding='0' cellspacing='0' style='border:1px solid #e2e8f0;border-radius:6px;overflow:hidden;margin-bottom:24px'>
            <tr><td style='padding:10px 14px;background:#f1f5f9;font-weight:700;font-size:13px;color:#64748b;width:170px'>Rate Code</td>
                <td style='padding:10px 14px;font-size:13px;font-family:monospace;color:#dc2626;font-weight:700'>{$rate['rate_code']}</td></tr>
            <tr><td style='padding:10px 14px;background:#f1f5f9;font-weight:700;font-size:13px;color:#64748b'>Description</td>
                <td style='padding:10px 14px;font-size:13px'>{$rate['rate_description']}</td></tr>
            <tr><td style='padding:10px 14px;background:#f1f5f9;font-weight:700;font-size:13px;color:#64748b'>Customer/Carrier</td>
                <td style='padding:10px 14px;font-size:13px'>{$rate['cc_name']} ({$rate['type']})</td></tr>
            <tr><td style='padding:10px 14px;background:#f1f5f9;font-weight:700;font-size:13px;color:#64748b'>Expired On</td>
                <td style='padding:10px 14px;font-size:13px;font-weight:700;color:#dc2626'>{$expiryDate} ({$daysExpired} days ago)</td></tr>
          </table>
          <p style='background:#fff1f2;border:1px solid #fecdd3;border-radius:6px;padding:14px;color:#991b1b;font-weight:600;margin:0'>
            ⚠ Immediate supervisor action is required. Please review, renew, or close out this rate.
          </p>
          <p style='margin:24px 0 0;color:#94a3b8;font-size:11px;border-top:1px solid #e2e8f0;padding-top:16px'>
            AUTO-ESCALATION — triggered by Rate Expiry Dashboard after Final Reminder received no response.
          </p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>";
}
