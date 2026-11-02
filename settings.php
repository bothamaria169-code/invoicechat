<?php
declare(strict_types=1);

// "My settings": everything about the business in one place, any time —
// details, logo, banking, invoice look & numbering, saved products, account.
// (setup.php is the step-by-step version for new users; both save the same way.)
require_once __DIR__ . '/includes/billing.php';
require_once __DIR__ . '/includes/logos.php';
require_once __DIR__ . '/includes/profile.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/vendor/autoload.php';

startAppSession();
requireLogin();
$user = getCurrentUser();
if (!$user) redirect('login.php');
$uid  = (int) $user['id'];
$pm   = new \InvoiceChat\ProfileManager(getDB());
$tabs = [
    'business' => ['Business', 'fa-store'],
    'logo'     => ['Logo', 'fa-image'],
    'banking'  => ['Banking', 'fa-building-columns'],
    'invoices' => ['Invoices', 'fa-file-invoice'],
    'products' => ['Products', 'fa-tags'],
    'account'  => ['Account', 'fa-user-lock'],
];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'business';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $form = (string) ($_POST['form'] ?? '');

    if ($form === 'business') {
        $err = saveBusinessForm($uid, $pm);
        flash($err ? 'error' : 'success', $err ?: 'Business details saved.');
    } elseif ($form === 'logo_upload') {
        $ok   = !empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name']);
        $mime = $ok ? (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['logo']['tmp_name']) : '';
        $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? null;
        if (!$ext || $_FILES['logo']['size'] > MAX_LOGO_SIZE) {
            flash('error', 'Upload a JPG, PNG or WebP image up to 5 MB.');
        } else {
            saveUserLogo($uid, (string) file_get_contents($_FILES['logo']['tmp_name']), $ext);
            flash('success', 'Logo saved. It will show on your next PDF.');
        }
    } elseif ($form === 'logo_remove') {
        if (!empty($user['logo_path']) && is_file($user['logo_path']) && str_starts_with(realpath($user['logo_path']) ?: '', realpath(UPLOAD_DIR) ?: '?')) {
            @unlink($user['logo_path']);
        }
        $pm->update($uid, ['logo_path' => null]);
        flash('success', 'Logo removed.');
    } elseif ($form === 'banking') {
        saveBankingForm($uid, $pm);
        flash('success', 'Banking details saved. They show in the “How to pay” box on your invoices.');
    } elseif ($form === 'invoices') {
        saveLookForm($uid, $pm);
        $pm->updateSettings($uid, [
            'discount'       => max(0, min(100, (float) ($_POST['discount'] ?? 0))),
            'invoice_prefix' => cleanPrefix(postText('invoice_prefix', 20), 'INV'),
            'quote_prefix'   => cleanPrefix(postText('quote_prefix', 20), 'QUO'),
            'default_notes'  => postText('default_notes', 1000),
        ]);
        flash('success', 'Invoice settings saved. They apply to new invoices and quotes.');
    } elseif ($form === 'product_save') {
        $name  = postText('name', 200);
        $price = parseAmount(postText('price', 30));
        if ($name === '' || $price === null) {
            flash('error', 'Add a name and a price (e.g. 450 or 1 200,50).');
        } else {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id) {
                getDB()->prepare('UPDATE products SET name = ?, name_key = ?, unit_price = ?, description = ? WHERE id = ? AND user_id = ?')
                    ->execute([$name, mb_strtolower($name), $price, postText('description', 500), $id, $uid]);
            } else {
                saveProduct($uid, $name, $price, postText('description', 500));
            }
            flash('success', 'Saved “' . $name . '”.');
        }
    } elseif ($form === 'product_delete') {
        getDB()->prepare('DELETE FROM products WHERE id = ? AND user_id = ?')->execute([(int) ($_POST['id'] ?? 0), $uid]);
        flash('success', 'Product removed.');
    } elseif ($form === 'product_import') {
        $n = importProductsFromInvoices($uid);
        flash('success', $n ? "Added {$n} products from your past invoices. Check the prices below." : 'No new products found in your past invoices.');
    } elseif ($form === 'password') {
        $has  = !empty($user['password_hash']);
        $new  = (string) ($_POST['new_password'] ?? '');
        $prob = passwordProblem($new, (string) ($_POST['confirm_password'] ?? ''));
        if ($has && !password_verify((string) ($_POST['current_password'] ?? ''), $user['password_hash'])) {
            flash('error', 'Your current password is not right.');
        } elseif ($prob) {
            flash('error', $prob);
        } else {
            getDB()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            flash('success', $has ? 'Password changed.' : 'Password set. You can now also sign in with your email and password.');
        }
    }
    redirect('settings.php?tab=' . $tab);
}

