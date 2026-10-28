<?php
declare(strict_types=1);

// Business setup wizard: 1 business details → 2 logo (upload or generate)
// → 3 banking details → 4 colours & template. New users land here after
// signing up; anyone can come back from the dashboard. Every step can be skipped.
require_once __DIR__ . '/includes/billing.php';
require_once __DIR__ . '/includes/logos.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/vendor/autoload.php';

startAppSession();
requireLogin();
$user = getCurrentUser();
if (!$user) redirect('login.php');
$uid  = (int) $user['id'];
$pm   = new \InvoiceChat\ProfileManager(getDB());
$step = max(1, min(4, (int) ($_GET['step'] ?? 1)));

const SA_BANKS = [
    'ABSA Bank' => '632005', 'African Bank' => '430000', 'Bank Zero' => '888000', 'Bidvest Bank' => '462005',
    'Capitec Bank' => '470010', 'Discovery Bank' => '679000', 'First National Bank (FNB)' => '250655', 'Investec Bank' => '580105',
    'Nedbank' => '198765', 'Old Mutual' => '462005', 'Sasfin Bank' => '683000', 'Standard Bank' => '051001', 'TymeBank' => '678910',
];
const TEMPLATES = [
    'classic'    => ['Classic', 'White and clean, logo left, colour rule'],
    'modern'     => ['Modern', 'Bold colour header band'],
    'freelancer' => ['Freelancer', 'Minimal, big "amount due" card'],
    'corporate'  => ['Corporate', 'Dark header, boxed details'],
    'banded'     => ['Banded', 'Colour side band, tinted boxes'],
];

/** "Account Holder: X\nBank Name: Y" → ['Account Holder' => 'X', ...] */
function bankFields(?string $text): array
{
    $out = [];
    foreach (preg_split('/\R/', (string) $text) as $line) {
        if (preg_match('/^\s*([^:]+):\s*(.+)$/', $line, $m)) $out[trim($m[1])] = trim($m[2]);
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $t = fn(string $k, int $len = 255) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $len);

    if ($step === 1) {
        if ($t('company_name') === '') { flash('error', 'Please add your business name — it goes at the top of every invoice.'); redirect('setup.php?step=1'); }
        $pm->update($uid, ['company_name' => $t('company_name'), 'address' => $t('address', 500), 'phone' => $t('phone', 50), 'website' => $t('website'), 'tax_number' => $t('tax_number', 100)]);
        if ($t('full_name') !== '') getDB()->prepare('UPDATE users SET full_name = ? WHERE id = ?')->execute([$t('full_name', 150), $uid]);
    } elseif ($step === 2 && !empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['logo']['tmp_name']);
        $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? null;
        if (!$ext || $_FILES['logo']['size'] > MAX_LOGO_SIZE) { flash('error', 'Upload a JPG, PNG or WebP image up to 5 MB.'); redirect('setup.php?step=2'); }
        saveUserLogo($uid, (string) file_get_contents($_FILES['logo']['tmp_name']), $ext);
        flash('success', 'Logo uploaded.');
        redirect('setup.php?step=2');
    } elseif ($step === 3) {
        $bank   = $t('bank_name', 100) === 'Other' ? $t('bank_other', 100) : $t('bank_name', 100);
        $parts  = array_filter([
            'Account Holder' => $t('holder', 150), 'Bank Name' => $bank, 'Account Number' => preg_replace('/[^\d ]/', '', $t('account', 30)),
            'Branch Code' => preg_replace('/\D/', '', $t('branch', 10)), 'Account Type' => $t('type', 30),
        ]);
        $pm->update($uid, ['bank_details' => implode("\n", array_map(fn($k, $v) => "{$k}: {$v}", array_keys($parts), $parts)) ?: null]);
        if ($t('payment_terms') !== '') $pm->updateSettings($uid, ['payment_terms' => $t('payment_terms', 100)]);
    } elseif ($step === 4) {
        $pm->updateSettings($uid, [
            'accent_color' => '#' . hexClean($t('accent_color', 7) ?: '#3b5bfd'),
            'template'     => isset(TEMPLATES[$_POST['template'] ?? '']) ? $_POST['template'] : 'classic',
            'tax_rate'     => max(0, min(100, (float) ($_POST['tax_rate'] ?? 0))),
            'currency'     => preg_match('/^[A-Z]{3}$/', strtoupper($t('currency', 3))) ? strtoupper($t('currency', 3)) : 'ZAR',
        ]);
        $_SESSION['bot_state'] = 'idle';
        flash('success', 'All set! Type /new or describe your invoice to create the first one.');
        redirect('chat.php');
    }
    redirect('setup.php?step=' . ($step + 1));
}

