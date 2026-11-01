<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/billing.php';
require_once __DIR__ . '/includes/layout.php';

startAppSession();
requireLogin();
$user = getCurrentUser();
if (!$user) redirect('login.php');

$db       = getDB();
$packages = billingPackages();
$methods  = array_filter(paymentMethods(), fn($m) => $m['on']);
$bank     = ownerBankDetails();
$ref      = eftReference($user);
$error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pkgKey = (string) ($_POST['package'] ?? '');
    $method = (string) ($_POST['method'] ?? '');
    $pin    = preg_replace('/\D/', '', (string) ($_POST[$method . '_pin'] ?? ''));
    $amount = (float) str_replace([',', 'R', ' '], ['.', '', ''], (string) ($_POST[$method . '_amount'] ?? ''));
    $hasProof = !empty($_FILES['proof']['tmp_name']) && is_uploaded_file($_FILES['proof']['tmp_name']);
    $pending = $db->prepare("SELECT COUNT(*) FROM payments WHERE user_id = ? AND status = 'pending'");
    $pending->execute([$user['id']]);

    if (!csrfValid()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!isset($packages[$pkgKey])) {
        $error = 'Choose what you\'d like to buy.';
    } elseif (!isset($methods[$method])) {
        $error = 'Choose how you\'d like to pay.';
    } elseif ($method === 'ott' && (strlen($pin) < 10 || strlen($pin) > 20)) {
        $error = 'Enter the voucher PIN exactly as printed on your OTT slip (digits only).';
    } elseif ($method === '1voucher' && strlen($pin) !== 16) {
        $error = 'A 1Voucher PIN has 16 digits. Type it exactly as printed on the slip.';
    } elseif ($amount < $packages[$pkgKey]['price']) {
        $error = ($method === 'eft' ? 'That deposit is' : 'That voucher is') . ' for less than R' . $packages[$pkgKey]['price'] . '. Pay at least the package price.';
    } elseif ($method === 'eft' && !$hasProof) {
        $error = 'Please upload your proof of payment (a screenshot or PDF from your banking app).';
    } elseif ((int) $pending->fetchColumn() >= 3) {
        $error = 'You already have 3 payments waiting for approval. Please wait for us to check them.';
    } else {
        $ready = true;
        $proof = null;
        if ($hasProof) {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['proof']['tmp_name']);
            $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime] ?? null;
            if ($ext && $_FILES['proof']['size'] <= 6 * 1024 * 1024) {
                $dir = BASE_DIR . '/uploads/payments/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $proof = 'slip_' . $user['id'] . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                move_uploaded_file($_FILES['proof']['tmp_name'], $dir . $proof);
            } elseif ($method === 'eft') {
                $ready = false;
                $error = 'Proof of payment must be a JPG, PNG, WEBP or PDF of up to 6 MB.';
            }
        }
    }
    if (!empty($ready)) {
        $eft = $method === 'eft';
        try {
            $db->prepare('INSERT INTO payments (user_id, package, amount, method, reference, voucher_pin, voucher_amount, proof_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
               ->execute([$user['id'], $pkgKey, $packages[$pkgKey]['price'], $method, $eft ? $ref : null, $eft ? null : $pin, $amount, $proof]);
            $pid = (int) $db->lastInsertId();
            sendMail(get_setting('signup_notify_email', ADMIN_EMAIL), methodLabel($method) . ' to verify: R' . number_format($amount, 2) . ' from ' . $user['email'],
                emailLayout('New ' . methodLabel($method) . ' payment', emailRows([
                    'Customer' => ($user['full_name'] ?: $user['company_name'] ?: '—') . ' · ' . $user['email'],
                    'Package'  => $packages[$pkgKey]['label'] . ' (R' . $packages[$pkgKey]['price'] . ')',
                    ($eft ? 'Amount paid' : 'Voucher amount') => 'R' . number_format($amount, 2),
                    ($eft ? 'Reference' : 'Voucher PIN') => $eft ? $ref : chunk_split($pin, 4, ' '),
                    ($eft ? 'Proof of payment' : 'Slip') => $proof ? 'Attached in admin' : 'Not uploaded',
                ]) . '<p>' . ($eft ? 'Check that the money with reference <strong>' . e($ref) . '</strong> is in your bank account' : 'Redeem the PIN in your ' . e(methodLabel($method)) . ' merchant portal') . ', then approve it.</p>',
                'Review payment', APP_URL . '/admin/payments.php?id=' . $pid));
            flash('success', $eft
                ? 'Thanks! We\'ll check your deposit and email you as soon as it\'s approved. Payments from other banks can take 1–2 business days to show.'
                : 'Thanks! Your voucher was sent for checking. You\'ll get an email as soon as it\'s approved — usually within a few hours during business hours.');
            redirect('billing.php');
        } catch (PDOException $e) {
            $error = $e->getCode() === '23000' ? 'That voucher PIN has already been submitted.' : 'Could not save your payment. Please try again.';
        }
    }
}

