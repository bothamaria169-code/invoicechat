<?php
declare(strict_types=1);

require_once __DIR__ . '/_shell.php';
require_once BASE_DIR . '/includes/billing.php';

$db  = getDB();
$tab = in_array($_GET['tab'] ?? '', ['pending', 'done', 'pricing'], true) ? $_GET['tab'] : 'pending';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = (string) ($_POST['form'] ?? '');
    if ($form === 'review') {
        $id  = (int) ($_POST['id'] ?? 0);
        $err = ($_POST['do'] ?? '') === 'approve'
            ? approvePayment($id, (int) $ADMIN['id'], trim((string) ($_POST['note'] ?? '')))
            : rejectPayment($id, (int) $ADMIN['id'], trim((string) ($_POST['note'] ?? '')));
        flash($err === '' ? 'success' : 'error', $err ?: (($_POST['do'] ?? '') === 'approve' ? 'Approved — the customer was emailed.' : 'Rejected — the customer was emailed the reason.'));
        redirect('admin/payments.php');
    }
    if ($form === 'pricing') {
        foreach (['bill_free_docs', 'bill_pro_docs', 'bill_pro_price', 'bill_pack_price', 'bill_pack_size', 'bill_free_ai', 'bill_pro_ai', 'bill_signup_gift'] as $k) {
            set_setting($k, (string) max(0, (int) ($_POST[$k] ?? 0)));
        }
        flash('success', 'Prices and allowances saved.');
        redirect('admin/payments.php?tab=pricing');
    }
}

