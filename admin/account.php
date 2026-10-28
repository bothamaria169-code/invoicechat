<?php
declare(strict_types=1);

require_once __DIR__ . '/_shell.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string) ($_POST['current'] ?? '');
    $new     = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');
    if ($ADMIN['password_hash'] && !password_verify($current, $ADMIN['password_hash'])) {
        flash('error', 'Your current password is wrong.');
    } elseif ($problem = passwordProblem($new, $confirm)) {
        flash('error', $problem);
    } else {
        getDB()->prepare('UPDATE users SET password_hash = ?, full_name = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), trim((string) ($_POST['full_name'] ?? '')) ?: $ADMIN['full_name'], $ADMIN['id']]);
        session_regenerate_id(true);
        flash('success', 'Password changed.');
    }
    redirect('admin/account.php');
}

adminOpen('account', 'My account', e($ADMIN['email']));
?>
<section class="card" style="max-width:640px">
  <div class="card__head"><div><h2>Change password</h2><p>At least 8 characters with a letter and a number.</p></div></div>
  <div class="card__body">
    <form method="POST"><?= csrfField() ?>
      <div class="field"><label>Display name</label><input name="full_name" value="<?= e($ADMIN['full_name'] ?? '') ?>"></div>
      <?php if ($ADMIN['password_hash']): ?>
        <div class="field"><label>Current password</label><input type="password" name="current" autocomplete="current-password" required></div>
      <?php endif; ?>
      <div class="form-grid">
        <div class="field"><label>New password</label><input type="password" name="password" autocomplete="new-password" required></div>
        <div class="field"><label>Confirm new password</label><input type="password" name="confirm" autocomplete="new-password" required></div>
      </div>
      <div class="form-foot"><button class="btn btn-primary">Update password</button></div>
    </form>
  </div>
</section>
<?php adminClose();
