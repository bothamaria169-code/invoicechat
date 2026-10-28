<?php
declare(strict_types=1);

require_once __DIR__ . '/_shell.php';

$db = getDB();
$me = (int) $ADMIN['id'];

// ── Actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int) ($_POST['user_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    $s = $db->prepare('SELECT * FROM users WHERE id = ?');
    $s->execute([$id]);
    $target = $s->fetch();
    $back = $_POST['back'] ?? 'users.php';

    if (!$target) {
        flash('error', 'That user no longer exists.');
    } elseif ($action === 'send_reset') {
        $err = sendResetLink($target);
        flash($err === '' ? 'success' : 'error', $err === '' ? 'Password link sent to ' . $target['email'] . '.' : 'Email failed: ' . $err);
    } elseif ($id === $me || $target['email'] === ADMIN_EMAIL) {
        flash('error', 'The main admin account can\'t be changed from here.');
    } elseif ($action === 'toggle_active') {
        $db->prepare('UPDATE users SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        flash('success', $target['email'] . ($target['is_active'] ? ' disabled.' : ' enabled.'));
    } elseif ($action === 'make_admin') {
        $db->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([$id]);
        flash('success', $target['email'] . ' is now an admin.');
    } elseif ($action === 'revoke_admin') {
        $db->prepare("UPDATE users SET role = 'user' WHERE id = ?")->execute([$id]);
        flash('success', 'Admin rights removed from ' . $target['email'] . '.');
    } elseif ($action === 'delete_user') {
        foreach ($db->query('SELECT pdf_path FROM invoices WHERE user_id = ' . $id)->fetchAll(PDO::FETCH_COLUMN) as $p) {
            if ($f = pdfFile($p)) @unlink($f);
        }
        if ($target['logo_path'] && is_file($target['logo_path'])) @unlink($target['logo_path']);
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        flash('success', $target['email'] . ' and all their documents were deleted.');
        $back = 'users.php';
    }
    redirect('admin/' . (preg_match('/^users\.php(\?[\w=&%.-]*)?$/', $back) ? $back : 'users.php'));
}

