<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/tracking.php';

function startAppSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('invoicechat_sess');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => str_starts_with(APP_URL, 'https://'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function isLoggedIn(): bool
{
    startAppSession();
    return isset($_SESSION['user_id']);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
}

function getCurrentUser(): ?array
{
    if (!isLoggedIn()) return null;
    $stmt = getDB()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    // A disabled or deleted account is signed out on its next request.
    if (!$user || !(bool) $user['is_active']) {
        logoutUser();
        return null;
    }
    return $user;
}

function findUserByEmail(string $email): ?array
{
    $stmt = getDB()->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([strtolower(trim($email))]);
    return $stmt->fetch() ?: null;
}

/**
 * @return true|'invalid'|'disabled'|'locked'|'google' — 'google' means the
 *         account was made with Google and has no password yet.
 */
function loginUser(string $email, string $password): bool|string
{
    $email = strtolower(trim($email));
    if (tooManyAttempts($email)) {
        return 'locked';
    }
    $row = findUserByEmail($email);
    if ($row && empty($row['password_hash']) && !empty($row['google_id'])) {
        recordAttempt($email, false);
        return 'google';
    }
    if (!$row || !password_verify($password, (string) $row['password_hash'])) {
        recordAttempt($email, false);
        return 'invalid';
    }
    if (!(bool) $row['is_active']) {
        recordAttempt($email, false);
        return 'disabled';
    }
    if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
        getDB()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
    }
    recordAttempt($email, true);
    beginUserSession($row);
    return true;
}

/** Signs the user in: fresh session id, chat state reset, last-login stamped. */
function beginUserSession(array $user, bool $isNew = false): void
{
    startAppSession();
    session_regenerate_id(true);
    $_SESSION['user_id']    = (int) $user['id'];
    $_SESSION['user_role']  = $user['role'] ?? 'user';
    $_SESSION['bot_state']  = $isNew ? 'onboard_name' : 'idle';
    $_SESSION['bot_data']   = [];
    $_SESSION['invoice_id'] = null;
    getDB()->prepare('UPDATE users SET last_login_at = NOW(), login_count = login_count + 1 WHERE id = ?')->execute([$user['id']]);
}

function isAdmin(): bool
{
    return isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'admin';
}

function requireAdmin(): void
{
    requireLogin();
    $user = getCurrentUser();
    if (!$user || $user['role'] !== 'admin') {
        header('Location: ' . APP_URL . '/chat.php');
        exit;
    }
    $_SESSION['user_role'] = 'admin';
}

function defaultUserSettings(): string
{
    return json_encode([
        'currency'      => 'ZAR',
        'tax_rate'      => 0,
        'discount'      => 0,
        'template'      => 'classic',
        'payment_terms' => 'Due in 30 days',
        'accent_color'  => '#3b5bfd',
    ]);
}

function registerUser(string $email, string $password, string $fullName = ''): int|false
{
    $email = strtolower(trim($email));
    $stmt = getDB()->prepare(
        'INSERT INTO users (email, full_name, password_hash, settings, signup_method, signup_source, signup_ip)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    try {
        $stmt->execute([$email, $fullName ?: null, password_hash($password, PASSWORD_DEFAULT), defaultUserSettings(), 'email', signupSource(), clientIp()]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') return false;
        throw $e;
    }
    $id   = (int) getDB()->lastInsertId();
    $user = getDB()->query('SELECT * FROM users WHERE id = ' . $id)->fetch();
    beginUserSession($user, true);
    onNewSignup($user);
    return $id;
}

/**
 * Everything that happens once per new account, however it was made:
 * a copy to the admin mailbox, a welcome email, and the ad-platform
 * CompleteRegistration events.
 */
function onNewSignup(array $user): void
{
    $method = $user['signup_method'] === 'google' ? 'Google' : 'Email & password';
    $total  = (int) getDB()->query('SELECT COUNT(*) FROM users')->fetchColumn();

    sendMail(
        get_setting('signup_notify_email', ADMIN_EMAIL),
        'New sign-up: ' . $user['email'],
        emailLayout('New InvoiceChat sign-up', emailRows([
            'Name'      => $user['full_name'] ?: '—',
            'Email'     => $user['email'],
            'Signed up' => date('d M Y, H:i'),
            'Method'    => $method,
            'Source'    => $user['signup_source'] ?: 'Direct',
            'IP'        => $user['signup_ip'] ?: '—',
            'Users now' => (string) $total,
        ]), 'Open admin dashboard', APP_URL . '/admin/users.php?q=' . rawurlencode($user['email'])),
        [],
        $user['email']
    );

    $name = trim((string) ($user['full_name'] ?? '')) ?: 'there';
    sendMail(
        $user['email'],
        'Welcome to ' . APP_NAME,
        emailLayout('Welcome, ' . e(explode(' ', $name)[0]) . '!',
            '<p>Your ' . APP_NAME . ' account is ready. Open the chat, answer a few quick questions about your business, and send your first invoice or quote in under two minutes.</p>'
            . '<p style="color:#4a5768">Signed up with: ' . e($user['email']) . '</p>',
            'Create my first invoice', APP_URL . '/chat.php')
    );

    // Welcome credits, if the admin set any.
    $gift = (int) get_setting('bill_signup_gift', '0');
    if ($gift > 0) {
        getDB()->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')->execute([$gift, $user['id']]);
        getDB()->prepare("INSERT INTO credit_ledger (user_id, delta, kind, note) VALUES (?, ?, 'gift', 'Welcome credits')")->execute([$user['id'], $gift]);
    }

    // A lead who signs up is marked as won, so nobody calls them as a cold lead again.
    $lead = getDB()->prepare("UPDATE leads SET status = 'signed_up', user_id = ?, call_after = NULL WHERE email = ? AND status <> 'do_not_contact'");
    $lead->execute([$user['id'], $user['email']]);
    if ($lead->rowCount()) {
        getDB()->prepare("INSERT INTO lead_activity (lead_id, kind, outcome, detail) SELECT id, 'system', 'signed_up', 'Created an InvoiceChat account' FROM leads WHERE email = ?")->execute([$user['email']]);
    }

    $_SESSION['track_signup'] = trackServerEvent('CompleteRegistration', $user);
}

/** Creates (or links) the account for a verified Google profile and signs it in. */
function loginWithGoogle(array $profile): array|string
{
    $db = getDB();
    $s  = $db->prepare('SELECT * FROM users WHERE google_id = ? LIMIT 1');
    $s->execute([$profile['sub']]);
    $user  = $s->fetch() ?: findUserByEmail($profile['email']);
    $isNew = false;

    if ($user) {
        if (!(bool) $user['is_active']) {
            recordAttempt($profile['email'], false, 'google');
            return 'This account has been disabled. Please contact support.';
        }
        $db->prepare('UPDATE users SET google_id = ?, avatar_url = COALESCE(avatar_url, ?), full_name = COALESCE(full_name, ?) WHERE id = ?')
           ->execute([$profile['sub'], $profile['picture'] ?: null, $profile['name'] ?: null, $user['id']]);
    } else {
        $db->prepare('INSERT INTO users (email, full_name, google_id, avatar_url, settings, signup_method, signup_source, signup_ip)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$profile['email'], $profile['name'] ?: null, $profile['sub'], $profile['picture'] ?: null,
                      defaultUserSettings(), 'google', signupSource(), clientIp()]);
        $user  = ['id' => (int) $db->lastInsertId()];
        $isNew = true;
    }
    $s = $db->prepare('SELECT * FROM users WHERE id = ?');
    $s->execute([$user['id']]);
    $user = $s->fetch();

    recordAttempt($profile['email'], true, 'google');
    beginUserSession($user, $isNew);
    if ($isNew) onNewSignup($user);
    return $user;
}

