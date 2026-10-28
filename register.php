<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

startAppSession();
captureAttribution();
if (isLoggedIn() && getCurrentUser()) { redirect('chat.php'); }

$error = '';
$name  = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim((string) ($_POST['name'] ?? ''));
    $email    = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm'] ?? '');

    if (!csrfValid()) {
        $error = 'Your session expired. Please try again.';
    } elseif (trim((string) ($_POST['website'] ?? '')) !== '') {
        $error = 'Please try again.'; // honeypot: only bots fill the hidden field
    } elseif ($name === '' || $email === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'That email address doesn\'t look right.';
    } elseif ($problem = passwordProblem($password, $confirm)) {
        $error = $problem;
    } elseif (empty($_POST['terms'])) {
        $error = 'Please accept the terms to continue.';
    } else {
        try {
            if (registerUser($email, $password, mb_substr($name, 0, 150)) === false) {
                $error = 'This email is already registered.';
            } else {
                redirect('chat.php');
            }
        } catch (Throwable $e) {
            error_log('InvoiceChat registration failed: ' . $e->getMessage());
            $error = 'Sign-up is temporarily unavailable. Please try again in a minute.';
        }
    }
}

authOpen('Create account', 'Create your free account', 'Your first invoice is about two minutes away.');
?>
      <?php if ($error): ?>
        <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($error) ?>
          <?php if ($error === 'This email is already registered.'): ?> <a href="login.php">Sign in</a> or <a href="forgot-password.php?email=<?= rawurlencode($email) ?>">reset your password</a>.<?php endif; ?>
        </span></div>
      <?php endif; ?>

      <?= googleButton('Sign up with Google') ?>

      <form method="POST" novalidate data-busy>
        <?= csrfField() ?>
        <div style="position:absolute;left:-9999px" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
        <div class="field">
          <label for="name">Your name</label>
          <div class="input-wrap"><i class="fa-regular fa-user"></i>
            <input type="text" id="name" name="name" placeholder="Thandi Mokoena" value="<?= e($name) ?>" autocomplete="name" required autofocus>
          </div>
        </div>
        <div class="field">
          <label for="email">Email</label>
          <div class="input-wrap"><i class="fa-regular fa-envelope"></i>
            <input type="email" id="email" name="email" placeholder="you@business.co.za" value="<?= e($email) ?>" autocomplete="email" required>
          </div>
        </div>
        <div class="field">
          <label for="password">Password</label>
          <div class="input-wrap"><i class="fa-solid fa-lock"></i>
            <input type="password" id="password" name="password" placeholder="At least 8 characters, with a number" autocomplete="new-password" required>
            <button type="button" class="pw-toggle" data-toggle-pw="password" aria-label="Show password"><i class="fa-regular fa-eye"></i></button>
          </div>
          <div class="strength"><span id="pwStrength"></span></div>
        </div>
        <div class="field">
          <label for="confirm">Confirm password</label>
          <div class="input-wrap"><i class="fa-solid fa-lock"></i>
            <input type="password" id="confirm" name="confirm" placeholder="Repeat password" autocomplete="new-password" required>
          </div>
        </div>
        <label class="check"><input type="checkbox" name="terms" value="1" <?= !empty($_POST['terms']) ? 'checked' : '' ?>>
          <span>I agree to the <a href="terms.php" target="_blank">Terms</a> and <a href="privacy.php" target="_blank">Privacy Policy</a>.</span></label>
        <button type="submit" class="btn btn-primary btn-block btn-lg">Create account</button>
      </form>

      <p class="auth__alt">Already have an account? <a href="login.php">Sign in</a></p>
      <script>
        document.getElementById('password').addEventListener('input', function () {
          var v = this.value, s = 0;
          if (v.length >= 8) s++; if (v.length >= 12) s++;
          if (/\d/.test(v) && /[A-Za-z]/.test(v)) s++; if (/[^A-Za-z0-9]/.test(v)) s++;
          var bar = document.getElementById('pwStrength');
          bar.style.width = (s * 25) + '%';
          bar.style.background = s < 2 ? 'var(--coral)' : s < 3 ? 'var(--amber)' : 'var(--mint)';
        });
      </script>
<?php authClose();