$user     = freshUser($uid);
$settings = $pm->getSettings($uid);
$accent   = accentColor($user);
$bf       = bankFields($user['bank_details'] ?? '');
$hasLogo  = !empty($user['logo_path']) && is_file($user['logo_path']);

// What's still missing, so the page can point at it.
$todo = array_filter([
    'business' => empty($user['address']) || empty($user['phone']),
    'logo'     => !$hasLogo,
    'banking'  => empty($user['bank_details']),
]);

pageHead('Settings – ' . APP_NAME, ['css' => ['style.css', 'admin.css', 'profile.css']]);
?>
<body>
<header class="site-nav"><div class="wrap"><?= brandMark() ?><nav><a href="chat.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back to chat</a></nav></div></header>
<main class="wrap" style="padding:2rem 0 4rem;max-width:900px">
  <div class="adm-head"><div><h1>Settings</h1><p>Your business details, logo, banking and invoice defaults. Changes apply to your next invoice or quote.</p></div></div>
  <?= flashHtml() ?>
  <?php if ($todo && $tab !== 'account'): ?>
    <div class="alert alert-info"><i class="fa-solid fa-list-check"></i><span>Still to do:
      <?= implode(' · ', array_map(fn($k) => '<a href="?tab=' . $k . '">' . ['business' => 'address & phone', 'logo' => 'add your logo', 'banking' => 'banking details (so clients know how to pay)'][$k] . '</a>', array_keys($todo))) ?></span></div>
  <?php endif; ?>

  <nav class="tabs">
    <?php foreach ($tabs as $k => [$label, $icon]): ?>
      <a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'is-on' : '' ?>"><i class="fa-solid <?= $icon ?>"></i> <?= $label ?><?= isset($todo[$k]) ? ' <span class="dot-todo"></span>' : '' ?></a>
    <?php endforeach; ?>
  </nav>

  <section class="card">
  <?php if ($tab === 'business'): ?>
    <div class="card__head"><div><h2>Your business</h2><p>This appears at the top of every invoice and quote.</p></div></div>
    <form method="POST" class="card__body"><?= csrfField() ?><input type="hidden" name="form" value="business">
      <div class="form-grid">
        <div class="field full"><label>Business name *</label><input name="company_name" value="<?= e($user['company_name']) ?>" placeholder="e.g. Mokoena Plumbing" required></div>
        <div class="field"><label>Your name</label><input name="full_name" value="<?= e($user['full_name']) ?>" placeholder="Thandi Mokoena"></div>
        <div class="field"><label>Phone / WhatsApp</label><input name="phone" value="<?= e($user['phone']) ?>" placeholder="082 123 4567"></div>
        <div class="field full"><label>Address</label><input name="address" value="<?= e($user['address']) ?>" placeholder="12 Vilakazi Street, Soweto, 1804"></div>
        <div class="field"><label>Website <span class="hint" style="margin:0">optional</span></label><input name="website" value="<?= e($user['website']) ?>" placeholder="mybusiness.co.za"></div>
        <div class="field"><label>VAT / tax number <span class="hint" style="margin:0">optional</span></label><input name="tax_number" value="<?= e($user['tax_number']) ?>"></div>
      </div>
      <div class="form-foot"><button class="btn btn-primary">Save</button></div>
    </form>

  <?php elseif ($tab === 'logo'): ?>
    <div class="card__head"><div><h2>Your logo</h2><p>Shown at the top of your invoices and quotes.</p></div></div>
    <div class="card__body">
      <?php if ($hasLogo): ?>
        <div class="cur-logo"><img src="uploads/logos/<?= e(basename(strtr($user['logo_path'], '\\', '/'))) ?>" alt="Your logo"><span>Current logo</span>
          <form method="POST" style="margin-left:auto"><?= csrfField() ?><input type="hidden" name="form" value="logo_remove"><button class="btn-xs danger" onclick="return confirm('Remove your logo?')"><i class="fa-solid fa-trash"></i> Remove</button></form></div>
      <?php else: ?>
        <p class="hint" style="margin:0 0 1rem">No logo yet — invoices show your business name instead.</p>
      <?php endif; ?>
      <form method="POST" enctype="multipart/form-data" class="upload-row"><?= csrfField() ?><input type="hidden" name="form" value="logo_upload">
        <label class="btn btn-primary"><i class="fa-solid fa-upload"></i> <?= $hasLogo ? 'Upload a new logo' : 'Upload a logo' ?><input type="file" name="logo" accept="image/png,image/jpeg,image/webp" hidden onchange="this.form.submit()"></label>
        <a class="btn btn-ghost" href="logo.php"><i class="fa-solid fa-palette"></i> Make one free</a>
      </form>
      <p class="hint">PNG with a transparent background looks best. Max 5 MB.</p>
    </div>

  <?php elseif ($tab === 'banking'): $curBank = $bf['Bank Name'] ?? ''; ?>
    <div class="card__head"><div><h2>How clients pay you</h2><p>Shown in a “How to pay” box on every invoice, with the invoice number as the payment reference.</p></div></div>
    <form method="POST" class="card__body"><?= csrfField() ?><input type="hidden" name="form" value="banking">
      <div class="form-grid">
        <div class="field full"><label>Account holder</label><input name="holder" value="<?= e($bf['Account Holder'] ?? ($user['company_name'] ?: '')) ?>" placeholder="Mokoena Plumbing (Pty) Ltd"></div>
        <div class="field"><label>Bank</label><select name="bank_name" id="bank">
          <option value="">Choose…</option>
          <?php foreach (SA_BANKS as $b => $code): ?><option value="<?= e($b) ?>" data-code="<?= $code ?>" <?= $curBank === $b ? 'selected' : '' ?>><?= e($b) ?></option><?php endforeach; ?>
          <option value="Other" <?= $curBank && !isset(SA_BANKS[$curBank]) ? 'selected' : '' ?>>Other</option></select>
          <input name="bank_other" id="bankOther" placeholder="Bank name" value="<?= e($curBank && !isset(SA_BANKS[$curBank]) ? $curBank : '') ?>" style="margin-top:.4rem;<?= $curBank && !isset(SA_BANKS[$curBank]) ? '' : 'display:none' ?>"></div>
        <div class="field"><label>Account number</label><input name="account" inputmode="numeric" value="<?= e($bf['Account Number'] ?? '') ?>"></div>
        <div class="field"><label>Branch code</label><input name="branch" id="branch" inputmode="numeric" value="<?= e($bf['Branch Code'] ?? '') ?>"><div class="hint">Filled in for you with the bank's universal code.</div></div>
        <div class="field"><label>Account type</label><select name="type"><?php foreach (['', 'Cheque / Current', 'Savings', 'Business'] as $ty): ?><option <?= ($bf['Account Type'] ?? '') === $ty ? 'selected' : '' ?>><?= $ty ?></option><?php endforeach; ?></select></div>
        <div class="field full"><label>Payment terms</label><select name="payment_terms"><?php foreach (PAYMENT_TERMS as $pt): ?><option <?= ($settings['payment_terms'] ?? '') === $pt ? 'selected' : '' ?>><?= $pt ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="form-foot"><button class="btn btn-primary">Save</button></div>
    </form>

  <?php elseif ($tab === 'invoices'): ?>
    <div class="card__head"><div><h2>Invoice look & defaults</h2><p>Used for every new invoice and quote. You can still change them per document in the chat.</p></div></div>
    <form method="POST" class="card__body"><?= csrfField() ?><input type="hidden" name="form" value="invoices">
      <div class="field"><label>Brand colour</label>
        <div class="chips">
          <?php foreach (array_unique(array_merge([$accent], LOGO_COLORS)) as $c): ?>
            <label class="swatch-r"><input type="radio" name="accent_color" value="<?= e($c) ?>" <?= strtolower($c) === strtolower($accent) ? 'checked' : '' ?>><span style="background:<?= e($c) ?>"></span></label>
          <?php endforeach; ?>
          <label class="swatch-r" title="Custom"><input type="radio" name="accent_color" id="customR" value="<?= e($accent) ?>"><input type="color" value="<?= e($accent) ?>" oninput="var r=document.getElementById('customR');r.value=this.value;r.checked=true" style="width:40px;height:40px;border:0;padding:0;background:none"></label>
        </div>
      </div>
      <div class="field"><label>Template</label>
        <div class="tpl-grid">
          <?php foreach (TEMPLATES as $k => [$label, $desc]): ?>
            <label class="tpl"><input type="radio" name="template" value="<?= $k ?>" <?= ($settings['template'] ?? 'classic') === $k ? 'checked' : '' ?>>
              <span class="tpl__mini tpl__mini--<?= $k ?>"><i></i><b></b><b></b><b></b><em></em></span><strong><?= $label ?></strong><small><?= $desc ?></small></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="form-grid">
        <div class="field"><label>Default VAT / tax %</label><select name="tax_rate"><?php foreach ([0 => 'No VAT (not registered)', 15 => '15% VAT'] as $v => $l): ?><option value="<?= $v ?>" <?= (float) $settings['tax_rate'] === (float) $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Currency</label><select name="currency"><?php foreach (CURRENCIES as $cu): ?><option <?= ($settings['currency'] ?? 'ZAR') === $cu ? 'selected' : '' ?>><?= $cu ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Default discount %</label><input type="number" name="discount" min="0" max="100" step="0.5" value="<?= e((string) (float) ($settings['discount'] ?? 0)) ?>"></div>
        <div class="field"></div>
        <div class="field"><label>Invoice number starts with</label><input name="invoice_prefix" maxlength="8" value="<?= e($settings['invoice_prefix'] ?? 'INV') ?>" placeholder="INV"><div class="hint">e.g. <span data-eg="invoice_prefix"><?= e($settings['invoice_prefix'] ?? 'INV') ?></span>-<?= date('Y') ?>-00001</div></div>
        <div class="field"><label>Quote number starts with</label><input name="quote_prefix" maxlength="8" value="<?= e($settings['quote_prefix'] ?? 'QUO') ?>" placeholder="QUO"><div class="hint">e.g. <span data-eg="quote_prefix"><?= e($settings['quote_prefix'] ?? 'QUO') ?></span>-<?= date('Y') ?>-00001</div></div>
        <div class="field full"><label>Standard note on every document <span class="hint" style="margin:0">optional</span></label>
          <textarea name="default_notes" rows="3" maxlength="1000" placeholder="e.g. Thank you for your business! Work is guaranteed for 3 months."><?= e($settings['default_notes'] ?? '') ?></textarea></div>
      </div>
      <div class="form-foot"><button class="btn btn-primary">Save</button></div>
    </form>

  <?php elseif ($tab === 'products'): $products = userProducts($uid); ?>
    <div class="card__head"><div><h2>Products & services</h2><p>Save what you sell with its price. In the chat you can tap them instead of typing, and the AI assistant uses your prices.</p></div>
      <form method="POST"><?= csrfField() ?><input type="hidden" name="form" value="product_import"><button class="btn btn-ghost"><i class="fa-solid fa-wand-magic-sparkles"></i> Add from past invoices</button></form></div>
    <div class="card__body">
      <form method="POST" class="prod-row prod-row--new"><?= csrfField() ?><input type="hidden" name="form" value="product_save">
        <input class="input" name="name" placeholder="Name, e.g. Call-out fee" required maxlength="200">
        <input class="input" name="price" placeholder="Price (R)" inputmode="decimal" required>
        <input class="input" name="description" placeholder="Short description (optional)" maxlength="500">
        <button class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add</button>
      </form>
      <?php if (!$products): ?>
        <div class="empty"><i class="fa-solid fa-tags"></i>No saved products yet.</div>
      <?php endif; ?>
      <?php foreach ($products as $p): ?>
        <form method="POST" class="prod-row"><?= csrfField() ?><input type="hidden" name="form" value="product_save"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <input class="input" name="name" value="<?= e($p['name']) ?>" required maxlength="200" aria-label="Name">
          <input class="input" name="price" value="<?= e(number_format((float) $p['unit_price'], 2, '.', '')) ?>" inputmode="decimal" required aria-label="Price">
          <input class="input" name="description" value="<?= e($p['description']) ?>" placeholder="Description" maxlength="500" aria-label="Description">
          <span class="prod-row__actions">
            <button class="btn-xs" title="Save"><i class="fa-solid fa-check"></i></button>
            <button class="btn-xs danger" name="form" value="product_delete" title="Remove" onclick="return confirm('Remove <?= e(addslashes($p['name'])) ?>?')"><i class="fa-solid fa-trash"></i></button>
          </span>
        </form>
      <?php endforeach; ?>
    </div>

  <?php else: $hasPw = !empty($user['password_hash']); ?>
    <div class="card__head"><div><h2>Your account</h2><p>Signed in as <strong><?= e($user['email']) ?></strong><?= !empty($user['google_id']) ? ' · Google sign-in linked' : '' ?></p></div></div>
    <div class="card__body">
      <form method="POST" style="max-width:420px"><?= csrfField() ?><input type="hidden" name="form" value="password">
        <h3 style="font-size:.95rem;font-family:var(--font);margin:0 0 .6rem"><?= $hasPw ? 'Change password' : 'Set a password' ?></h3>
        <?php if (!$hasPw): ?><p class="hint" style="margin:0 0 .6rem">You sign in with Google. Set a password to also sign in with your email.</p><?php endif; ?>
        <?php if ($hasPw): ?><div class="field"><label>Current password</label><input type="password" name="current_password" autocomplete="current-password" required></div><?php endif; ?>
        <div class="field"><label>New password</label><input type="password" name="new_password" autocomplete="new-password" minlength="8" required><div class="hint">At least 8 characters, with a letter and a number.</div></div>
        <div class="field"><label>Confirm new password</label><input type="password" name="confirm_password" autocomplete="new-password" minlength="8" required></div>
        <div class="form-foot"><button class="btn btn-primary">Save password</button></div>
      </form>
      <div class="divider"><span>more</span></div>
      <div class="acct-links">
        <a class="btn btn-ghost" href="billing.php"><i class="fa-solid fa-gem"></i> Plan & credits — <?= e(usageBadge($user)) ?></a>
        <a class="btn btn-ghost" href="dashboard.php?tab=clients"><i class="fa-solid fa-address-book"></i> My clients</a>
        <a class="btn btn-ghost" href="logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
      </div>
    </div>
  <?php endif; ?>
  </section>
