<?php
declare(strict_types=1);

require_once __DIR__ . '/_shell.php';

$db = getDB();
$q  = fn(string $sql) => $db->query($sql)->fetchColumn();

$users      = (int) $q('SELECT COUNT(*) FROM users');
$new7       = (int) $q('SELECT COUNT(*) FROM users WHERE created_at > NOW() - INTERVAL 7 DAY');
$newPrev7   = (int) $q('SELECT COUNT(*) FROM users WHERE created_at BETWEEN NOW() - INTERVAL 14 DAY AND NOW() - INTERVAL 7 DAY');
$active7    = (int) $q('SELECT COUNT(*) FROM users WHERE last_login_at > NOW() - INTERVAL 7 DAY');
$invoices   = (int) $q("SELECT COUNT(*) FROM invoices WHERE doc_type = 'invoice'");
$quotes     = (int) $q("SELECT COUNT(*) FROM invoices WHERE doc_type = 'quote'");
$docs30     = (int) $q('SELECT COUNT(*) FROM invoices WHERE created_at > NOW() - INTERVAL 30 DAY');
$billed     = (float) $q("SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE doc_type = 'invoice' AND currency = 'ZAR'");
$paid       = (float) $q("SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE doc_type = 'invoice' AND status = 'paid' AND currency = 'ZAR'");
$outstanding= (float) $q("SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE doc_type = 'invoice' AND status = 'sent' AND currency = 'ZAR'");
$accepted   = (int) $q("SELECT COUNT(*) FROM invoices WHERE doc_type = 'quote' AND status = 'accepted'");
$failed24   = (int) $q('SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at > NOW() - INTERVAL 1 DAY');
$onboarded  = (int) $q("SELECT COUNT(*) FROM users WHERE company_name <> '' AND address <> '' AND phone <> ''");
$withDoc    = (int) $q('SELECT COUNT(DISTINCT user_id) FROM invoices');

// Daily series for the last 30 days, zero-filled.
function daily(PDO $db, string $table): array
{
    $rows = $db->query("SELECT DATE(created_at) d, COUNT(*) c FROM {$table} WHERE created_at >= CURDATE() - INTERVAL 29 DAY GROUP BY d")->fetchAll(PDO::FETCH_KEY_PAIR);
    $out = [];
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $out[$d] = (int) ($rows[$d] ?? 0);
    }
    return $out;
}
$signupSeries = daily($db, 'users');
$docSeries    = daily($db, 'invoices');

