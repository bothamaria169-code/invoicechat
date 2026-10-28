<?php
declare(strict_types=1);

/**
 * Scheduled jobs: publishes due marketing posts and tidies old security rows.
 *
 * cPanel → Cron Jobs, every 5 minutes:
 *   /usr/local/bin/php /home/swiftfusionco/invoicechat.swiftfusion.co.za/cron.php >/dev/null 2>&1
 * or, from a URL cron service:
 *   https://invoicechat.swiftfusion.co.za/cron.php?key=CRON_KEY
 */
require_once __DIR__ . '/includes/marketing.php';

$cronKey = (string) secret('cron_key', '');
if (PHP_SAPI !== 'cli' && ($cronKey === '' || !hash_equals($cronKey, (string) ($_GET['key'] ?? '')))) {
    http_response_code(403);
    exit('Forbidden');
}
set_time_limit(280);

$log = runDuePosts();

$db = getDB();
$db->exec('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 90 DAY');
$db->exec('DELETE FROM password_resets WHERE created_at < NOW() - INTERVAL 7 DAY');
set_setting('cron_last_run', date('Y-m-d H:i:s'));

header('Content-Type: text/plain');
echo date('c') . ' ok' . ($log ? "\n" . implode("\n", $log) : '') . "\n";