$u       = usageFor($user);
$history = $db->prepare('SELECT * FROM payments WHERE user_id = ? ORDER BY id DESC LIMIT 10');
$history->execute([$user['id']]);
$history = $history->fetchAll();
$pick    = (string) ($_POST['package'] ?? $_GET['package'] ?? 'credits_1');
$how     = (string) ($_POST['method'] ?? array_key_first($methods) ?? '');
$pct     = $u['allowance'] ? min(100, round($u['used'] / $u['allowance'] * 100)) : 100;
$old     = fn(string $k) => e($_POST[$k] ?? '');

pageHead('Plan & credits – ' . APP_NAME, ['css' => ['style.css', 'admin.css']]);
?>
<body>
<header class="site-nav"><div class="wrap"><?= brandMark() ?><nav><a href="chat.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back to chat</a></nav></div></header>
<main class="wrap" style="padding:2rem 0 4rem;max-width:1080px">
  <div class="adm-head"><div><h1>Plan & credits</h1><p>Pay by bank deposit, or with an OTT voucher or 1Voucher from any shop — no card needed.</p></div></div>
  <?= flashHtml() ?>
  <?php if ($error): ?><div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($error) ?></span></div><?php endif; ?>

  <div class="kpis" style="grid-template-columns:repeat(3,1fr)">
    <div class="kpi"><div class="kpi__ico"><i class="fa-solid fa-gem"></i></div><div><div class="kpi__label">Your plan</div><div class="kpi__value"><?= $u['plan'] ?></div>
      <div class="kpi__delta"><?= $u['pro'] ? 'Active until ' . date('j M Y', strtotime($u['expires'])) : 'Upgrade for R' . billingConfig()['pro_price'] . '/month' ?></div></div></div>
    <div class="kpi kpi--violet"><div class="kpi__ico"><i class="fa-solid fa-file-invoice"></i></div><div style="flex:1"><div class="kpi__label">This month</div><div class="kpi__value"><?= $u['left'] ?> <small style="font-size:.9rem;color:var(--text-3)">of <?= $u['allowance'] ?> left</small></div>
      <div class="strength" style="margin-top:.4rem"><span style="width:<?= $pct ?>%;background:var(--violet)"></span></div><div class="kpi__delta">Resets <?= $u['renews'] ?></div></div></div>
    <div class="kpi kpi--mint"><div class="kpi__ico"><i class="fa-solid fa-coins"></i></div><div><div class="kpi__label">Credits</div><div class="kpi__value"><?= $u['credits'] ?></div><div class="kpi__delta">1 credit = 1 invoice or quote · never expire</div></div></div>
  </div>

  <div class="grid-2">
    <section class="card">
      <div class="card__head"><div><h2>1. Choose what to buy</h2><p>Credits are used only after your monthly documents run out.</p></div></div>
      <form method="POST" enctype="multipart/form-data" class="card__body" data-busy>
        <?= csrfField() ?>
        <div style="display:grid;gap:.6rem;margin-bottom:1.2rem">
          <?php foreach ($packages as $key => $p): ?>
            <label class="chip-check" style="border-radius:var(--r);justify-content:space-between;padding:.85rem 1rem">
              <span style="display:flex;gap:.7rem;align-items:center"><input type="radio" name="package" value="<?= $key ?>" data-price="<?= $p['price'] ?>" <?= $pick === $key ? 'checked' : '' ?>>
                <span><strong><?= e($p['label']) ?></strong><br><small style="color:var(--text-3);font-weight:600"><?= e($p['note']) ?></small></span></span>
              <strong style="font-size:1.1rem">R<?= $p['price'] ?></strong>
            </label>
          <?php endforeach; ?>
        </div>

        <h2 style="font-size:1.02rem;font-family:var(--font);margin-bottom:.8rem">2. Choose how to pay</h2>
        <?php if (!$methods): ?>
          <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i><span>Payments are switched off right now. Please contact us.</span></div>
        <?php else: ?>
        <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem">
          <?php foreach ($methods as $k => $m): ?>
            <label class="chip-check"><input type="radio" name="method" value="<?= $k ?>" <?= $how === $k ? 'checked' : '' ?>><i class="fa-solid <?= $m['icon'] ?>"></i> <?= e($m['label']) ?></label>
          <?php endforeach; ?>
        </div>

        <?php if (isset($methods['eft'])): ?>
        <div data-pay="eft">
          <p class="hint" style="margin:0 0 .6rem">Pay into this account from your banking app, ATM or at the bank, then upload the proof.</p>
          <div class="list-row" style="display:block;padding:.8rem 1rem;border:1.5px solid var(--line);border-radius:var(--r);margin-bottom:1rem">
            <?php foreach (['Account holder' => $bank['holder'], 'Bank' => $bank['bank'], 'Account number' => $bank['account'], 'Branch code' => $bank['branch'], 'Account type' => $bank['type']] as $l => $v): if ($v === '') continue; ?>
              <div style="display:flex;justify-content:space-between;gap:1rem;padding:.2rem 0"><span class="muted"><?= $l ?></span><strong><?= e($v) ?></strong></div>
            <?php endforeach; ?>
            <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:.4rem 0 0"><span class="muted">Reference <small>(always use this)</small></span>
              <span class="copy-box" style="margin:0;display:inline-flex"><code><?= e($ref) ?></code><button type="button" class="btn-xs" data-copy="<?= e($ref) ?>"><i class="fa-regular fa-copy"></i></button></span></div>
            <div style="display:flex;justify-content:space-between;gap:1rem;padding:.2rem 0"><span class="muted">Amount</span><strong data-amount>R<?= $packages[$pick]['price'] ?? '' ?></strong></div>
          </div>
          <div class="form-grid">
            <div class="field"><label>Amount you paid (R)</label><input name="eft_amount" inputmode="decimal" placeholder="10" value="<?= $old('eft_amount') ?>"></div>
            <div class="field"><label>Proof of payment</label><input type="file" name="proof" accept="image/*,application/pdf"><div class="hint">Screenshot or PDF from your bank</div></div>
          </div>
        </div>
        <?php endif; ?>

        <?php foreach (['ott' => ['e.g. 1234 5678 9012', 'At most supermarkets and spaza shops with a Kazang or Flash device, or in your banking app under Vouchers.'],
                        '1voucher' => ['16 digits, e.g. 1234 5678 9012 3456', 'At Shoprite, Checkers, Pick n Pay, Spar, Boxer and many spaza shops, or in your banking app.']] as $k => [$ph, $where]):
            if (!isset($methods[$k])) continue; ?>
        <div data-pay="<?= $k ?>">
          <p class="hint" style="margin:0 0 .6rem"><strong>Where to buy:</strong> <?= $where ?> Buy one for the exact package price.</p>
          <div class="form-grid">
            <div class="field"><label>Voucher PIN</label><input name="<?= $k ?>_pin" inputmode="numeric" autocomplete="off" placeholder="<?= $ph ?>" value="<?= $old($k . '_pin') ?>"></div>
            <div class="field"><label>Voucher amount (R)</label><input name="<?= $k ?>_amount" inputmode="decimal" placeholder="10" value="<?= $old($k . '_amount') ?>"></div>
            <div class="field full"><label>Photo of the slip <span class="hint" style="margin:0">optional, speeds up approval</span></label><input type="file" name="proof" accept="image/*,application/pdf"></div>
          </div>
        </div>
        <?php endforeach; ?>

        <button class="btn btn-primary btn-block btn-lg"><i class="fa-solid fa-paper-plane"></i> Submit payment</button>
        <p class="hint" style="margin-top:.7rem">We check every payment by hand and add your credits or Pro as soon as it's verified. You'll get an email.</p>
        <?php endif; ?>
      </form>
    </section>
    <section class="card">
      <div class="card__head"><h2>How it works</h2></div>
      <div class="card__body">
        <ol class="steps-list">
          <li>Choose a package and how you want to pay.</li>
          <li><strong>Bank deposit:</strong> pay into our account with reference <strong><?= e($ref) ?></strong> and upload the proof. <strong>Voucher:</strong> type the PIN from your OTT or 1Voucher slip.</li>
          <li>We check it and switch on your credits or Pro. You'll get an email. Keep your slip until then.</li>
        </ol>
        <div class="alert alert-info"><i class="fa-solid fa-shield-halved"></i><span>Never share your voucher PIN with anyone else. We will never ask for it by phone or WhatsApp.</span></div>
        <h3 style="font-size:.9rem;font-family:var(--font);margin:1rem 0 .5rem">Your payments</h3>
        <?php if (!$history): ?><p class="hint">No payments yet.</p><?php endif; ?>
        <?php foreach ($history as $h): ?>
          <div class="list-row" style="padding:.55rem 0">
            <div class="list-row__main"><strong><?= e($packages[$h['package']]['label'] ?? $h['package']) ?> · R<?= number_format((float) $h['amount'], 0) ?></strong>
              <small><?= date('j M Y H:i', strtotime($h['created_at'])) ?> · <?= e(paymentMethods()[$h['method']]['short'] ?? $h['method']) ?> <?= $h['method'] === 'eft' ? e((string) $h['reference']) : 'PIN …' . e(substr((string) $h['voucher_pin'], -4)) ?><?= $h['status'] === 'rejected' && $h['admin_note'] ? ' · ' . e($h['admin_note']) : '' ?></small></div>
            <span class="tag <?= ['approved' => 'tag-on', 'rejected' => 'tag-off'][$h['status']] ?? 'tag-google' ?>"><?= e($h['status']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>
</main>
<script>
document.querySelectorAll('form[data-busy]').forEach(function(f){f.addEventListener('submit',function(){var b=f.querySelector('[type=submit],button.btn-primary');if(b){b.disabled=true;b.classList.add('is-busy');}});});
// Show only the chosen payment method; hidden file inputs are disabled so the right "proof" is sent.
(function(){
  function sync(){
    var m=document.querySelector('input[name=method]:checked'); m=m?m.value:'';
    document.querySelectorAll('[data-pay]').forEach(function(p){var on=p.dataset.pay===m;p.hidden=!on;p.querySelectorAll('input').forEach(function(i){i.disabled=!on;});});
    var pk=document.querySelector('input[name=package]:checked'), a=document.querySelector('[data-amount]');
    if(pk&&a)a.textContent='R'+pk.dataset.price;
  }
  document.querySelectorAll('input[name=method],input[name=package]').forEach(function(i){i.addEventListener('change',sync);});
  sync();
  document.querySelectorAll('[data-copy]').forEach(function(b){b.addEventListener('click',function(){navigator.clipboard&&navigator.clipboard.writeText(b.dataset.copy);b.innerHTML='<i class="fa-solid fa-check"></i>';});});
})();
</script>
</body>
</html>