// ── Filters ───────────────────────────────────────────────────────
$q      = trim((string) ($_GET['q'] ?? ''));
$role   = (string) ($_GET['role'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$method = (string) ($_GET['method'] ?? '');
$where  = ['1=1'];
$args   = [];
if ($q !== '') { $where[] = '(u.email LIKE ? OR u.full_name LIKE ? OR u.company_name LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
if (in_array($role, ['user', 'admin'], true)) { $where[] = 'u.role = ?'; $args[] = $role; }
if ($status === 'active')   $where[] = 'u.is_active = 1';
if ($status === 'disabled') $where[] = 'u.is_active = 0';
if (in_array($method, ['email', 'google'], true)) { $where[] = 'u.signup_method = ?'; $args[] = $method; }
$whereSql = implode(' AND ', $where);

$select = "SELECT u.id, u.email, u.full_name, u.company_name, u.phone, u.role, u.is_active, u.signup_method, u.signup_source,
                  u.avatar_url, u.created_at, u.last_login_at, u.login_count,
                  COUNT(i.id) AS docs,
                  COALESCE(SUM(CASE WHEN i.status = 'paid' THEN i.total_amount END), 0) AS paid
           FROM users u LEFT JOIN invoices i ON i.user_id = u.id
           WHERE {$whereSql} GROUP BY u.id ORDER BY u.created_at DESC";

// ── CSV export ────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $s = $db->prepare($select);
    $s->execute($args);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="invoicechat-users-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Email', 'Name', 'Company', 'Phone', 'Role', 'Active', 'Signed up with', 'Source', 'Joined', 'Last sign-in', 'Sign-ins', 'Documents', 'Paid']);
    foreach ($s as $r) {
        fputcsv($out, [$r['id'], $r['email'], $r['full_name'], $r['company_name'], $r['phone'], $r['role'], $r['is_active'] ? 'yes' : 'no',
            $r['signup_method'], $r['signup_source'], $r['created_at'], $r['last_login_at'], $r['login_count'], $r['docs'], $r['paid']]);
    }
    exit;
}

// ── Single user ───────────────────────────────────────────────────
if (isset($_GET['id'])) {
    $s = $db->prepare('SELECT * FROM users WHERE id = ?');
    $s->execute([(int) $_GET['id']]);
    $u = $s->fetch();
    if (!$u) { flash('error', 'User not found.'); redirect('admin/users.php'); }
    $docs = $db->prepare('SELECT id, invoice_number, doc_type, client_name, total_amount, currency, status, pdf_path, created_at FROM invoices WHERE user_id = ? ORDER BY created_at DESC LIMIT 100');
    $docs->execute([$u['id']]);
    $docs = $docs->fetchAll();
    $logins = $db->prepare('SELECT ip, success, method, created_at FROM login_attempts WHERE email = ? ORDER BY id DESC LIMIT 8');
    $logins->execute([$u['email']]);
    $logins = $logins->fetchAll();
    $name = $u['full_name'] ?: ($u['company_name'] ?: $u['email']);
    $back = 'users.php?id=' . $u['id'];
    $locked = (int) $u['id'] === $me || $u['email'] === ADMIN_EMAIL;

    adminOpen('users', $name, e($u['email']) . ' · joined ' . date('d M Y', strtotime($u['created_at'])), '<a class="btn btn-ghost" href="users.php"><i class="fa-solid fa-arrow-left"></i> All users</a>');
    ?>
    <div class="grid-2">
      <section class="card">
        <div class="card__head"><h2>Profile</h2><div><?= $u['role'] === 'admin' ? '<span class="tag tag-admin">Admin</span>' : '<span class="tag tag-user">User</span>' ?> <?= $u['is_active'] ? '<span class="tag tag-on">Active</span>' : '<span class="tag tag-off">Disabled</span>' ?></div></div>
        <div class="card__body">
          <table class="tbl"><tbody>
            <?php foreach ([
                'Name' => $u['full_name'], 'Company' => $u['company_name'], 'Phone' => $u['phone'], 'Address' => $u['address'],
                'Website' => $u['website'], 'Tax number' => $u['tax_number'],
                'Signed up with' => ucfirst((string) $u['signup_method']) . ($u['google_id'] ? ' · Google linked' : ''),
                'Source' => $u['signup_source'] ?: 'Direct / unknown', 'Sign-up IP' => $u['signup_ip'],
                'Last sign-in' => timeAgo($u['last_login_at']) . ' · ' . (int) $u['login_count'] . ' total',
                'Password' => $u['password_hash'] ? 'Set' : 'Not set (Google only)',
            ] as $k => $v): ?>
              <tr><td class="muted" style="width:38%"><?= e($k) ?></td><td><?= nl2br(e($v ?: '—')) ?></td></tr>
            <?php endforeach; ?>
          </tbody></table>
        </div>
      </section>
      <section class="card">
        <div class="card__head"><h2>Actions</h2></div>
        <div class="card__body" style="display:grid;gap:.6rem">
          <form method="POST"><?= csrfField() ?><input type="hidden" name="user_id" value="<?= $u['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>"><input type="hidden" name="action" value="send_reset">
            <button class="btn btn-ghost btn-block"><i class="fa-solid fa-key"></i> Email a password <?= $u['password_hash'] ? 'reset' : 'set-up' ?> link</button></form>
          <a class="btn btn-ghost btn-block" href="mailto:<?= e($u['email']) ?>"><i class="fa-regular fa-envelope"></i> Email this user</a>
          <?php if ($u['phone']): ?><a class="btn btn-ghost btn-block" target="_blank" href="https://wa.me/<?= e(preg_replace('/\D/', '', preg_replace('/^0/', '27', $u['phone']))) ?>"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a><?php endif; ?>
          <?php if (!$locked): ?>
            <form method="POST"><?= csrfField() ?><input type="hidden" name="user_id" value="<?= $u['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>"><input type="hidden" name="action" value="toggle_active">
              <button class="btn btn-ghost btn-block"><i class="fa-solid <?= $u['is_active'] ? 'fa-user-slash' : 'fa-user-check' ?>"></i> <?= $u['is_active'] ? 'Disable account' : 'Enable account' ?></button></form>
            <form method="POST"><?= csrfField() ?><input type="hidden" name="user_id" value="<?= $u['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>"><input type="hidden" name="action" value="<?= $u['role'] === 'admin' ? 'revoke_admin' : 'make_admin' ?>">
              <button class="btn btn-ghost btn-block"><i class="fa-solid fa-user-shield"></i> <?= $u['role'] === 'admin' ? 'Remove admin rights' : 'Make admin' ?></button></form>
            <form method="POST" data-confirm="Delete <?= e($u['email']) ?> and ALL their invoices, quotes and chats? This cannot be undone."><?= csrfField() ?><input type="hidden" name="user_id" value="<?= $u['id'] ?>"><input type="hidden" name="action" value="delete_user">
              <button class="btn btn-ghost btn-block" style="color:var(--coral)"><i class="fa-solid fa-trash-can"></i> Delete user</button></form>
          <?php endif; ?>
          <?php if ($logins): ?>
            <h3 style="font-size:.9rem;margin-top:.8rem;font-family:var(--font)">Recent sign-ins</h3>
            <?php foreach ($logins as $l): ?>
              <div class="muted" style="font-size:.8rem"><i class="fa-solid <?= $l['success'] ? 'fa-circle-check" style="color:var(--mint)' : 'fa-circle-xmark" style="color:var(--coral)' ?>"></i> <?= timeAgo($l['created_at']) ?> · <?= e($l['method']) ?> · <?= e($l['ip']) ?></div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </section>
    </div>
    <section class="card">
      <div class="card__head"><h2>Documents (<?= count($docs) ?>)</h2></div>
      <?php if (!$docs): ?><div class="empty"><i class="fa-regular fa-file-lines"></i>No documents yet.</div><?php else: ?>
      <table class="tbl"><thead><tr><th>Document</th><th>Client</th><th class="num">Amount</th><th>Status</th><th>Created</th><th></th></tr></thead><tbody>
        <?php foreach ($docs as $d): ?>
          <tr>
            <td class="cell-main"><strong><?= e($d['invoice_number']) ?></strong> <span class="muted"><?= e(ucfirst($d['doc_type'])) ?></span></td>
            <td data-label="Client"><?= e($d['client_name'] ?: '—') ?></td>
            <td data-label="Amount" class="num"><?= e($d['currency']) ?> <?= number_format((float) $d['total_amount'], 2) ?></td>
            <td data-label="Status"><?= statusPill($d['status']) ?></td>
            <td data-label="Created" class="muted"><?= date('d M Y', strtotime($d['created_at'])) ?></td>
            <td><?php if ($f = pdfFile($d['pdf_path'])): ?><a class="btn-xs" target="_blank" href="<?= e(signedPdfUrl($f)) ?>"><i class="fa-solid fa-file-pdf"></i> PDF</a><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody></table>
      <?php endif; ?>
    </section>
    <?php
    adminClose();
    exit;
}

// ── List ──────────────────────────────────────────────────────────
$per   = 25;
$page  = max(1, (int) ($_GET['page'] ?? 1));
$count = $db->prepare("SELECT COUNT(*) FROM users u WHERE {$whereSql}");
$count->execute($args);
$total = (int) $count->fetchColumn();
$s = $db->prepare($select . ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
$s->execute($args);
$rows = $s->fetchAll();
$qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge(['q' => $q, 'role' => $role, 'status' => $status, 'method' => $method], $over), fn($v) => $v !== '' && $v !== null));

adminOpen('users', 'Users', number_format($total) . ' account' . ($total === 1 ? '' : 's') . ' · every new sign-up is emailed to ' . e(get_setting('signup_notify_email', ADMIN_EMAIL)),
    '<a class="btn btn-ghost" href="users.php' . e($qs(['export' => 1])) . '"><i class="fa-solid fa-download"></i> Export CSV</a>');
?>
<section class="card">
  <form class="toolbar" method="get">
    <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search email, name or company">
    <select name="role" onchange="this.form.submit()"><option value="">All roles</option><option value="user" <?= $role === 'user' ? 'selected' : '' ?>>Users</option><option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Admins</option></select>
    <select name="status" onchange="this.form.submit()"><option value="">Any status</option><option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option><option value="disabled" <?= $status === 'disabled' ? 'selected' : '' ?>>Disabled</option></select>
    <select name="method" onchange="this.form.submit()"><option value="">Any sign-up</option><option value="email" <?= $method === 'email' ? 'selected' : '' ?>>Email</option><option value="google" <?= $method === 'google' ? 'selected' : '' ?>>Google</option></select>
    <button class="btn-xs"><i class="fa-solid fa-filter"></i> Filter</button>
  </form>
  <?php if (!$rows): ?><div class="empty"><i class="fa-solid fa-user-xmark"></i>No users match.</div><?php else: ?>
  <table class="tbl">
    <thead><tr><th>User</th><th>Role</th><th>Signed up</th><th>Source</th><th class="num">Docs</th><th class="num">Paid</th><th>Last seen</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $u): $n = $u['full_name'] ?: ($u['company_name'] ?: $u['email']); $locked = (int) $u['id'] === $me || $u['email'] === ADMIN_EMAIL; ?>
      <tr>
        <td class="cell-main"><a class="who" href="users.php?id=<?= $u['id'] ?>" style="color:inherit;text-decoration:none">
          <div class="avatar"><?php if ($u['avatar_url']): ?><img src="<?= e($u['avatar_url']) ?>" alt="" referrerpolicy="no-referrer"><?php else: ?><?= e(strtoupper(mb_substr($n, 0, 1))) ?><?php endif; ?></div>
          <div style="min-width:0"><strong><?= e($n) ?><?= (int) $u['id'] === $me ? ' <span class="tag tag-user">You</span>' : '' ?></strong><small><?= e($u['email']) ?></small></div></a></td>
        <td data-label="Role"><?= $u['role'] === 'admin' ? '<span class="tag tag-admin">Admin</span>' : '<span class="tag tag-user">User</span>' ?> <?= $u['is_active'] ? '' : '<span class="tag tag-off">Disabled</span>' ?></td>
        <td data-label="Signed up" class="muted"><?= date('d M Y', strtotime($u['created_at'])) ?><?= $u['signup_method'] === 'google' ? ' <i class="fa-brands fa-google" title="Google"></i>' : '' ?></td>
        <td data-label="Source" class="muted"><?= e($u['signup_source'] ?: '—') ?></td>
        <td data-label="Docs" class="num"><?= (int) $u['docs'] ?></td>
        <td data-label="Paid" class="num"><?= moneyZar((float) $u['paid']) ?></td>
        <td data-label="Last seen" class="muted"><?= timeAgo($u['last_login_at']) ?></td>
        <td><div class="row-actions">
          <a class="btn-xs" href="users.php?id=<?= $u['id'] ?>">View</a>
          <?php if (!$locked): ?>
          <form method="POST"><?= csrfField() ?><input type="hidden" name="user_id" value="<?= $u['id'] ?>"><input type="hidden" name="back" value="<?= e('users.php' . $qs(['page' => $page])) ?>"><input type="hidden" name="action" value="toggle_active">
            <button class="btn-xs"><?= $u['is_active'] ? 'Disable' : 'Enable' ?></button></form>
          <?php endif; ?>
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
