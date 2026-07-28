<?php
/**
 * SMTP configuration for password reset emails.
 *
 * Preferred: set these as environment variables.
 * Local override: create smtp_config.local.php next to this file and define the
 * same constants there. Keep real passwords out of shared project files.
 */

if (file_exists(__DIR__ . '/smtp_config.local.php')) {
    require_once __DIR__ . '/smtp_config.local.php';
}

if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', getenv('STOCKPILOT_SMTP_HOST') ?: 'smtp.gmail.com');
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', (int)(getenv('STOCKPILOT_SMTP_PORT') ?: 587));
}
if (!defined('SMTP_USERNAME')) {
    define('SMTP_USERNAME', getenv('STOCKPILOT_SMTP_USERNAME') ?: '');
}
if (!defined('SMTP_PASSWORD')) {
    define('SMTP_PASSWORD', getenv('STOCKPILOT_SMTP_PASSWORD') ?: '');
}
if (!defined('SMTP_FROM_EMAIL')) {
    define('SMTP_FROM_EMAIL', getenv('STOCKPILOT_SMTP_FROM_EMAIL') ?: SMTP_USERNAME);
}
if (!defined('SMTP_FROM_NAME')) {
    define('SMTP_FROM_NAME', getenv('STOCKPILOT_SMTP_FROM_NAME') ?: 'StockPilot');
}
if (!defined('SMTP_SECURE')) {
    define('SMTP_SECURE', getenv('STOCKPILOT_SMTP_SECURE') ?: 'tls'); // tls, ssl, or none
}
?>
