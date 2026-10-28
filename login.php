<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

startAppSession();
captureAttribution();
if (isLoggedIn() && getCurrentUser()) { redirect(isAdmin() ? 'admin/' : 'chat.php'); }

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if (!csrfValid()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'That email address doesn\'t look right.';
    } else {
        $result = loginUser($email, $password);
        $error = match ($result) {
            true       => '',
            'disabled' => 'This account has been disabled. Please contact ' . ADMIN_EMAIL . '.',
            'locked'   => 'Too many attempts. Please wait 15 minutes, or reset your password.',
            'google'   => 'This account uses Google sign-in. Continue with Google, or use "Forgot password" to set a password.',
            default    => 'Wrong email or password.',
        };
        if ($result === true) {
            $next = (string) ($_SESSION['after_login'] ?? '');
            unset($_SESSION['after_login']);
            redirect(isAdmin() ? 'admin/' : ($next !== '' ? $next : 'chat.php'));
        }
    }
}

authOpen('Sign in', 'Welcome back', 'Sign in to keep invoicing.');
?>
      <?php if ($error): ?><div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($error) ?></span></div><?php endif; ?>

      <?= googleButton() ?>

      <form method="POST" novalidate data-busy>
        <?= csrfField() ?>
        <div class="field">
          <label for="email">Email</label>
          <div class="input-wrap"><i class="fa-regular fa-envelope"></i>
            <input type="email" id="email" name="email" placeholder="you@business.co.za" value="<?= e($email) ?>" autocomplete="email" required <?= $email ? '' : 'autofocus' ?>>
          </div>
        </div>
        <div class="field">
          <label for="password">Password <a href="forgot-password.php<?= $email ? '?email=' . rawurlencode($email) : '' ?>">Forgot password?</a></label>
          <div class="input-wrap"><i class="fa-solid fa-lock"></i>
            <input type="password" id="password" name="password" placeholder="Your password" autocomplete="current-password" required <?= $email ? 'autofocus' : '' ?>>
            <button type="button" class="pw-toggle" data-toggle-pw="password" aria-label="Show password"><i class="fa-regular fa-eye"></i></button>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-block btn-lg">Sign in</button>
      </form>

      <p class="auth__alt">New to <?= APP_NAME ?>? <a href="register.php">Create a free account</a></p>
<?php authClose();
