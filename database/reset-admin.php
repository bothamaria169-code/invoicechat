<?php
declare(strict_types=1);

/**
 * Sets a new password for the admin account from the command line, clears its
 * sign-in lockout and makes sure it is an active admin. Nothing is stored in
 * git: the password is typed when you run it.
 *
 *   cPanel → Terminal:
 *     php ~/invoicechat.swiftfusion.co.za/database/reset-admin.php
 *
 *   No Terminal? Add a one-off cPanel cron job, then delete it:
 *     php /home/swiftfusionco/invoicechat.swiftfusion.co.za/database/reset-admin.php 'NewPassword' > /home/swiftfusionco/reset-admin.txt 2>&1
 *
 * Pass an email as the second argument to reset a different account.
 * Web requests are refused (and .htaccess blocks this folder anyway).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/db.php';

$password = (string) ($argv[1] ?? '');
$email    = strtolower(trim((string) ($argv[2] ?? ADMIN_EMAIL)));

if ($password === '') {
    echo 'New password for ' . $email . ': ';
    $password = trim((string) fgets(STDIN));
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters. Nothing changed.\n");
    exit(1);
}

$db = getDB();
echo 'Database: ' . DB_NAME . "\n";
echo 'secrets.php: ' . (is_file(dirname(__DIR__) . '/config/secrets.php') ? 'found' : 'MISSING') . "\n";

$s = $db->prepare('SELECT id FROM users WHERE email = ?');
$s->execute([$email]);
$id = $s->fetchColumn();
$hash = password_hash($password, PASSWORD_DEFAULT);

if ($id) {
    $db->prepare("UPDATE users SET password_hash = ?, role = 'admin', is_active = 1 WHERE id = ?")->execute([$hash, $id]);
    echo "Updated {$email} (user #{$id}).\n";
} else {
    $db->prepare("INSERT INTO users (email, full_name, password_hash, role, is_active, settings, signup_method) VALUES (?, 'Administrator', ?, 'admin', 1, '{}', 'seed')")
       ->execute([$email, $hash]);
    echo "Created {$email} as admin (user #{$db->lastInsertId()}).\n";
}
$db->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);
echo "Lockout cleared. Sign in at " . APP_URL . "/login.php\n";