// ── Password reset ────────────────────────────────────────────────
const RESET_MINUTES = 60;

/** Emails a reset link if the account exists. Says nothing either way to the caller. */
function requestPasswordReset(string $email): void
{
    $user = findUserByEmail($email);
    if (!$user || !(bool) $user['is_active']) return;

    // At most 3 links an hour per account.
    $s = getDB()->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)');
    $s->execute([$user['id']]);
    if ((int) $s->fetchColumn() >= 3) return;

    sendResetLink($user);
}

function sendResetLink(array $user): string
{
    $token = bin2hex(random_bytes(32));
    getDB()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, ip) VALUES (?, ?, NOW() + INTERVAL ' . RESET_MINUTES . ' MINUTE, ?)')
        ->execute([$user['id'], hash('sha256', $token), clientIp()]);
    $link = APP_URL . '/reset-password.php?token=' . $token;
    $hasPw = !empty($user['password_hash']);

    return sendMail($user['email'], ($hasPw ? 'Reset your ' : 'Set your ') . APP_NAME . ' password', emailLayout(
        $hasPw ? 'Reset your password' : 'Set a password',
        '<p>We received a request to ' . ($hasPw ? 'reset' : 'set') . ' the password for <strong>' . e($user['email']) . '</strong>.</p>'
        . '<p>The link works once and expires in ' . RESET_MINUTES . ' minutes. If you didn\'t ask for this, you can ignore this email — your password stays the same.</p>',
        $hasPw ? 'Reset password' : 'Set password', $link
    ));
}

/** The user a still-valid reset token belongs to. */
function findResetUser(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $s = getDB()->prepare(
        'SELECT u.* FROM password_resets r JOIN users u ON u.id = r.user_id
         WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW() AND u.is_active = 1'
    );
    $s->execute([hash('sha256', $token)]);
    return $s->fetch() ?: null;
}

function completePasswordReset(string $token, string $password): ?array
{
    $user = findResetUser($token);
    if (!$user) return null;
    $db = getDB();
    $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    // Using one link retires every other outstanding link for the account.
    $db->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$user['id']]);
    sendMail($user['email'], 'Your ' . APP_NAME . ' password was changed', emailLayout(
        'Password changed',
        '<p>The password for <strong>' . e($user['email']) . '</strong> was just changed. If this wasn\'t you, reset it again straight away and contact us at ' . e(ADMIN_EMAIL) . '.</p>',
        'Sign in', APP_URL . '/login.php'
    ));
    return $user;
}

function passwordProblem(string $password, string $confirm): ?string
{
    if (strlen($password) < 8) return 'Password must be at least 8 characters.';
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) return 'Use at least one letter and one number.';
    if ($password !== $confirm) return 'Passwords do not match.';
    return null;
}

function logoutUser(): void
{
    startAppSession();
    session_unset();
    session_destroy();
}
