<?php
declare(strict_types=1);

require_once __DIR__ . '/_shell.php';
require_once BASE_DIR . '/classes/GoogleAuth.php';

$tab = in_array($_GET['tab'] ?? '', ['general', 'email', 'google'], true) ? $_GET['tab'] : 'general';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = (string) ($_POST['form'] ?? '');
    if ($form === 'general') {
        $notify = strtolower(trim((string) ($_POST['signup_notify_email'] ?? '')));
        if ($notify !== '' && !filter_var($notify, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'That notification address isn\'t a valid email.');
        } else {
            set_setting('signup_notify_email', $notify);
            set_setting('mail_from_name', trim((string) ($_POST['mail_from_name'] ?? '')));
            flash('success', 'Saved.');
        }
    } elseif ($form === 'email') {
        set_setting('smtp_host', trim((string) ($_POST['smtp_host'] ?? '')));
        set_setting('smtp_port', (string) (int) ($_POST['smtp_port'] ?? 465));
        set_setting('smtp_user', trim((string) ($_POST['smtp_user'] ?? '')));
        save_secret_setting('smtp_pass', (string) ($_POST['smtp_pass'] ?? ''));
        flash('success', 'Email settings saved. Send a test to check them.');
    } elseif ($form === 'email_test') {
        $err = sendMail($ADMIN['email'], 'Test email from ' . APP_NAME, emailLayout('It works!', '<p>Your ' . APP_NAME . ' email settings are working. Sign-up copies, password resets and invoices will be delivered.</p>'));
        flash($err === '' ? 'success' : 'error', $err === '' ? 'Test email sent to ' . $ADMIN['email'] . '. Check the inbox (and spam).' : 'Sending failed: ' . $err);
    } elseif ($form === 'google') {
        set_setting('google_login_enabled', empty($_POST['google_login_enabled']) ? '0' : '1');
        set_setting('google_client_id', trim((string) ($_POST['google_client_id'] ?? '')));
        save_secret_setting('google_client_secret', (string) ($_POST['google_client_secret'] ?? ''));
        flash('success', GoogleAuth::enabled() ? 'Google sign-in is on. Try it from a private window.' : 'Saved. Add both the Client ID and secret to switch Google sign-in on.');
    }
    redirect('admin/settings.php?tab=' . $tab);
}

$smtpPass = get_setting('smtp_pass', SMTP_PASS);
$smtpMode = get_setting('smtp_host', SMTP_HOST) !== '' && $smtpPass !== '';

adminOpen('settings', 'Settings', 'Email delivery, Google sign-in and notifications.');
?>
<nav class="tabs">
  <a href="?tab=general" class="<?= $tab === 'general' ? 'is-on' : '' ?>"><i class="fa-solid fa-bell"></i> Notifications</a>
  <a href="?tab=email" class="<?= $tab === 'email' ? 'is-on' : '' ?>"><i class="fa-regular fa-envelope"></i> Email delivery</a>
  <a href="?tab=google" class="<?= $tab === 'google' ? 'is-on' : '' ?>"><i class="fa-brands fa-google"></i> Google sign-in</a>
</nav>

<?php if ($tab === 'general'): ?>
<section class="card" style="max-width:760px">
  <div class="card__head"><div><h2>Sign-up notifications</h2><p>A copy of every new account (email or Google) is sent here.</p></div></div>
  <div class="card__body">
    <form method="POST"><?= csrfField() ?><input type="hidden" name="form" value="general">
      <div class="form-grid">
        <div class="field"><label>Send sign-up copies to</label><input type="email" name="signup_notify_email" value="<?= e(get_setting('signup_notify_email')) ?>" placeholder="<?= e(ADMIN_EMAIL) ?>"><div class="hint">Leave blank to use <?= e(ADMIN_EMAIL) ?>.</div></div>
        <div class="field"><label>Email sender name</label><input type="text" name="mail_from_name" value="<?= e(get_setting('mail_from_name')) ?>" placeholder="<?= APP_NAME ?>"></div>
      </div>
      <div class="form-foot"><button class="btn btn-primary">Save</button></div>
    </form>
  </div>
</section>

