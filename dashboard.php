<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/documents.php';
require_once __DIR__ . '/includes/layout.php';

startAppSession();
requireLogin();
$user = getCurrentUser();
if (!$user) redirect('login.php');
$uid = (int) $user['id'];
$db  = getDB();
$im  = new \InvoiceChat\InvoiceManager($db);

// ── Actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $form = (string) ($_POST['form'] ?? '');
    $id   = (int) ($_POST['invoice_id'] ?? 0);
    $back = preg_match('/^dashboard\.php(\?[\w=&%.+-]*)?$/', (string) ($_POST['back'] ?? '')) ? $_POST['back'] : 'dashboard.php';

    if ($form === 'status' && in_array($_POST['status'] ?? '', ['draft', 'sent', 'paid', 'accepted', 'rejected'], true)) {
        $im->update($id, $uid, ['status' => $_POST['status']]);
        flash('success', $_POST['status'] === 'paid' ? 'Marked as paid. 🎉' : 'Status updated.');
    } elseif ($form === 'email' || $form === 'remind') {
        $err = emailDocument($uid, $id, strtolower(trim((string) ($_POST['to'] ?? ''))), $form === 'remind');
        flash($err === '' ? 'success' : 'error', $err === '' ? ($form === 'remind' ? 'Reminder emailed.' : 'Emailed to ' . $_POST['to'] . '.') : $err);
    } elseif ($form === 'client') {
        $cid = (int) ($_POST['client_id'] ?? 0);
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $db->prepare('UPDATE clients SET email = ?, phone = ?, notes = ? WHERE id = ? AND user_id = ?')
           ->execute([filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null, trim((string) ($_POST['phone'] ?? '')) ?: null, trim((string) ($_POST['notes'] ?? '')) ?: null, $cid, $uid]);
        flash('success', 'Client saved. New documents for them use these details.');
        $back = 'dashboard.php?client=' . $cid;
    }
    redirect($back);
}

// ── Numbers ───────────────────────────────────────────────────────
$q = function (string $sql, array $args = []) use ($db, $uid) {
    $s = $db->prepare($sql);
    $s->execute(array_merge([$uid], $args));
    return $s->fetchColumn();
};
$outstanding = (float) $q("SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE user_id = ? AND doc_type = 'invoice' AND status = 'sent'");
$overdueAmt  = (float) $q("SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE user_id = ? AND doc_type = 'invoice' AND status = 'sent' AND due_date < CURDATE()");
$overdueN    = (int) $q("SELECT COUNT(*) FROM invoices WHERE user_id = ? AND doc_type = 'invoice' AND status = 'sent' AND due_date < CURDATE()");
$paidMonth   = (float) $q("SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE user_id = ? AND doc_type = 'invoice' AND status = 'paid' AND paid_at >= ?", [date('Y-m-01')]);
$quotesOpen  = (int) $q("SELECT COUNT(*) FROM invoices WHERE user_id = ? AND doc_type = 'quote' AND status = 'sent'");
$notSent     = (int) $q("SELECT COUNT(*) FROM invoices WHERE user_id = ? AND pdf_path IS NOT NULL AND emailed_at IS NULL AND whatsapped_at IS NULL AND status = 'draft'");

$view = isset($_GET['client']) ? 'client' : (isset($_GET['doc']) ? 'doc' : ((($_GET['tab'] ?? '') === 'clients') ? 'clients' : 'docs'));

function deliveryIcons(array $d): string
{
    $i = [];
    $i[] = $d['emailed_at'] ? '<span class="dl on" title="Emailed ' . e(date('j M H:i', strtotime($d['emailed_at']))) . '"><i class="fa-solid fa-envelope"></i></span>' : '<span class="dl" title="Not emailed"><i class="fa-solid fa-envelope"></i></span>';
    $i[] = $d['whatsapped_at'] ? '<span class="dl on wa" title="Sent on WhatsApp ' . e(date('j M H:i', strtotime($d['whatsapped_at']))) . '"><i class="fa-brands fa-whatsapp"></i></span>' : '<span class="dl" title="Not sent on WhatsApp"><i class="fa-brands fa-whatsapp"></i></span>';
    $i[] = $d['viewed_at'] ? '<span class="dl on seen" title="Opened ' . (int) $d['view_count'] . '× · first ' . e(date('j M H:i', strtotime($d['viewed_at']))) . '"><i class="fa-solid fa-eye"></i></span>' : '<span class="dl" title="Not opened yet"><i class="fa-solid fa-eye"></i></span>';
    return '<span class="dls">' . implode('', $i) . '</span>';
}

