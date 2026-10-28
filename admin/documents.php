<?php
declare(strict_types=1);

require_once __DIR__ . '/_shell.php';

$db = getDB();
const DOC_STATUSES = ['draft', 'sent', 'paid', 'accepted', 'rejected'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int) ($_POST['invoice_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'status' && in_array($_POST['status'] ?? '', DOC_STATUSES, true)) {
        $db->prepare('UPDATE invoices SET status = ? WHERE id = ?')->execute([$_POST['status'], $id]);
        flash('success', 'Status updated.');
    } elseif ($action === 'delete') {
        $s = $db->prepare('SELECT pdf_path FROM invoices WHERE id = ?');
        $s->execute([$id]);
        if ($f = pdfFile($s->fetchColumn() ?: null)) @unlink($f);
        $db->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        flash('success', 'Document deleted.');
    }
    $back = (string) ($_POST['back'] ?? '');
    redirect('admin/documents.php' . (preg_match('/^\?[\w=&%.+-]*$/', $back) ? $back : ''));
}

$q      = trim((string) ($_GET['q'] ?? ''));
$type   = (string) ($_GET['type'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$where  = ['1=1'];
$args   = [];
if ($q !== '') { $where[] = '(i.invoice_number LIKE ? OR i.client_name LIKE ? OR u.email LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
if (in_array($type, ['invoice', 'quote'], true)) { $where[] = 'i.doc_type = ?'; $args[] = $type; }
if (in_array($status, DOC_STATUSES, true)) { $where[] = 'i.status = ?'; $args[] = $status; }
$whereSql = implode(' AND ', $where);

$per  = 30;
$page = max(1, (int) ($_GET['page'] ?? 1));
$c = $db->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN i.currency = 'ZAR' THEN i.total_amount END),0) FROM invoices i JOIN users u ON u.id = i.user_id WHERE {$whereSql}");
$c->execute($args);
[$total, $sum] = $c->fetch(PDO::FETCH_NUM);
$s = $db->prepare("SELECT i.*, u.email, u.company_name FROM invoices i JOIN users u ON u.id = i.user_id WHERE {$whereSql} ORDER BY i.created_at DESC LIMIT {$per} OFFSET " . (($page - 1) * $per));
$s->execute($args);
$rows = $s->fetchAll();
$qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge(['q' => $q, 'type' => $type, 'status' => $status], $over), fn($v) => $v !== '' && $v !== null));

adminOpen('documents', 'Invoices & quotes', number_format((int) $total) . ' documents · ' . moneyZar((float) $sum) . ' in ZAR');
?>
<section class="card">
  <form class="toolbar" method="get">
    <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search number, client or owner email">
    <select name="type" onchange="this.form.submit()"><option value="">Invoices & quotes</option><option value="invoice" <?= $type === 'invoice' ? 'selected' : '' ?>>Invoices</option><option value="quote" <?= $type === 'quote' ? 'selected' : '' ?>>Quotes</option></select>
    <select name="status" onchange="this.form.submit()"><option value="">Any status</option><?php foreach (DOC_STATUSES as $st): ?><option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option><?php endforeach; ?></select>
    <button class="btn-xs"><i class="fa-solid fa-filter"></i> Filter</button>
  </form>
  <?php if (!$rows): ?><div class="empty"><i class="fa-regular fa-file-lines"></i>No documents match.</div><?php else: ?>
  <table class="tbl">
    <thead><tr><th>Document</th><th>Client</th><th>Owner</th><th class="num">Amount</th><th>Status</th><th>Created</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $d): ?>
      <tr>
        <td class="cell-main"><strong><?= e($d['invoice_number']) ?></strong> <span class="tag <?= $d['doc_type'] === 'quote' ? 'tag-admin' : 'tag-user' ?>"><?= e(ucfirst($d['doc_type'])) ?></span></td>
        <td data-label="Client"><?= e($d['client_name'] ?: '—') ?><?php if ($d['client_email']): ?><br><span class="muted"><?= e($d['client_email']) ?></span><?php endif; ?></td>
        <td data-label="Owner"><a href="users.php?id=<?= (int) $d['user_id'] ?>"><?= e($d['company_name'] ?: $d['email']) ?></a></td>
        <td data-label="Amount" class="num"><?= e($d['currency']) ?> <?= number_format((float) $d['total_amount'], 2) ?></td>
        <td data-label="Status">
          <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="status"><input type="hidden" name="invoice_id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="back" value="<?= e($qs(['page' => $page])) ?>">
            <select class="select-sm" name="status" onchange="this.form.submit()" aria-label="Status"><?php foreach (DOC_STATUSES as $st): ?><option value="<?= $st ?>" <?= $d['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option><?php endforeach; ?></select>
          </form>
        </td>
        <td data-label="Created" class="muted"><?= date('d M Y', strtotime($d['created_at'])) ?></td>
        <td><div class="row-actions">
          <?php if ($f = pdfFile($d['pdf_path'])): ?><a class="btn-xs" target="_blank" href="<?= e(signedPdfUrl($f)) ?>"><i class="fa-solid fa-file-pdf"></i> PDF</a><?php endif; ?>
          <form method="POST" data-confirm="Delete <?= e($d['invoice_number']) ?> and its PDF?"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="invoice_id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="back" value="<?= e($qs(['page' => $page])) ?>">
            <button class="btn-xs danger" aria-label="Delete"><i class="fa-solid fa-trash-can"></i></button></form>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <?php $pages = (int) ceil($total / $per); if ($pages > 1): ?>
    <div class="pager"><span>Page <?= $page ?> of <?= $pages ?></span><div>
      <?php if ($page > 1): ?><a class="btn-xs" href="<?= e($qs(['page' => $page - 1])) ?>">← Prev</a><?php endif; ?>
      <?php if ($page < $pages): ?><a class="btn-xs" href="<?= e($qs(['page' => $page + 1])) ?>">Next →</a><?php endif; ?>
    </div></div>
  <?php endif; ?>
</section>
<?php adminClose();
