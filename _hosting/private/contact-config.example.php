<?php

declare(strict_types=1);

// Save as contact-config.php in patsilias-private, outside the public document root.
// Keep the SMTP password and the rate-limit secret out of Git and web-accessible files.
return [
    'smtp_host' => 'smtp.example.com',
    'smtp_port' => 587,
    'smtp_encryption' => 'starttls', // Typically 'starttls' on 587 or 'smtps' on 465.
    'smtp_username' => '',
    'smtp_password' => '',
    'from_email' => '',
    'from_name' => 'Patsilias Events',
    'to_email' => '',
    // Generate with: php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
    'rate_limit_secret' => '',
];
