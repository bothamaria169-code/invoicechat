<?php
declare(strict_types=1);

// ── Secrets ──────────────────────────────────────────────────────
// Passwords and keys live in config/secrets.php (not in git). Copy
// config/secrets.example.php to start one.
$secretsFile = __DIR__ . '/secrets.php';
$GLOBALS['IC_SECRETS'] = is_file($secretsFile) ? (require $secretsFile) : [];

function secret(string $key, mixed $default = ''): mixed
{
    return $GLOBALS['IC_SECRETS'][$key] ?? $default;
}

// ── App ──────────────────────────────────────────────────────────
define('APP_NAME', 'InvoiceChat');
define('APP_TAGLINE', 'Invoices & quotes, as easy as sending a message');
define('BASE_DIR', dirname(__DIR__));

$host = $_SERVER['HTTP_HOST'] ?? '';
define('IS_LOCAL', $host === ''
    ? PHP_OS_FAMILY === 'Windows'
    : (bool) preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$|\.(localhost|test)(:\d+)?$/', $host));

// Set APP_URL in the environment when the site is behind a proxy or uses a
// custom domain. Otherwise derive it from the current host and install path,
// falling back to secrets.php's app_url for cron/CLI runs.
$appUrl = (string) getenv('APP_URL');
if ($appUrl === '' && $host !== '') {
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $basePath = '/Invoicechat';
    if ($documentRoot && realpath(BASE_DIR) !== false) {
        $relativePath = str_replace('\\', '/', substr(realpath(BASE_DIR), strlen($documentRoot)));
        $basePath = '/' . trim($relativePath, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
    $appUrl = $scheme . '://' . $host . ($basePath === '/' ? '' : $basePath);
}
if ($appUrl === '') {
    $appUrl = IS_LOCAL ? 'http://localhost/Invoicechat' : (string) secret('app_url', '');
}
define('APP_URL', rtrim($appUrl, '/'));

// The admin mailbox: owns the seeded admin account and receives a copy of
// every sign-up.
define('ADMIN_EMAIL', strtolower((string) secret('admin_email', 'admin@swiftfusion.co.za')));
define('APP_SECRET', (string) secret('app_secret', 'change-me'));

// ── Database ─────────────────────────────────────────────────────
// Live credentials on the server; the local_* ones on XAMPP.
define('DB_HOST', (string) secret('db_host', 'localhost'));
define('DB_NAME', (string) secret(IS_LOCAL ? 'local_db_name' : 'db_name', 'swiftfusionco_invoicechat'));
define('DB_USER', (string) secret(IS_LOCAL ? 'local_db_user' : 'db_user', 'root'));
define('DB_PASS', (string) secret(IS_LOCAL ? 'local_db_pass' : 'db_pass', ''));

// ── File paths ───────────────────────────────────────────────────
define('UPLOAD_DIR',  BASE_DIR . '/uploads/logos/');
define('MEDIA_DIR',   BASE_DIR . '/uploads/marketing/');
define('PDF_DIR',     BASE_DIR . '/pdfs/');
define('MAX_LOGO_SIZE', 5 * 1024 * 1024); // 5 MB

// ── Email / SMTP (PHPMailer) ──────────────────────────────────────
// secrets.php sets the defaults; Admin → Settings → Email can override them.
define('SMTP_HOST',     (string) secret('smtp_host', ''));
define('SMTP_PORT',     (int) secret('smtp_port', 465));
define('SMTP_USER',     (string) secret('smtp_user', ''));
define('SMTP_PASS',     (string) secret('smtp_pass', ''));
define('SMTP_FROM_NAME', APP_NAME);

// ── WhatsApp Cloud API (Meta / Facebook) ──────────────────────────
// Preferred WhatsApp provider. Get these from your Meta App Dashboard:
//   developers.facebook.com → Your App → WhatsApp → API Setup / Configuration
//
// whatsapp_phone_number_id : the "Phone number ID" (NOT the phone number)
// whatsapp_token           : a temporary token (API Setup) or permanent token
// whatsapp_verify_token    : any secret string you choose; paste the SAME value
//                            into Meta's webhook "Verify token" field
//
// Leave whatsapp_token blank to fall back to Twilio (below).
define('WHATSAPP_PHONE_NUMBER_ID', (string) secret('whatsapp_phone_number_id', ''));
define('WHATSAPP_TOKEN',           (string) secret('whatsapp_token', ''));
define('WHATSAPP_VERIFY_TOKEN',    (string) secret('whatsapp_verify_token', ''));
define('WHATSAPP_API_VERSION',     'v21.0');

// ── WhatsApp (Twilio) — legacy fallback ───────────────────────────
// Only used if the WhatsApp token above is blank. Leave blank to disable.
define('TWILIO_SID',   (string) secret('twilio_sid', ''));
define('TWILIO_TOKEN', (string) secret('twilio_token', ''));
define('TWILIO_FROM',  'whatsapp:+14155238886'); // Twilio sandbox default

if (!IS_LOCAL) {
    ini_set('display_errors', '0');
}
