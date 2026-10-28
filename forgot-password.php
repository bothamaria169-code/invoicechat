<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

startAppSession();

$sent  = false;
$error = '';
$email = strtolower(trim((string) ($_POST['email'] ?? $_GET['email'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValid()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter the email address you signed up with.';
    } else {
        requestPasswordReset($email);
        // Same answer whether or not the account exists, so this page can't
        // be used to discover who has an account.
        $sent = true;
    }
}

authOpen('Forgot password', $sent ? 'Check your inbox' : 'Forgot your password?',
    $sent ? 'If an account exists for <strong>' . e($email) . '</strong>, a reset link is on its way. It expires in ' . RESET_MINUTES . ' minutes.'
          : 'Enter your email and we\'ll send you a link to choose a new one.');
?>
      <?php if ($sent): ?>
        <div class="alert alert-info"><i class="fa-solid fa-envelope-open-text"></i><span>Can't find it? Check your spam folder, or try again in a minute.</span></div>
        <a href="login.php" class="btn btn-ghost btn-block btn-lg"><i class="fa-solid fa-arrow-left"></i> Back to sign in</a>
      <?php else: ?>
        <?php if ($error): ?><div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($error) ?></span></div><?php endif; ?>
        <form method="POST" novalidate data-busy>
          <?= csrfField() ?>
          <div class="field">
            <label for="email">Email</label>
            <div class="input-wrap"><i class="fa-regular fa-envelope"></i>
              <input type="email" id="email" name="email" placeholder="you@business.co.za" value="<?= e($email) ?>" autocomplete="email" required autofocus>
            </div>
          </div>
          <button type="submit" class="btn btn-primary btn-block btn-lg">Send reset link</button>
        </form>
        <p class="auth__alt"><a href="login.php"><i class="fa-solid fa-arrow-left"></i> Back to sign in</a></p>
      <?php endif; ?>
<?php authClose();
