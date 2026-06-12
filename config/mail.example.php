<?php
// ============================================================
// Mail & Notification Configuration — TEMPLATE
// Copy this file to config/mail.php and fill in your values.
// NEVER commit config/mail.php (it contains real credentials).
// ============================================================

define('MAIL_ENABLED',   true);   // false = log-only, no real emails sent

define('MAIL_HOST',      'smtp.gmail.com');
define('MAIL_PORT',      587);
define('MAIL_ENCRYPTION','tls');
define('MAIL_USERNAME',  'your_gmail@gmail.com');  // your Gmail address
define('MAIL_PASSWORD',  'xxxx xxxx xxxx xxxx');   // 16-char App Password from myaccount.google.com/apppasswords

define('MAIL_FROM',      'your_gmail@gmail.com');
define('MAIL_FROM_NAME', 'Rate Expiry Dashboard');

// Override all outgoing emails to this address (useful for testing).
// Set to '' to use actual customer/carrier emails from the database.
define('MAIL_TEST_RECIPIENT', '');

// Email address that receives supervisor escalation alerts
define('SUPERVISOR_EMAIL', 'supervisor@example.com');

// Microsoft Teams simulation via webhook.site
// 1. Go to https://webhook.site and copy your unique URL
// 2. Paste it below and set TEAMS_ENABLED = true
define('TEAMS_ENABLED',     false);
define('TEAMS_WEBHOOK_URL', 'https://webhook.site/your-unique-id');