<?php elseif ($tab === 'email'): ?>
<div class="grid-2">
  <section class="card">
    <div class="card__head"><div><h2>Mailbox (SMTP)</h2><p>Sends sign-up copies, password resets, welcome emails and invoices.</p></div></div>
    <div class="card__body">
      <div class="status-line <?= $smtpMode ? 'ok' : 'off' ?>"><span class="dot"></span><?= $smtpMode ? 'Sending through ' . e(get_setting('smtp_host', SMTP_HOST)) : 'No SMTP password — using the server\'s PHP mail()' ?></div>
      <form method="POST"><?= csrfField() ?><input type="hidden" name="form" value="email">
        <div class="form-grid">
          <div class="field"><label>SMTP host</label><input name="smtp_host" value="<?= e(get_setting('smtp_host', SMTP_HOST)) ?>" placeholder="mail.swiftfusion.co.za"></div>
          <div class="field"><label>Port</label><select name="smtp_port"><?php foreach ([465 => '465 (SSL)', 587 => '587 (STARTTLS)'] as $p => $l): ?><option value="<?= $p ?>" <?= (int) get_setting('smtp_port', (string) SMTP_PORT) === $p ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Username (mailbox)</label><input name="smtp_user" value="<?= e(get_setting('smtp_user', SMTP_USER)) ?>" placeholder="admin@swiftfusion.co.za"></div>
          <div class="field"><label>Password</label><input type="password" name="smtp_pass" value="<?= e(masked($smtpPass)) ?>" autocomplete="new-password" placeholder="Mailbox password"></div>
        </div>
        <div class="form-foot"><button class="btn btn-primary">Save</button></div>
      </form>
    </div>
  </section>
  <section class="card">
    <div class="card__head"><h2>Test it</h2></div>
    <div class="card__body">
      <p class="hint" style="margin:0 0 1rem;font-size:.86rem">Sends a test email to <strong><?= e($ADMIN['email']) ?></strong>.</p>
      <form method="POST"><?= csrfField() ?><input type="hidden" name="form" value="email_test"><button class="btn btn-ghost btn-block"><i class="fa-solid fa-paper-plane"></i> Send test email</button></form>
      <ol class="steps-list" style="margin-top:1.2rem">
        <li>cPanel → <strong>Email Accounts</strong> → find <code>admin@swiftfusion.co.za</code> → <strong>Connect Devices</strong>.</li>
        <li>Copy the <strong>Outgoing server</strong> (usually <code>mail.swiftfusion.co.za</code>, port 465).</li>
        <li>Paste the mailbox password here and Save.</li>
      </ol>
    </div>
  </section>
</div>

<?php else: ?>
<div class="grid-2">
  <section class="card">
    <div class="card__head"><div><h2>Continue with Google</h2><p>Shows on the sign-in and sign-up pages.</p></div></div>
    <div class="card__body">
      <div class="status-line <?= GoogleAuth::enabled() ? 'ok' : 'off' ?>"><span class="dot"></span><?= GoogleAuth::enabled() ? 'On — visitors see the Google button' : 'Off — add the keys below' ?></div>
      <form method="POST"><?= csrfField() ?><input type="hidden" name="form" value="google">
        <div class="field"><label>Client ID</label><input name="google_client_id" value="<?= e(get_setting('google_client_id')) ?>" placeholder="1234-abc.apps.googleusercontent.com"></div>
        <div class="field"><label>Client secret</label><input type="password" name="google_client_secret" value="<?= e(masked(get_setting('google_client_secret'))) ?>" autocomplete="new-password" placeholder="GOCSPX-…"></div>
        <label class="check"><input type="checkbox" name="google_login_enabled" value="1" <?= get_setting('google_login_enabled', '1') === '1' ? 'checked' : '' ?>> Show the Google button</label>
        <div class="form-foot"><button class="btn btn-primary">Save</button></div>
      </form>
    </div>
  </section>
  <section class="card">
    <div class="card__head"><h2>Setup (about 10 minutes)</h2></div>
    <div class="card__body">
      <ol class="steps-list">
        <li><a href="https://console.cloud.google.com" target="_blank" rel="noopener">console.cloud.google.com</a> → New project <strong><?= APP_NAME ?></strong>.</li>
        <li><strong>Google Auth Platform → Branding</strong>: app name, support email <?= e(ADMIN_EMAIL) ?>, home page, privacy & terms links (below), authorised domain <code><?= e(parse_url(APP_URL, PHP_URL_HOST)) ?></code>.</li>
        <li><strong>Audience</strong> → External → <strong>Publish app</strong>.</li>
        <li><strong>Clients → Create client</strong> → Web application, with:</li>
      </ol>
      <div class="hint" style="margin-bottom:.3rem">Authorised JavaScript origin</div>
      <div class="copy-box"><code><?= e(preg_replace('#^(https?://[^/]+).*$#', '$1', APP_URL)) ?></code><button type="button" class="btn-xs" data-copy="<?= e(preg_replace('#^(https?://[^/]+).*$#', '$1', APP_URL)) ?>"><i class="fa-regular fa-copy"></i></button></div>
      <div class="hint" style="margin-bottom:.3rem">Authorised redirect URI</div>
      <div class="copy-box"><code><?= e(GoogleAuth::redirectUri()) ?></code><button type="button" class="btn-xs" data-copy="<?= e(GoogleAuth::redirectUri()) ?>"><i class="fa-regular fa-copy"></i></button></div>
      <div class="hint">Privacy: <?= e(APP_URL) ?>/privacy.php · Terms: <?= e(APP_URL) ?>/terms.php</div>
      <p class="hint" style="margin-top:.8rem">Copy the Client ID and secret into the form. Do this on the live site — Google won't redirect back to localhost unless you add it as a second redirect URI.</p>
    </div>
  </section>
</div>
<?php endif; ?>

<?php adminClose();
