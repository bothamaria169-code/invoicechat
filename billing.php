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
$error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pkgKey = (string) ($_POST['package'] ?? '');
    $pin    = preg_replace('/\D/', '', (string) ($_POST['voucher_pin'] ?? ''));
    $amount = (float) str_replace([',', 'R', ' '], ['.', '', ''], (string) ($_POST['voucher_amount'] ?? ''));
    $pending = $db->prepare("SELECT COUNT(*) FROM payments WHERE user_id = ? AND status = 'pending'");
    $pending->execute([$user['id']]);

    if (!csrfValid()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!isset($packages[$pkgKey])) {
        $error = 'Choose what you\'d like to buy.';
    } elseif (strlen($pin) < 10 || strlen($pin) > 20) {
        $error = 'Enter the voucher PIN exactly as printed on your OTT slip (digits only).';
    } elseif ($amount < $packages[$pkgKey]['price']) {
        $error = 'That voucher is for less than R' . $packages[$pkgKey]['price'] . '. Buy a voucher for at least the package price.';
    } elseif ((int) $pending->fetchColumn() >= 3) {
        $error = 'You already have 3 vouchers waiting for approval. Please wait for us to check them.';
    } else {
        $proof = null;
        if (!empty($_FILES['proof']['tmp_name']) && is_uploaded_file($_FILES['proof']['tmp_name'])) {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['proof']['tmp_name']);
            $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime] ?? null;
            if ($ext && $_FILES['proof']['size'] <= 6 * 1024 * 1024) {
                $dir = BASE_DIR . '/uploads/payments/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $proof = 'slip_' . $user['id'] . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                move_uploaded_file($_FILES['proof']['tmp_name'], $dir . $proof);
            }
        }
        try {
            $db->prepare('INSERT INTO payments (user_id, package, amount, method, voucher_pin, voucher_amount, proof_path) VALUES (?, ?, ?, ?, ?, ?, ?)')
               ->execute([$user['id'], $pkgKey, $packages[$pkgKey]['price'], 'ott', $pin, $amount, $proof]);
            $pid = (int) $db->lastInsertId();
            sendMail(get_setting('signup_notify_email', ADMIN_EMAIL), 'OTT voucher to verify: R' . number_format($amount, 2) . ' from ' . $user['email'],
                emailLayout('New OTT voucher payment', emailRows([
                    'Customer' => ($user['full_name'] ?: $user['company_name'] ?: '—') . ' · ' . $user['email'],
                    'Package'  => $packages[$pkgKey]['label'] . ' (R' . $packages[$pkgKey]['price'] . ')',
                    'Voucher amount' => 'R' . number_format($amount, 2),
                    'Voucher PIN'    => chunk_split($pin, 4, ' '),
                    'Slip'     => $proof ? 'Attached in admin' : 'Not uploaded',
                ]) . '<p>Redeem the PIN in your OTT merchant portal, then approve it.</p>', 'Review payment', APP_URL . '/admin/payments.php?id=' . $pid));
            flash('success', 'Thanks! Your voucher was sent for checking. You\'ll get an email as soon as it\'s approved — usually within a few hours during business hours.');
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
$pct     = $u['allowance'] ? min(100, round($u['used'] / $u['allowance'] * 100)) : 100;

pageHead('Plan & credits – ' . APP_NAME, ['css' => ['style.css', 'admin.css']]);
?>
<body>
<header class="site-nav"><div class="wrap"><?= brandMark() ?><nav><a href="chat.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back to chat</a></nav></div></header>
<main class="wrap" style="padding:2rem 0 4rem;max-width:1080px">
  <div class="adm-head"><div><h1>Plan & credits</h1><p>Pay with an OTT voucher — no card or bank account needed.</p></div></div>
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
              <span style="display:flex;gap:.7rem;align-items:center"><input type="radio" name="package" value="<?= $key ?>" <?= $pick === $key ? 'checked' : '' ?>>
                <span><strong><?= e($p['label']) ?></strong><br><small style="color:var(--text-3);font-weight:600"><?= e($p['note']) ?></small></span></span>
              <strong style="font-size:1.1rem">R<?= $p['price'] ?></strong>
            </label>
          <?php endforeach; ?>
        </div>
        <h2 style="font-size:1.02rem;font-family:var(--font);margin-bottom:.8rem">2. Enter your OTT voucher</h2>
        <div class="form-grid">
          <div class="field"><label>Voucher PIN</label><input name="voucher_pin" inputmode="numeric" autocomplete="off" placeholder="e.g. 1234 5678 9012" value="<?= e($_POST['voucher_pin'] ?? '') ?>" required></div>
          <div class="field"><label>Voucher amount (R)</label><input name="voucher_amount" inputmode="decimal" placeholder="10" value="<?= e($_POST['voucher_amount'] ?? '') ?>" required></div>
          <div class="field full"><label>Photo of the slip <span class="hint" style="margin:0">optional, speeds up approval</span></label><input type="file" name="proof" accept="image/*,application/pdf"></div>
        </div>
        <button class="btn btn-primary btn-block btn-lg"><i class="fa-solid fa-paper-plane"></i> Submit voucher</button>
        <p class="hint" style="margin-top:.7rem">We check every voucher by hand and add your credits or Pro as soon as it's verified. You'll get an email.</p>
      </form>
    </section>
    <section class="card">
      <div class="card__head"><h2>How to buy an OTT voucher</h2></div>
      <div class="card__body">
        <ol class="steps-list">
          <li>Ask for an <strong>OTT voucher</strong> at the till at most supermarkets and spaza shops with a Kazang or Flash device, or buy one in your banking app under <em>Vouchers</em>.</li>
          <li>Buy a voucher for the <strong>exact package price</strong> (R10, R50 …).</li>
          <li>Type the <strong>PIN</strong> from the slip here and submit. Keep the slip until you're approved.</li>
        </ol>
        <div class="alert alert-info"><i class="fa-solid fa-shield-halved"></i><span>Never share your voucher PIN with anyone else. We will never ask for it by phone or WhatsApp.</span></div>
        <h3 style="font-size:.9rem;font-family:var(--font);margin:1rem 0 .5rem">Your payments</h3>
        <?php if (!$history): ?><p class="hint">No payments yet.</p><?php endif; ?>
        <?php foreach ($history as $h): ?>
          <div class="list-row" style="padding:.55rem 0">
            <div class="list-row__main"><strong><?= e($packages[$h['package']]['label'] ?? $h['package']) ?> · R<?= number_format((float) $h['amount'], 0) ?></strong>
              <small><?= date('j M Y H:i', strtotime($h['created_at'])) ?> · PIN …<?= e(substr((string) $h['voucher_pin'], -4)) ?><?= $h['status'] === 'rejected' && $h['admin_note'] ? ' · ' . e($h['admin_note']) : '' ?></small></div>
            <span class="tag <?= ['approved' => 'tag-on', 'rejected' => 'tag-off'][$h['status']] ?? 'tag-google' ?>"><?= e($h['status']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>
</main>
<script>document.querySelectorAll('form[data-busy]').forEach(function(f){f.addEventListener('submit',function(){var b=f.querySelector('[type=submit],button.btn-primary');if(b){b.disabled=true;b.classList.add('is-busy');}});});</script>
</body>
</html>