$user     = freshUser($uid);
$settings = $pm->getSettings($uid);
$accent   = accentColor($user);
$bf       = bankFields($user['bank_details'] ?? '');
$hasLogo  = !empty($user['logo_path']) && is_file($user['logo_path']);
$steps    = [1 => 'Business', 2 => 'Logo', 3 => 'Banking', 4 => 'Look'];

pageHead('Set up your business – ' . APP_NAME, ['css' => ['style.css', 'admin.css']]);
?>
<body>
<header class="site-nav"><div class="wrap"><?= brandMark() ?><nav><a href="chat.php">Skip for now</a></nav></div></header>
<main class="wrap" style="padding:2rem 0 4rem;max-width:760px">
  <ol class="wizard">
    <?php foreach ($steps as $n => $label): ?>
      <li class="<?= $n < $step ? 'done' : ($n === $step ? 'on' : '') ?>"><a href="?step=<?= $n ?>"><span><?= $n < $step ? '<i class="fa-solid fa-check"></i>' : $n ?></span><?= $label ?></a></li>
    <?php endforeach; ?>
  </ol>
  <?= flashHtml() ?>

  <section class="card">
  <?php if ($step === 1): ?>
    <div class="card__head"><div><h2>Your business</h2><p>This appears at the top of every invoice and quote.</p></div></div>
    <form method="POST" class="card__body"><?= csrfField() ?>
      <div class="form-grid">
        <div class="field full"><label>Business name *</label><input name="company_name" value="<?= e($user['company_name']) ?>" placeholder="e.g. Mokoena Plumbing" required autofocus></div>
        <div class="field"><label>Your name</label><input name="full_name" value="<?= e($user['full_name']) ?>" placeholder="Thandi Mokoena"></div>
        <div class="field"><label>Phone / WhatsApp</label><input name="phone" value="<?= e($user['phone']) ?>" placeholder="082 123 4567"></div>
        <div class="field full"><label>Address</label><input name="address" value="<?= e($user['address']) ?>" placeholder="12 Vilakazi Street, Soweto, 1804"></div>
        <div class="field"><label>Website <span class="hint" style="margin:0">optional</span></label><input name="website" value="<?= e($user['website']) ?>" placeholder="mybusiness.co.za"></div>
        <div class="field"><label>VAT / tax number <span class="hint" style="margin:0">optional</span></label><input name="tax_number" value="<?= e($user['tax_number']) ?>"></div>
      </div>
      <p class="hint">Invoices are sent from your account email: <?= e($user['email']) ?>.</p>
      <div class="form-foot"><button class="btn btn-primary">Next: logo <i class="fa-solid fa-arrow-right"></i></button></div>
    </form>

  <?php elseif ($step === 2): $name = $user['company_name'] ?: 'My Business'; ?>
    <div class="card__head"><div><h2>Your logo</h2><p>Upload yours, or make one from your initials in one tap.</p></div>
      <?php if ($hasLogo): ?><span class="tag tag-on"><i class="fa-solid fa-check"></i> Logo saved</span><?php endif; ?></div>
    <div class="card__body">
      <?php if ($hasLogo): ?><div class="cur-logo"><img src="uploads/logos/<?= e(basename(strtr($user['logo_path'], '\\', '/'))) ?>" alt="Your logo"><span>Current logo</span></div><?php endif; ?>
      <form method="POST" enctype="multipart/form-data" class="upload-row"><?= csrfField() ?>
        <label class="btn btn-ghost"><i class="fa-solid fa-upload"></i> Upload a logo<input type="file" name="logo" accept="image/png,image/jpeg,image/webp" hidden onchange="this.form.submit()"></label>
        <span class="hint" style="margin:0">PNG with a transparent background looks best. Max 5 MB.</span>
      </form>
      <div class="divider"><span>or generate one</span></div>
      <div class="chips" id="sw">
        <?php foreach (array_unique(array_merge([$accent], LOGO_COLORS)) as $i => $c): ?><button type="button" class="swatch<?= $i === 0 ? ' is-on' : '' ?>" data-color="<?= e($c) ?>" style="background:<?= e($c) ?>" aria-label="<?= e($c) ?>"></button><?php endforeach; ?>
      </div>
      <div class="logo-grid">
        <?php foreach (LOGO_STYLES as $s): ?><button type="button" class="logo-pick" data-style="<?= $s ?>"><img alt="<?= e($s) ?>"><span>Use this</span></button><?php endforeach; ?>
      </div>
      <p class="hint">Want more options or an AI symbol? <a href="logo.php">Open the full logo maker</a>.</p>
      <div class="form-foot"><a class="btn btn-ghost" href="?step=1"><i class="fa-solid fa-arrow-left"></i> Back</a><a class="btn btn-primary" href="?step=3">Next: banking <i class="fa-solid fa-arrow-right"></i></a></div>
    </div>

  <?php elseif ($step === 3): $curBank = $bf['Bank Name'] ?? ''; ?>
    <div class="card__head"><div><h2>How clients pay you</h2><p>Shown in a “How to pay” box on every invoice, with the invoice number as the payment reference.</p></div></div>
    <form method="POST" class="card__body"><?= csrfField() ?>
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
        <div class="field full"><label>Payment terms</label><select name="payment_terms"><?php foreach (['Due on receipt', 'Due in 7 days', 'Due in 14 days', 'Due in 30 days'] as $pt): ?><option <?= ($settings['payment_terms'] ?? '') === $pt ? 'selected' : '' ?>><?= $pt ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="form-foot"><a class="btn btn-ghost" href="?step=2"><i class="fa-solid fa-arrow-left"></i> Back</a><button class="btn btn-primary">Next: colours <i class="fa-solid fa-arrow-right"></i></button></div>
    </form>

  <?php else: ?>
    <div class="card__head"><div><h2>Colours & template</h2><p>Your colour is used for headings, the totals bar and the logo maker.</p></div></div>
    <form method="POST" class="card__body"><?= csrfField() ?>
      <div class="field"><label>Brand colour</label>
        <div class="chips">
          <?php foreach (array_unique(array_merge([$accent], LOGO_COLORS)) as $c): ?>
            <label class="swatch-r"><input type="radio" name="accent_color" value="<?= e($c) ?>" <?= strtolower($c) === strtolower($accent) ? 'checked' : '' ?>><span style="background:<?= e($c) ?>"></span></label>
          <?php endforeach; ?>
          <label class="swatch-r" title="Custom"><input type="radio" name="accent_color" id="customR" value="<?= e($accent) ?>"><input type="color" value="<?= e($accent) ?>" oninput="var r=document.getElementById('customR');r.value=this.value;r.checked=true" style="width:40px;height:40px;border:0;padding:0;background:none"></label>
        </div>
      </div>
      <div class="field"><label>Invoice template</label>
        <div class="tpl-grid">
          <?php foreach (TEMPLATES as $k => [$label, $desc]): ?>
            <label class="tpl"><input type="radio" name="template" value="<?= $k ?>" <?= ($settings['template'] ?? 'classic') === $k ? 'checked' : '' ?>>
              <span class="tpl__mini tpl__mini--<?= $k ?>"><i></i><b></b><b></b><b></b><em></em></span><strong><?= $label ?></strong><small><?= $desc ?></small></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="form-grid">
        <div class="field"><label>Default VAT / tax %</label><select name="tax_rate"><?php foreach ([0 => 'No VAT (not registered)', 15 => '15% VAT'] as $v => $l): ?><option value="<?= $v ?>" <?= (float) $settings['tax_rate'] === (float) $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Currency</label><select name="currency"><?php foreach (['ZAR', 'USD', 'EUR', 'GBP', 'BWP', 'NAD', 'NGN'] as $cu): ?><option <?= ($settings['currency'] ?? 'ZAR') === $cu ? 'selected' : '' ?>><?= $cu ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="form-foot"><a class="btn btn-ghost" href="?step=3"><i class="fa-solid fa-arrow-left"></i> Back</a><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Finish & create an invoice</button></div>
    </form>
  <?php endif; ?>
  </section>
