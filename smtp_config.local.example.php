<?php
// Copy this file to smtp_config.local.php and fill in your SMTP details.
// For Gmail, use an App Password, not your normal account password.

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@gmail.com');
define('SMTP_PASSWORD', 'your-app-password');
define('SMTP_FROM_EMAIL', 'your-email@gmail.com');
define('SMTP_FROM_NAME', 'StockPilot');
define('SMTP_SECURE', 'tls');
?>