function docActions(array $d, array $user, string $back): string
{
    $pdf = docPdfUrl($d);
    $overdue = isOverdue($d);
    ob_start(); ?>
    <div class="row-actions">
      <?php if ($pdf): ?>
        <a class="btn-xs" href="<?= e($pdf) ?>" target="_blank" title="Open PDF"><i class="fa-solid fa-file-pdf"></i></a>
        <a class="btn-xs wa" target="_blank" rel="noopener" data-wa="<?= (int) $d['id'] ?>" data-kind="<?= $overdue ? 'reminder_whatsapp' : 'whatsapp' ?>"
           href="<?= e(whatsappShareUrl($d, $user, $pdf, $overdue)) ?>" title="<?= $overdue ? 'WhatsApp a reminder' : 'Send on WhatsApp' ?>"><i class="fa-brands fa-whatsapp"></i> <?= $overdue ? 'Remind' : ($d['whatsapped_at'] ? 'Resend' : 'Send') ?></a>
        <form method="POST" class="mail-form"><?= csrfField() ?><input type="hidden" name="invoice_id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
          <input type="hidden" name="form" value="<?= $overdue ? 'remind' : 'email' ?>"><input type="hidden" name="to" value="<?= e($d['client_email']) ?>">
          <button class="btn-xs" data-ask="<?= $d['client_email'] ? '' : '1' ?>" title="<?= $overdue ? 'Email a reminder' : 'Email to client' ?>"><i class="fa-solid fa-envelope"></i> <?= $overdue ? 'Remind' : ($d['emailed_at'] ? 'Resend' : 'Email') ?></button>
        </form>
      <?php else: ?>
        <a class="btn-xs" href="chat.php?open=<?= (int) $d['id'] ?>"><i class="fa-solid fa-wand-magic-sparkles"></i> Finish in chat</a>
      <?php endif; ?>
      <form method="POST"><?= csrfField() ?><input type="hidden" name="form" value="status"><input type="hidden" name="invoice_id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
        <select class="select-sm" name="status" onchange="this.form.submit()" aria-label="Status">
          <?php foreach ($d['doc_type'] === 'quote' ? ['draft', 'sent', 'accepted', 'rejected'] : ['draft', 'sent', 'paid'] as $st): ?>
            <option value="<?= $st ?>" <?= $d['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <?php return (string) ob_get_clean();
}

pageHead('My dashboard – ' . APP_NAME, ['css' => ['style.css', 'admin.css']]);
?>
<body>
<header class="site-nav"><div class="wrap">
  <?= brandMark() ?>
  <nav>
    <a href="dashboard.php" class="hide-sm" style="color:var(--text)"><i class="fa-solid fa-gauge"></i> Dashboard</a>
    <a href="dashboard.php?tab=clients" class="hide-sm"><i class="fa-solid fa-address-book"></i> Clients</a>
    <a href="billing.php" class="hide-sm"><i class="fa-solid fa-gem"></i> <?= e(usageFor($user)['plan']) ?></a>
    <a href="chat.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> New invoice</a>
  </nav>
</div></header>
<main class="wrap" style="padding:1.6rem 0 4rem">
  <?= flashHtml() ?>

<?php if ($view === 'doc'):
    $s = $db->prepare('SELECT * FROM invoices WHERE id = ? AND user_id = ?');
    $s->execute([(int) $_GET['doc'], $uid]);
    $d = $s->fetch();
    if (!$d) { echo '<div class="empty">Document not found.</div>'; } else {
    $ev = $db->prepare('SELECT * FROM document_events WHERE invoice_id = ? ORDER BY id DESC');
    $ev->execute([$d['id']]);
    $items = $im->getItems((int) $d['id']); ?>
  <div class="adm-head"><div><h1><?= e($d['client_name'] ?: 'No client') ?> · <?= e($d['invoice_number']) ?></h1>
    <p><?= $d['doc_type'] === 'quote' ? 'Quote' : 'Invoice' ?> · <?= e(formatMoney((float) $d['total_amount'], $d['currency'])) ?> · <?= $d['doc_type'] === 'quote' ? 'valid until' : 'due' ?> <?= $d['due_date'] ? date('j M Y', strtotime($d['due_date'])) : '—' ?> <?= statusTag($d) ?></p></div>
    <div class="adm-head__actions"><a class="btn btn-ghost" href="dashboard.php"><i class="fa-solid fa-arrow-left"></i> All documents</a><a class="btn btn-ghost" href="chat.php?open=<?= (int) $d['id'] ?>"><i class="fa-solid fa-comments"></i> Open in chat</a></div></div>
  <div class="grid-2">
    <section class="card"><div class="card__head"><h2>Send & track</h2><?= deliveryIcons($d) ?></div>
      <div class="card__body"><?= docActions($d, $user, 'dashboard.php?doc=' . $d['id']) ?>
        <h3 class="mini-h">Timeline</h3>
        <?php foreach ($ev as $x): [$label, $icon] = DOC_EVENT_LABELS[$x['kind']] ?? [ucfirst($x['kind']), 'fa-circle']; ?>
          <div class="act"><i class="<?= str_contains($icon, 'fa-brands') ? $icon : 'fa-solid ' . $icon ?>"></i><div><strong><?= e($label) ?><?= $x['kind'] === 'status' ? ': ' . e($x['detail']) : ($x['detail'] && in_array($x['kind'], ['email', 'reminder'], true) ? ' to ' . e($x['detail']) : '') ?></strong><small><?= date('j M Y, H:i', strtotime($x['created_at'])) ?></small></div></div>
        <?php endforeach; ?>
        <div class="act"><i class="fa-solid fa-plus"></i><div><strong>Created</strong><small><?= date('j M Y, H:i', strtotime($d['created_at'])) ?></small></div></div>
      </div></section>
    <section class="card"><div class="card__head"><h2>Items</h2></div>
      <table class="tbl"><tbody>
        <?php foreach ($items as $it): ?><tr><td class="cell-main"><strong><?= e($it['product_name']) ?></strong><br><span class="muted"><?= rtrim(rtrim(number_format((float) $it['quantity'], 2), '0'), '.') ?> × <?= e(formatMoney((float) $it['unit_price'], $d['currency'])) ?></span></td><td class="num"><?= e(formatMoney($it['quantity'] * $it['unit_price'], $d['currency'])) ?></td></tr><?php endforeach; ?>
        <tr><td><strong>Total</strong></td><td class="num"><strong><?= e(formatMoney((float) $d['total_amount'], $d['currency'])) ?></strong></td></tr>
      </tbody></table>
      <div class="card__body"><p class="hint" style="margin:0">Client: <?= e($d['client_email'] ?: 'no email') ?> · <?= e($d['client_phone'] ?: 'no phone') ?></p></div>
    </section>
  </div>
<?php } elseif ($view === 'client' || $view === 'clients'):
    if ($view === 'client') {
        $s = $db->prepare('SELECT * FROM clients WHERE id = ? AND user_id = ?');
        $s->execute([(int) $_GET['client'], $uid]);
        $c = $s->fetch();
    }
    $clients = $db->prepare("SELECT c.*, COUNT(i.id) docs,
        COALESCE(SUM(CASE WHEN i.doc_type = 'invoice' THEN i.total_amount END),0) billed,
        COALESCE(SUM(CASE WHEN i.doc_type = 'invoice' AND i.status = 'sent' THEN i.total_amount END),0) owing,
        MAX(i.created_at) last_doc
        FROM clients c LEFT JOIN invoices i ON i.client_id = c.id WHERE c.user_id = ? " . (($_GET['q'] ?? '') !== '' ? 'AND c.name LIKE ? ' : '') . "GROUP BY c.id ORDER BY last_doc DESC");
    $clients->execute(array_merge([$uid], ($_GET['q'] ?? '') !== '' ? ['%' . $_GET['q'] . '%'] : []));
    $clients = $clients->fetchAll(); ?>
  <?php if ($view === 'client' && !empty($c)):
      $docs = $db->prepare('SELECT * FROM invoices WHERE client_id = ? AND user_id = ? ORDER BY created_at DESC');
      $docs->execute([$c['id'], $uid]);
      $docs = $docs->fetchAll(); ?>
    <div class="adm-head"><div><h1><?= e($c['name']) ?></h1><p><?= count($docs) ?> documents</p></div><div class="adm-head__actions"><a class="btn btn-ghost" href="dashboard.php?tab=clients"><i class="fa-solid fa-arrow-left"></i> All clients</a></div></div>
    <div class="grid-2">
      <section class="card"><div class="card__head"><h2>Documents</h2></div>
        <?php if (!$docs): ?><div class="empty">No documents yet.</div><?php else: ?>
        <table class="tbl"><tbody><?php foreach ($docs as $d): ?>
          <tr><td class="cell-main"><a href="?doc=<?= (int) $d['id'] ?>"><strong><?= e($d['invoice_number']) ?></strong></a><br><span class="muted"><?= date('j M Y', strtotime($d['created_at'])) ?></span></td>
            <td data-label="Amount" class="num"><?= e(formatMoney((float) $d['total_amount'], $d['currency'])) ?></td><td data-label="Status"><?= statusTag($d) ?></td><td data-label="Sent"><?= deliveryIcons($d) ?></td></tr>
        <?php endforeach; ?></tbody></table><?php endif; ?>
      </section>
      <section class="card"><div class="card__head"><h2>Contact</h2></div>
        <form method="POST" class="card__body"><?= csrfField() ?><input type="hidden" name="form" value="client"><input type="hidden" name="client_id" value="<?= (int) $c['id'] ?>">
          <div class="field"><label>Email</label><input name="email" type="email" value="<?= e($c['email']) ?>"></div>
          <div class="field"><label>WhatsApp / phone</label><input name="phone" value="<?= e($c['phone']) ?>" placeholder="082 123 4567"></div>
          <div class="field"><label>Notes</label><textarea name="notes" rows="3"><?= e($c['notes']) ?></textarea></div>
          <div class="form-foot"><button class="btn btn-primary">Save</button>
            <?php if ($c['phone']): ?><a class="btn btn-ghost" target="_blank" href="https://wa.me/<?= e(waDigits($c['phone'])) ?>"><i class="fa-brands fa-whatsapp"></i> Chat</a><?php endif; ?>
            <?php if ($c['email']): ?><a class="btn btn-ghost" href="mailto:<?= e($c['email']) ?>"><i class="fa-regular fa-envelope"></i> Email</a><?php endif; ?></div>
        </form>
      </section>
    </div>
  <?php else: ?>
    <div class="adm-head"><div><h1>Clients</h1><p>Everyone you've invoiced or quoted. Pick a saved client in the chat and their details fill in automatically.</p></div></div>
    <section class="card">
      <form class="toolbar" method="get"><input type="hidden" name="tab" value="clients"><input class="input" type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search clients"><span class="count"><?= count($clients) ?> clients</span></form>
      <?php if (!$clients): ?><div class="empty"><i class="fa-solid fa-address-book"></i>Clients appear here after your first invoice or quote.</div><?php else: ?>
      <table class="tbl"><thead><tr><th>Client</th><th>Contact</th><th class="num">Documents</th><th class="num">Invoiced</th><th class="num">Owing</th><th>Last</th><th></th></tr></thead><tbody>
        <?php foreach ($clients as $c): ?>
          <tr><td class="cell-main"><a class="who" href="?client=<?= (int) $c['id'] ?>" style="color:inherit;text-decoration:none"><div class="avatar"><?= e(mb_strtoupper(mb_substr($c['name'], 0, 1))) ?></div><strong><?= e($c['name']) ?></strong></a></td>
            <td data-label="Contact" class="muted"><?= e($c['email'] ?: '—') ?><br><?= e($c['phone'] ?: '') ?></td>
            <td data-label="Documents" class="num"><?= (int) $c['docs'] ?></td>
            <td data-label="Invoiced" class="num"><?= e(formatMoney((float) $c['billed'], 'ZAR')) ?></td>
            <td data-label="Owing" class="num" style="<?= $c['owing'] > 0 ? 'color:var(--amber);font-weight:700' : '' ?>"><?= e(formatMoney((float) $c['owing'], 'ZAR')) ?></td>
            <td data-label="Last" class="muted"><?= $c['last_doc'] ? date('j M', strtotime($c['last_doc'])) : '—' ?></td>
            <td><div class="row-actions"><?php if ($c['phone']): ?><a class="btn-xs wa" target="_blank" href="https://wa.me/<?= e(waDigits($c['phone'])) ?>"><i class="fa-brands fa-whatsapp"></i></a><?php endif; ?><a class="btn-xs" href="?client=<?= (int) $c['id'] ?>">Open</a></div></td></tr>
        <?php endforeach; ?></tbody></table>
      <?php endif; ?>
    </section>
  <?php endif; ?>

<?php else:
    $filter = in_array($_GET['f'] ?? '', ['unpaid', 'overdue', 'paid', 'quotes', 'drafts', 'notsent'], true) ? $_GET['f'] : 'all';
    $where = ['user_id = ?'];
    $args = [$uid];
    $where[] = match ($filter) {
        'unpaid'  => "doc_type = 'invoice' AND status = 'sent'",
        'overdue' => "doc_type = 'invoice' AND status = 'sent' AND due_date < CURDATE()",
        'paid'    => "status = 'paid'",
        'quotes'  => "doc_type = 'quote'",
        'drafts'  => "status = 'draft'",
        'notsent' => "pdf_path IS NOT NULL AND emailed_at IS NULL AND whatsapped_at IS NULL AND status = 'draft'",
        // Abandoned empty drafts only show under "Drafts".
        default   => "NOT (status = 'draft' AND pdf_path IS NULL AND total_amount = 0)",
    };
    if (($_GET['q'] ?? '') !== '') { $where[] = '(client_name LIKE ? OR invoice_number LIKE ?)'; array_push($args, '%' . $_GET['q'] . '%', '%' . $_GET['q'] . '%'); }
    $s = $db->prepare('SELECT * FROM invoices WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT 200');
    $s->execute($args);
    $docs = $s->fetchAll();
    $back = 'dashboard.php' . ($filter !== 'all' ? '?f=' . $filter : ''); ?>
  <div class="adm-head"><div><h1>Hi <?= e(explode(' ', (string) ($user['full_name'] ?: $user['company_name'] ?: 'there'))[0]) ?> 👋</h1><p>Your invoices, quotes and who has seen them.</p></div>
    <div class="adm-head__actions"><a class="btn btn-ghost" href="setup.php"><i class="fa-solid fa-sliders"></i> Business setup</a><a class="btn btn-primary" href="chat.php"><i class="fa-solid fa-plus"></i> New invoice</a></div></div>

  <div class="kpis">
    <div class="kpi kpi--amber"><div class="kpi__ico"><i class="fa-solid fa-hourglass-half"></i></div><div><div class="kpi__label">Waiting to be paid</div><div class="kpi__value"><?= e(formatMoney($outstanding, 'ZAR')) ?></div><div class="kpi__delta"><a href="?f=unpaid">See unpaid →</a></div></div></div>
    <div class="kpi" style="<?= $overdueN ? 'border-color:var(--coral)' : '' ?>"><div class="kpi__ico" style="background:var(--coral-50);color:var(--coral)"><i class="fa-solid fa-triangle-exclamation"></i></div><div><div class="kpi__label">Overdue</div><div class="kpi__value"><?= e(formatMoney($overdueAmt, 'ZAR')) ?></div><div class="kpi__delta"><?= $overdueN ?> invoice<?= $overdueN === 1 ? '' : 's' ?> · <a href="?f=overdue">send reminders →</a></div></div></div>
    <div class="kpi kpi--mint"><div class="kpi__ico"><i class="fa-solid fa-sack-dollar"></i></div><div><div class="kpi__label">Paid this month</div><div class="kpi__value"><?= e(formatMoney($paidMonth, 'ZAR')) ?></div><div class="kpi__delta"><a href="?f=paid">See paid →</a></div></div></div>
    <div class="kpi kpi--violet"><div class="kpi__ico"><i class="fa-solid fa-paper-plane"></i></div><div><div class="kpi__label">Not sent yet</div><div class="kpi__value"><?= $notSent ?></div><div class="kpi__delta"><?= $quotesOpen ?> quote<?= $quotesOpen === 1 ? '' : 's' ?> awaiting reply</div></div></div>
  </div>

  <section class="card">
    <form class="toolbar" method="get">
      <div class="chips">
        <?php foreach (['all' => 'All', 'unpaid' => 'Unpaid', 'overdue' => 'Overdue', 'notsent' => 'Not sent', 'paid' => 'Paid', 'quotes' => 'Quotes', 'drafts' => 'Drafts'] as $k => $l): ?>
          <a class="chip-check chip-sm<?= $filter === $k ? ' is-on' : '' ?>" href="?f=<?= $k ?>"><?= $l ?></a>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="f" value="<?= e($filter) ?>"><input class="input" type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search client or number">
    </form>
    <?php if (!$docs): ?><div class="empty"><i class="fa-regular fa-file-lines"></i><?= $filter === 'all' ? 'No documents yet. <a href="chat.php">Create your first invoice</a> — it takes about two minutes.' : 'Nothing here.' ?></div><?php else: ?>
    <table class="tbl">
      <thead><tr><th>Client</th><th class="num">Amount</th><th>Due</th><th>Status</th><th>Sent · Seen</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($docs as $d): $od = isOverdue($d); ?>
        <tr>
          <td class="cell-main"><a href="?doc=<?= (int) $d['id'] ?>" style="color:inherit;text-decoration:none"><strong><?= e($d['client_name'] ?: 'No client yet') ?></strong><br><span class="muted"><?= $d['doc_type'] === 'quote' ? 'Quote' : 'Invoice' ?> <?= e($d['invoice_number']) ?></span></a></td>
          <td data-label="Amount" class="num"><strong><?= e(formatMoney((float) $d['total_amount'], $d['currency'])) ?></strong></td>
          <td data-label="Due" style="<?= $od ? 'color:var(--coral);font-weight:700' : '' ?>"><?= $d['due_date'] ? date('j M', strtotime($d['due_date'])) : '—' ?><?= $od ? '<br><small>' . (int) ((time() - strtotime($d['due_date'])) / 86400) . ' days late</small>' : '' ?></td>
          <td data-label="Status"><?= statusTag($d) ?></td>
          <td data-label="Sent · Seen"><?= deliveryIcons($d) ?></td>
          <td><?= docActions($d, $user, $back) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>
  <p class="hint" style="margin-top:1rem"><i class="fa-solid fa-circle-info"></i> <i class="fa-solid fa-eye"></i> lights up when your client opens the PDF link. WhatsApp sends open your own WhatsApp with the message ready — we record the tap.</p>
<?php endif; ?>
</main>
<style>
  .dls { display: inline-flex; gap: .3rem; }
  .dl { width: 26px; height: 26px; border-radius: 8px; display: inline-grid; place-items: center; font-size: .75rem; background: var(--surface-2); color: var(--line-strong); border: 1px solid var(--line); }
  .dl.on { background: var(--brand-50); color: var(--brand); border-color: transparent; }
  .dl.on.wa { background: #e7f8ee; color: #128c7e; }
  .dl.on.seen { background: var(--mint-50); color: var(--mint); }
  .btn-xs.wa { color: #128c7e; }
  .mail-form, .row-actions form { display: inline; }
  .chip-check.is-on { border-color: var(--brand); background: var(--brand-50); color: var(--brand); text-decoration: none; }
  .chip-check { text-decoration: none !important; color: var(--text-2); }
  .mini-h { font-size: .78rem; text-transform: uppercase; letter-spacing: .06em; color: var(--text-3); font-family: var(--font); margin: 1.1rem 0 .4rem; }
  .act { display: flex; gap: .6rem; padding: .4rem 0; font-size: .86rem; }
  .act i { color: var(--text-3); width: 16px; margin-top: .2rem; }
  .act small { display: block; color: var(--text-3); font-size: .75rem; }
</style>
<script>
  // Record WhatsApp taps (the message opens in the user's own WhatsApp).
  document.querySelectorAll('[data-wa]').forEach(function (a) {
    a.addEventListener('click', function () {
      fetch('ajax/doc_event.php', { method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': <?= json_encode(csrfToken()) ?> },
        body: JSON.stringify({ invoice_id: a.dataset.wa, kind: a.dataset.kind }) }).then(function () { setTimeout(function () { location.reload(); }, 1500); });
    });
  });
  // No client email saved yet: ask for one before sending.
  document.querySelectorAll('.mail-form button[data-ask="1"]').forEach(function (b) {
    b.addEventListener('click', function (e) {
      var to = prompt('Client email address:');
      if (!to) { e.preventDefault(); return; }
      b.form.querySelector('[name=to]').value = to.trim();
    });
  });
</script>
</body>
</html>
<?php
function statusTag(array $d): string
{
    if (isOverdue($d)) return '<span class="pill badge-rejected">overdue</span>';
    return '<span class="pill badge-' . e($d['status']) . '">' . e($d['status']) . '</span>';
}