</main>
<style>
  .wizard { list-style: none; display: grid; grid-template-columns: repeat(4, 1fr); gap: .4rem; margin-bottom: 1.2rem; }
  .wizard a { display: flex; align-items: center; gap: .5rem; padding: .6rem .7rem; border-radius: 12px; background: var(--surface); border: 1px solid var(--line); color: var(--text-3); font-weight: 700; font-size: .85rem; text-decoration: none; }
  .wizard span { width: 24px; height: 24px; border-radius: 50%; display: grid; place-items: center; background: var(--surface-2); font-size: .75rem; flex-shrink: 0; }
  .wizard .on a { border-color: var(--brand); color: var(--brand); } .wizard .on span { background: var(--brand); color: #fff; }
  .wizard .done span { background: var(--mint); color: #fff; } .wizard .done a { color: var(--text); }
  .swatch, .swatch-r span { width: 40px; height: 40px; border-radius: 12px; border: 3px solid var(--surface); box-shadow: 0 0 0 1px var(--line); cursor: pointer; display: block; }
  .swatch.is-on, .swatch-r input:checked + span { box-shadow: 0 0 0 3px var(--text); }
  .swatch-r input[type=radio] { position: absolute; opacity: 0; }
  .swatch-r { position: relative; cursor: pointer; }
  .logo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: .6rem; margin-top: .8rem; }
  .logo-pick { border: 2px solid var(--line); border-radius: 16px; background: var(--surface); padding: .6rem; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: .3rem; font-weight: 700; font-size: .75rem; color: var(--brand); }
  .logo-pick:hover { border-color: var(--brand); } .logo-pick img { width: 100%; aspect-ratio: 1; object-fit: contain; }
  .logo-pick.saved { border-color: var(--mint); color: var(--mint); }
  .upload-row { display: flex; align-items: center; gap: .8rem; flex-wrap: wrap; }
  .cur-logo { display: flex; align-items: center; gap: .8rem; margin-bottom: 1rem; font-size: .85rem; color: var(--text-3); }
  .cur-logo img { width: 64px; height: 64px; object-fit: contain; border: 1px solid var(--line); border-radius: 12px; background: #fff; }
  .tpl-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: .6rem; }
  .tpl { border: 2px solid var(--line); border-radius: 14px; padding: .7rem; cursor: pointer; display: flex; flex-direction: column; gap: .2rem; font-size: .82rem; }
  .tpl small { color: var(--text-3); font-size: .72rem; line-height: 1.3; }
  .tpl input { position: absolute; opacity: 0; } .tpl:has(input:checked) { border-color: var(--brand); box-shadow: 0 0 0 4px var(--brand-50); }
  .tpl__mini { display: block; height: 76px; border-radius: 8px; background: #fff; border: 1px solid var(--line); padding: 7px; margin-bottom: .4rem; position: relative; overflow: hidden; }
  .tpl__mini i { display: block; height: 12px; width: 40%; background: var(--brand); border-radius: 3px; margin-bottom: 7px; }
  .tpl__mini b { display: block; height: 5px; background: #e4e8f1; border-radius: 3px; margin-bottom: 4px; }
  .tpl__mini em { position: absolute; right: 7px; bottom: 7px; width: 40%; height: 10px; background: var(--brand); border-radius: 3px; }
  .tpl__mini--modern i, .tpl__mini--corporate i { width: auto; margin: -7px -7px 7px; height: 20px; border-radius: 0; }
  .tpl__mini--corporate i { background: #0e1726; }
  .tpl__mini--freelancer i { background: #0e1726; height: 6px; } .tpl__mini--freelancer em { top: 18px; bottom: auto; height: 16px; }
  .tpl__mini--banded { border-left: 7px solid var(--brand); }
  @media (max-width: 560px) { .wizard a { flex-direction: column; font-size: .72rem; gap: .25rem; } }
</style>
<script>
(function () {
  var bank = document.getElementById('bank');
  if (bank) bank.addEventListener('change', function () {
    var o = bank.options[bank.selectedIndex];
    document.getElementById('bankOther').style.display = bank.value === 'Other' ? '' : 'none';
    if (o.dataset.code) document.getElementById('branch').value = o.dataset.code;
  });
  var grid = document.querySelector('.logo-grid');
  if (!grid) return;
  var csrf = <?= json_encode(csrfToken()) ?>, name = <?= json_encode($user['company_name'] ?: 'My Business') ?>, color = <?= json_encode($accent) ?>;
  function draw() { grid.querySelectorAll('.logo-pick').forEach(function (b) { b.querySelector('img').src = 'ajax/logo.php?action=preview&style=' + b.dataset.style + '&name=' + encodeURIComponent(name) + '&color=' + encodeURIComponent(color.replace('#', '')); }); }
  document.querySelectorAll('.swatch').forEach(function (s) { s.onclick = function () { document.querySelectorAll('.swatch').forEach(function (x) { x.classList.toggle('is-on', x === s); }); color = s.dataset.color; draw(); }; });
  grid.querySelectorAll('.logo-pick').forEach(function (b) { b.onclick = function () {
    var fd = new FormData(); fd.append('action', 'save'); fd.append('style', b.dataset.style); fd.append('color', color); fd.append('name', name); fd.append('_csrf', csrf);
    b.querySelector('span').textContent = 'Saving…';
    fetch('ajax/logo.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (r) {
      grid.querySelectorAll('.logo-pick').forEach(function (x) { x.classList.remove('saved'); x.querySelector('span').textContent = 'Use this'; });
      if (r.ok) { b.classList.add('saved'); b.querySelector('span').textContent = '✓ Saved'; }
    });
  }; });
  draw();
})();
</script>
</body>
</html>