$sources = $db->query("SELECT COALESCE(NULLIF(signup_source,''),'Direct / unknown') s, COUNT(*) c FROM users GROUP BY s ORDER BY c DESC LIMIT 6")->fetchAll();
$methods = $db->query('SELECT signup_method m, COUNT(*) c FROM users GROUP BY m ORDER BY c DESC')->fetchAll();
$recentUsers = $db->query('SELECT id, email, full_name, company_name, signup_method, avatar_url, created_at FROM users ORDER BY created_at DESC LIMIT 6')->fetchAll();
$recentDocs  = $db->query('SELECT i.id, i.invoice_number, i.doc_type, i.client_name, i.total_amount, i.currency, i.status, i.created_at, u.email
                           FROM invoices i JOIN users u ON u.id = i.user_id ORDER BY i.created_at DESC LIMIT 6')->fetchAll();

$pct = fn(int $a, int $b) => $b > 0 ? round($a / $b * 100) : 0;
$delta = $newPrev7 > 0 ? round(($new7 - $newPrev7) / $newPrev7 * 100) : null;

adminOpen('dashboard', 'Dashboard', 'Good ' . (date('H') < 12 ? 'morning' : (date('H') < 18 ? 'afternoon' : 'evening')) . '. Here\'s how ' . APP_NAME . ' is doing.',
    '<a class="btn btn-ghost" href="users.php?export=1"><i class="fa-solid fa-download"></i> Export users</a><a class="btn btn-primary" href="marketing.php"><i class="fa-solid fa-bullhorn"></i> New post</a>');
?>

<div class="kpis">
  <div class="kpi"><div class="kpi__ico"><i class="fa-solid fa-users"></i></div><div>
    <div class="kpi__label">Total users</div><div class="kpi__value"><?= number_format($users) ?></div>
    <div class="kpi__delta"><b>+<?= $new7 ?></b> this week<?= $delta !== null ? ' · ' . ($delta >= 0 ? '▲' : '▼') . abs($delta) . '% vs last' : '' ?></div></div></div>
  <div class="kpi kpi--violet"><div class="kpi__ico"><i class="fa-solid fa-file-invoice"></i></div><div>
    <div class="kpi__label">Documents</div><div class="kpi__value"><?= number_format($invoices + $quotes) ?></div>
    <div class="kpi__delta"><?= $invoices ?> invoices · <?= $quotes ?> quotes</div></div></div>
  <div class="kpi kpi--mint"><div class="kpi__ico"><i class="fa-solid fa-sack-dollar"></i></div><div>
    <div class="kpi__label">Paid (ZAR)</div><div class="kpi__value"><?= moneyZar($paid) ?></div>
    <div class="kpi__delta">of <?= moneyZar($billed) ?> invoiced</div></div></div>
  <div class="kpi kpi--amber"><div class="kpi__ico"><i class="fa-solid fa-hourglass-half"></i></div><div>
    <div class="kpi__label">Outstanding (ZAR)</div><div class="kpi__value"><?= moneyZar($outstanding) ?></div>
    <div class="kpi__delta">sent, not yet paid</div></div></div>
</div>

<div class="grid-2 even">
  <section class="card">
    <div class="card__head"><div><h2>Sign-ups</h2><p>New accounts per day, last 30 days</p></div><b><?= array_sum($signupSeries) ?></b></div>
    <div class="card__body"><div class="chart-box"><canvas id="chSignups" aria-label="Sign-ups per day" role="img"></canvas></div></div>
  </section>
  <section class="card">
    <div class="card__head"><div><h2>Documents created</h2><p>Invoices and quotes per day, last 30 days</p></div><b><?= $docs30 ?></b></div>
    <div class="card__body"><div class="chart-box"><canvas id="chDocs" aria-label="Documents per day" role="img"></canvas></div></div>
  </section>
</div>

<div class="kpis">
  <div class="kpi"><div class="kpi__ico"><i class="fa-solid fa-user-check"></i></div><div><div class="kpi__label">Active this week</div><div class="kpi__value"><?= $active7 ?></div><div class="kpi__delta"><?= $pct($active7, $users) ?>% of users signed in</div></div></div>
  <div class="kpi kpi--violet"><div class="kpi__ico"><i class="fa-solid fa-id-card"></i></div><div><div class="kpi__label">Finished profile</div><div class="kpi__value"><?= $pct($onboarded, $users) ?>%</div><div class="kpi__delta"><?= $onboarded ?> of <?= $users ?> users</div></div></div>
  <div class="kpi kpi--mint"><div class="kpi__ico"><i class="fa-solid fa-paper-plane"></i></div><div><div class="kpi__label">Made a document</div><div class="kpi__value"><?= $pct($withDoc, $users) ?>%</div><div class="kpi__delta"><?= $withDoc ?> users activated</div></div></div>
  <div class="kpi kpi--amber"><div class="kpi__ico"><i class="fa-solid fa-shield-halved"></i></div><div><div class="kpi__label">Failed sign-ins (24 h)</div><div class="kpi__value"><?= $failed24 ?></div><div class="kpi__delta">Quotes accepted: <?= $pct($accepted, $quotes) ?>%</div></div></div>
</div>

<div class="grid-2">
  <section class="card">
    <div class="card__head"><h2>Newest users</h2><a href="users.php">All users →</a></div>
    <?php if (!$recentUsers): ?><div class="empty"><i class="fa-solid fa-user-plus"></i>No sign-ups yet.</div><?php endif; ?>
    <?php foreach ($recentUsers as $u): $n = $u['full_name'] ?: ($u['company_name'] ?: $u['email']); ?>
      <a class="list-row" href="users.php?id=<?= $u['id'] ?>" style="color:inherit;text-decoration:none">
        <div class="avatar"><?php if ($u['avatar_url']): ?><img src="<?= e($u['avatar_url']) ?>" alt="" referrerpolicy="no-referrer"><?php else: ?><?= e(strtoupper(mb_substr($n, 0, 1))) ?><?php endif; ?></div>
        <div class="list-row__main"><strong><?= e($n) ?></strong><small><?= e($u['email']) ?></small></div>
        <div class="list-row__side"><?= $u['signup_method'] === 'google' ? '<span class="tag tag-google"><i class="fa-brands fa-google"></i> Google</span><br>' : '' ?><?= timeAgo($u['created_at']) ?></div>
      </a>
    <?php endforeach; ?>
  </section>
  <section class="card">
    <div class="card__head"><h2>Where sign-ups come from</h2><a href="marketing.php">Tracking →</a></div>
    <div class="card__body">
      <?php $max = max(1, ...array_map(fn($r) => (int) $r['c'], $sources ?: [['c' => 1]])); ?>
      <?php foreach ($sources as $s): ?>
        <div class="bar-row"><span title="<?= e($s['s']) ?>" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($s['s']) ?></span><div class="track"><span style="width:<?= round($s['c'] / $max * 100) ?>%"></span></div><b><?= (int) $s['c'] ?></b></div>
      <?php endforeach; ?>
      <p class="hint" style="margin-top:1rem">Sign-up method:
        <?= implode(' · ', array_map(fn($m) => e(ucfirst($m['m'])) . ' ' . (int) $m['c'], $methods)) ?>.
        Tag ad links with <code>?utm_source=facebook&amp;utm_campaign=…</code> to see them here.</p>
    </div>
  </section>
</div>

<section class="card">
  <div class="card__head"><h2>Latest invoices & quotes</h2><a href="documents.php">All documents →</a></div>
  <?php if (!$recentDocs): ?><div class="empty"><i class="fa-regular fa-file-lines"></i>No documents yet.</div><?php else: ?>
  <table class="tbl">
    <thead><tr><th>Document</th><th>Client</th><th>Owner</th><th class="num">Amount</th><th>Status</th><th>Created</th></tr></thead>
    <tbody>
    <?php foreach ($recentDocs as $d): ?>
      <tr>
        <td class="cell-main"><strong><?= e($d['invoice_number']) ?></strong> <span class="muted"><?= e(ucfirst($d['doc_type'])) ?></span></td>
        <td data-label="Client"><?= e($d['client_name'] ?: '—') ?></td>
        <td data-label="Owner" class="muted"><?= e($d['email']) ?></td>
        <td data-label="Amount" class="num"><?= e($d['currency']) ?> <?= number_format((float) $d['total_amount'], 2) ?></td>
        <td data-label="Status"><?= statusPill($d['status']) ?></td>
        <td data-label="Created" class="muted"><?= timeAgo($d['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<?php
$labels = array_map(fn($d) => date('j M', strtotime($d)), array_keys($signupSeries));
adminClose('<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function () {
  var labels = ' . json_encode($labels) . ';
  var charts = [];
  function css(v) { return getComputedStyle(document.documentElement).getPropertyValue(v).trim(); }
  function draw() {
    charts.forEach(function (c) { c.destroy(); }); charts = [];
    var grid = css("--line"), ink = css("--text-3"), font = { family: "Plus Jakarta Sans", size: 11 };
    var base = {
      responsive: true, maintainAspectRatio: false, interaction: { mode: "index", intersect: false },
      plugins: { legend: { display: false }, tooltip: { backgroundColor: css("--ink"), padding: 10, cornerRadius: 8, displayColors: false } },
      scales: {
        x: { grid: { display: false }, border: { display: false }, ticks: { color: ink, font: font, maxTicksLimit: 6, maxRotation: 0 } },
        y: { beginAtZero: true, grid: { color: grid }, border: { display: false }, ticks: { color: ink, font: font, precision: 0, maxTicksLimit: 5 } }
      }
    };
    var brand = css("--brand");
    charts.push(new Chart(document.getElementById("chSignups"), { type: "line", options: base, data: { labels: labels, datasets: [{
      label: "Sign-ups", data: ' . json_encode(array_values($signupSeries)) . ', borderColor: brand, borderWidth: 2, tension: .35, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: brand,
      fill: true, backgroundColor: function (ctx) { var g = ctx.chart.ctx.createLinearGradient(0, 0, 0, 240); g.addColorStop(0, brand + "33"); g.addColorStop(1, brand + "00"); return g; } }] } }));
    charts.push(new Chart(document.getElementById("chDocs"), { type: "bar", options: base, data: { labels: labels, datasets: [{
      label: "Documents", data: ' . json_encode(array_values($docSeries)) . ', backgroundColor: css("--violet"), borderRadius: 4, borderSkipped: "bottom", maxBarThickness: 14 }] } }));
  }
  draw();
  document.addEventListener("themechange", draw);
})();
</script>');
