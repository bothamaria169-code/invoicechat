<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

startAppSession();

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$user  = findResetUser($token);
$error = '';

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm'] ?? '');
    if (!csrfValid()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($problem = passwordProblem($password, $confirm)) {
        $error = $problem;
    } elseif ($done = completePasswordReset($token, $password)) {
        logoutUser();
        startAppSession();
        flash('success', 'Password updated. Sign in with your new password.');
        redirect('login.php');
    } else {
        $user = null;
    }
}

if (!$user) {
    authOpen('Reset password', 'This link has expired', 'Reset links work once and last ' . RESET_MINUTES . ' minutes. Request a fresh one below.');
    ?>
      <a href="forgot-password.php" class="btn btn-primary btn-block btn-lg">Send a new link</a>
      <p class="auth__alt"><a href="login.php">Back to sign in</a></p>
    <?php
    authClose();
    exit;
}

authOpen('Reset password', 'Choose a new password', 'For <strong>' . e($user['email']) . '</strong>');
?>
      <?php if ($error): ?><div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($error) ?></span></div><?php endif; ?>
      <form method="POST" novalidate data-busy>
        <?= csrfField() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="field">
          <label for="password">New password</label>
          <div class="input-wrap"><i class="fa-solid fa-lock"></i>
            <input type="password" id="password" name="password" placeholder="At least 8 characters, with a number" autocomplete="new-password" required autofocus>
            <button type="button" class="pw-toggle" data-toggle-pw="password" aria-label="Show password"><i class="fa-regular fa-eye"></i></button>
          </div>
        </div>
        <div class="field">
          <label for="confirm">Confirm new password</label>
          <div class="input-wrap"><i class="fa-solid fa-lock"></i>
            <input type="password" id="confirm" name="confirm" placeholder="Repeat password" autocomplete="new-password" required>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-block btn-lg">Update password</button>
      </form>
<?php authClose();
