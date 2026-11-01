<?php
declare(strict_types=1);

/**
 * Locked out of admin with no Terminal? Open /admin-reset.php, enter the
 * database password (db_pass in config/secrets.php — only someone with access
 * to the server can read it) and choose a new admin password. It also clears
 * the "too many attempts" lockout. Wrong database passwords count towards the
 * normal lockout, so it can't be guessed.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

startAppSession();

$error = '';
$done  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key      = (string) ($_POST['db_pass'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm'] ?? '');

    if (!csrfValid()) {
        $error = 'Your session expired. Please try again.';
    } elseif (tooManyAttempts('admin-reset')) {
        $error = 'Too many attempts. Please wait 15 minutes.';
    } elseif (DB_PASS === '' || !hash_equals(DB_PASS, $key)) {
        recordAttempt('admin-reset', false, 'admin-reset');
        $error = 'That isn\'t the database password from config/secrets.php (db_pass).';
    } elseif ($problem = passwordProblem($password, $confirm)) {
        $error = $problem;
    } else {
        // Same steps as database/reset-admin.php, kept here so this one file
        // works even when uploaded on its own.
        $db = getDB();
        $s = $db->prepare('SELECT id FROM users WHERE email = ?');
        $s->execute([ADMIN_EMAIL]);
        $id   = $s->fetchColumn();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($id) {
            $db->prepare("UPDATE users SET password_hash = ?, role = 'admin', is_active = 1 WHERE id = ?")->execute([$hash, $id]);
            $done = 'Updated ' . ADMIN_EMAIL . " (user #{$id}).";
        } else {
            $db->prepare("INSERT INTO users (email, full_name, password_hash, role, is_active, settings, signup_method) VALUES (?, 'Administrator', ?, 'admin', 1, '{}', 'seed')")
               ->execute([ADMIN_EMAIL, $hash]);
            $done = 'Created ' . ADMIN_EMAIL . " as admin (user #{$db->lastInsertId()}).";
        }
        $db->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([ADMIN_EMAIL]);
        error_log('InvoiceChat: admin password set through admin-reset.php from ' . clientIp());
    }
}

if ($done) {
    authOpen('Admin password reset', 'Admin password updated', e($done) . ' Any sign-in lockout has been cleared.');
    ?>
      <a href="login.php" class="btn btn-primary btn-block btn-lg">Sign in as <?= e(ADMIN_EMAIL) ?></a>
    <?php
    authClose();
    exit;
}

authOpen('Admin password reset', 'Reset the admin password', 'For <strong>' . e(ADMIN_EMAIL) . '</strong>. You\'ll need the <strong>db_pass</strong> value from config/secrets.php on the server.');
?>
      <?php if ($error): ?><div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($error) ?></span></div><?php endif; ?>
      <form method="POST" novalidate data-busy>
        <?= csrfField() ?>
        <div class="field">
          <label for="db_pass">Database password (db_pass)</label>
          <div class="input-wrap"><i class="fa-solid fa-key"></i>
            <input type="password" id="db_pass" name="db_pass" autocomplete="off" required autofocus>
            <button type="button" class="pw-toggle" data-toggle-pw="db_pass" aria-label="Show password"><i class="fa-regular fa-eye"></i></button>
          </div>
        </div>
        <div class="field">
          <label for="password">New admin password</label>
          <div class="input-wrap"><i class="fa-solid fa-lock"></i>
            <input type="password" id="password" name="password" placeholder="At least 8 characters, with a number" autocomplete="new-password" required>
            <button type="button" class="pw-toggle" data-toggle-pw="password" aria-label="Show password"><i class="fa-regular fa-eye"></i></button>
          </div>
        </div>
        <div class="field">
          <label for="confirm">Confirm new password</label>
          <div class="input-wrap"><i class="fa-solid fa-lock"></i>
            <input type="password" id="confirm" name="confirm" placeholder="Repeat password" autocomplete="new-password" required>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-block btn-lg">Set admin password</button>
      </form>
      <p class="auth__alt"><a href="login.php">Back to sign in</a></p>
<?php authClose();