</main>
<style>
  .tabs .dot-todo { display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: var(--coral); vertical-align: middle; }
  .prod-row { display: grid; grid-template-columns: 1.3fr .6fr 1.6fr auto; gap: .5rem; align-items: center; padding: .45rem 0; border-bottom: 1px solid var(--line); }
  .prod-row--new { padding-bottom: 1rem; margin-bottom: .4rem; }
  .prod-row__actions { display: flex; gap: .3rem; }
  .acct-links { display: flex; flex-wrap: wrap; gap: .5rem; }
  @media (max-width: 640px) { .prod-row { grid-template-columns: 1fr 1fr; } .prod-row input[name=description] { grid-column: 1 / -1; } }
</style>
<script>
(function () {
  var bank = document.getElementById('bank');
  if (bank) bank.addEventListener('change', function () {
    var o = bank.options[bank.selectedIndex];
    document.getElementById('bankOther').style.display = bank.value === 'Other' ? '' : 'none';
    if (o.dataset.code) document.getElementById('branch').value = o.dataset.code;
  });
  document.querySelectorAll('[data-eg]').forEach(function (s) {
    var i = document.querySelector('input[name=' + s.dataset.eg + ']');
    i.addEventListener('input', function () { s.textContent = (i.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase() || i.placeholder).slice(0, 8); });
  });
})();
</script>
</body>
</html>
