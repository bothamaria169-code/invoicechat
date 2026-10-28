<?php
// InvoiceChat secrets — copy to config/secrets.php and fill in.
// config/secrets.php is in .gitignore: NEVER commit it. Upload it once to the
// server with cPanel File Manager; git deploys leave it alone.
return [
    // Live database (cPanel → MySQL Databases)
    'db_host' => 'localhost',
    'db_name' => 'swiftfusionco_invoicechat',
    'db_user' => 'CHANGE_ME',
    'db_pass' => 'CHANGE_ME',

    // Local XAMPP database (used automatically on localhost)
    'local_db_name' => 'swiftfusionco_invoicechat',
    'local_db_user' => 'root',
    'local_db_pass' => '',

    // Public address of the live app, no trailing slash
    'app_url' => 'https://swiftfusion.co.za/invoicechat',

    // Admin account: created on first page load if it doesn't exist yet.
    // Every sign-up is also copied to this address.
    'admin_email'         => 'admin@swiftfusion.co.za',
    'admin_seed_password' => 'CHANGE_ME',

    // Mailbox that sends sign-up, reset and invoice emails (cPanel → Email Accounts).
    // Leave smtp_pass blank to use the server's PHP mail() instead.
    'smtp_host' => 'mail.swiftfusion.co.za',
    'smtp_port' => 465,
    'smtp_user' => 'admin@swiftfusion.co.za',
    'smtp_pass' => '',

    // Long random string: signs PDF download links. Changing it breaks old links.
    'app_secret' => 'make-a-long-random-string',
    // Random string for the URL cron (scripts/cron.php?key=...)
    'cron_key'   => 'make-a-random-string',

    // WhatsApp Cloud API (Meta) — see config/config.php for where to find these
    'whatsapp_phone_number_id' => '',
    'whatsapp_token'           => '',
    'whatsapp_verify_token'    => 'invoicechat_webhook_2026',
];