$pendingCount = (int) $db->query("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn();
$month        = (float) $db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'approved' AND reviewed_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn();
$allTime      = (float) $db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'approved'")->fetchColumn();
$proUsers     = (int) $db->query("SELECT COUNT(*) FROM users WHERE plan = 'pro' AND plan_expires_at > NOW()")->fetchColumn();
$packages     = billingPackages();

adminOpen('payments', 'Payments', 'OTT vouchers to verify, revenue and pricing.');
?>
<div class="kpis">
  <div class="kpi kpi--amber"><div class="kpi__ico"><i class="fa-solid fa-hourglass-half"></i></div><div><div class="kpi__label">Waiting for you</div><div class="kpi__value"><?= $pendingCount ?></div><div class="kpi__delta">vouchers to verify</div></div></div>
  <div class="kpi kpi--mint"><div class="kpi__ico"><i class="fa-solid fa-sack-dollar"></i></div><div><div class="kpi__label">Revenue this month</div><div class="kpi__value"><?= moneyZar($month) ?></div><div class="kpi__delta"><?= moneyZar($allTime) ?> all time</div></div></div>
  <div class="kpi kpi--violet"><div class="kpi__ico"><i class="fa-solid fa-gem"></i></div><div><div class="kpi__label">Pro customers</div><div class="kpi__value"><?= $proUsers ?></div><div class="kpi__delta"><?= moneyZar($proUsers * billingConfig()['pro_price']) ?> a month</div></div></div>
  <div class="kpi"><div class="kpi__ico"><i class="fa-solid fa-coins"></i></div><div><div class="kpi__label">Credits held</div><div class="kpi__value"><?= number_format((int) $db->query('SELECT COALESCE(SUM(credits),0) FROM users')->fetchColumn()) ?></div><div class="kpi__delta">unused, across all users</div></div></div>
</div>

<nav class="tabs">
  <a href="?tab=pending" class="<?= $tab === 'pending' ? 'is-on' : '' ?>"><i class="fa-solid fa-inbox"></i> To verify (<?= $pendingCount ?>)</a>
  <a href="?tab=done" class="<?= $tab === 'done' ? 'is-on' : '' ?>"><i class="fa-solid fa-clock-rotate-left"></i> History</a>
  <a href="?tab=pricing" class="<?= $tab === 'pricing' ? 'is-on' : '' ?>"><i class="fa-solid fa-tags"></i> Prices & plans</a>
</nav>

<?php if ($tab === 'pricing'): $c = billingConfig(); ?>
<section class="card" style="max-width:820px">
  <div class="card__head"><div><h2>Prices & plans</h2><p>Changes apply immediately to the billing page, the chat and the landing page.</p></div></div>
  <form method="POST" class="card__body"><?= csrfField() ?><input type="hidden" name="form" value="pricing">
    <div class="form-grid">
      <div class="field"><label>Free plan: documents / month</label><input type="number" min="0" name="bill_free_docs" value="<?= $c['free_docs'] ?>"></div>
      <div class="field"><label>Free plan: AI requests / month</label><input type="number" min="0" name="bill_free_ai" value="<?= $c['free_ai'] ?>"></div>
      <div class="field"><label>Pro price (R / month)</label><input type="number" min="0" name="bill_pro_price" value="<?= $c['pro_price'] ?>"></div>
      <div class="field"><label>Pro: documents / month</label><input type="number" min="0" name="bill_pro_docs" value="<?= $c['pro_docs'] ?>"></div>
      <div class="field"><label>Pro: AI requests / month</label><input type="number" min="0" name="bill_pro_ai" value="<?= $c['pro_ai'] ?>"></div>
      <div class="field"><label>Credit pack price (R)</label><input type="number" min="0" name="bill_pack_price" value="<?= $c['pack_price'] ?>"></div>
      <div class="field"><label>Credits in a pack</label><input type="number" min="0" name="bill_pack_size" value="<?= $c['pack_size'] ?>"></div>
      <div class="field"><label>Free credits on sign-up</label><input type="number" min="0" name="bill_signup_gift" value="<?= $c['signup_gift'] ?>"><div class="hint">A welcome gift, e.g. 5. Leave 0 for none.</div></div>
    </div>
    <div class="form-foot"><button class="btn btn-primary">Save</button></div>
  </form>
</section>

<?php else:
    $rows = $tab === 'pending'
        ? $db->query("SELECT p.*, u.email, u.full_name, u.company_name, u.phone FROM payments p JOIN users u ON u.id = p.user_id WHERE p.status = 'pending' ORDER BY p.id")->fetchAll()
        : $db->query("SELECT p.*, u.email, u.full_name, u.company_name, u.phone, a.email AS reviewer FROM payments p JOIN users u ON u.id = p.user_id LEFT JOIN users a ON a.id = p.reviewed_by WHERE p.status <> 'pending' ORDER BY p.reviewed_at DESC LIMIT 200")->fetchAll(); ?>
<?php if ($tab === 'pending'): ?>
  <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i><span>For each voucher: copy the PIN → redeem it in your <strong>OTT merchant portal / app</strong> → if it redeems for the right amount, press <strong>Approve</strong>. If it's already used or invalid, <strong>Reject</strong> with the reason.</span></div>
<?php endif; ?>
<section class="card">
  <?php if (!$rows): ?><div class="empty"><i class="fa-solid fa-mug-hot"></i><?= $tab === 'pending' ? 'Nothing to verify. 🎉' : 'No reviewed payments yet.' ?></div><?php else: ?>
  <table class="tbl">
    <thead><tr><th>Customer</th><th>Package</th><th>Voucher</th><th>Slip</th><th>Submitted</th><th><?= $tab === 'pending' ? '' : 'Result' ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $p): $pkg = $packages[$p['package']] ?? ['label' => $p['package'], 'price' => $p['amount']]; $short = (float) $p['voucher_amount'] < (float) $p['amount']; ?>
      <tr>
        <td class="cell-main"><a class="who" href="users.php?id=<?= (int) $p['user_id'] ?>" style="color:inherit;text-decoration:none"><div class="avatar"><?= e(strtoupper(mb_substr($p['full_name'] ?: $p['email'], 0, 1))) ?></div><div><strong><?= e($p['full_name'] ?: ($p['company_name'] ?: $p['email'])) ?></strong><small><?= e($p['email']) ?></small></div></a></td>
        <td data-label="Package"><strong><?= e($pkg['label']) ?></strong><br><span class="muted">R<?= number_format((float) $p['amount'], 2) ?></span></td>
        <td data-label="Voucher"><div class="copy-box" style="margin:0;display:inline-flex"><code><?= e(trim(chunk_split((string) $p['voucher_pin'], 4, ' '))) ?></code><button type="button" class="btn-xs" data-copy="<?= e($p['voucher_pin']) ?>"><i class="fa-regular fa-copy"></i></button></div>
          <br><span class="muted" style="<?= $short ? 'color:var(--coral)' : '' ?>">Voucher: R<?= number_format((float) $p['voucher_amount'], 2) ?></span></td>
        <td data-label="Slip"><?= $p['proof_path'] ? '<a class="btn-xs" target="_blank" href="slip.php?id=' . (int) $p['id'] . '"><i class="fa-regular fa-image"></i> View</a>' : '<span class="muted">—</span>' ?></td>
        <td data-label="Submitted" class="muted"><?= timeAgo($p['created_at']) ?></td>
        <td>
          <?php if ($p['status'] === 'pending'): ?>
            <form method="POST" class="row-actions" style="align-items:center"><?= csrfField() ?><input type="hidden" name="form" value="review"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <input class="input" name="note" placeholder="Reason (if rejecting)" style="width:170px;padding:.4rem .6rem;font-size:.8rem">
              <button class="btn-xs" name="do" value="approve" style="border-color:var(--mint);color:var(--mint)"><i class="fa-solid fa-check"></i> Approve</button>
              <button class="btn-xs danger" name="do" value="reject"><i class="fa-solid fa-xmark"></i> Reject</button>
            </form>
          <?php else: ?>
            <span class="tag <?= $p['status'] === 'approved' ? 'tag-on' : 'tag-off' ?>"><?= e($p['status']) ?></span>
            <div class="muted"><?= timeAgo($p['reviewed_at']) ?><?= $p['reviewer'] ? ' · ' . e($p['reviewer']) : '' ?><?= $p['admin_note'] ? ' · ' . e($p['admin_note']) : '' ?></div>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php adminClose();
