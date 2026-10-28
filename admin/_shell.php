<?php
declare(strict_types=1);

/**
 * Admin dashboard shell: sidebar, top bar and page header. Pages call
 * adminOpen() after handling their POSTs (which redirect), then adminClose().
 */
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/layout.php';

startAppSession();
requireAdmin();
$ADMIN = getCurrentUser();

/** Every admin POST must carry the CSRF token. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
}

function adminOpen(string $active, string $title, string $sub = '', string $actions = ''): void
{
    global $ADMIN;
    $db = getDB();
    $newUsers = (int) $db->query('SELECT COUNT(*) FROM users WHERE created_at > (NOW() - INTERVAL 1 DAY)')->fetchColumn();
    $queued   = (int) $db->query("SELECT COUNT(*) FROM marketing_posts WHERE status = 'scheduled'")->fetchColumn();
    $vouchers = (int) $db->query("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn();
    $toCall   = (int) $db->query("SELECT COUNT(*) FROM leads WHERE status = 'to_call' AND (call_after IS NULL OR call_after <= NOW())")->fetchColumn();

    $nav = [
        'Overview' => [
            ['dashboard', 'index.php',     'fa-gauge-high',   'Dashboard', 0],
            ['users',     'users.php',     'fa-users',        'Users',     $newUsers],
            ['documents', 'documents.php', 'fa-file-invoice', 'Invoices & quotes', 0],
            ['payments',  'payments.php',  'fa-money-bill-wave', 'Payments', $vouchers],
        ],
        'Growth' => [
            ['leads',     'leads.php',     'fa-address-book', 'Leads & calls', $toCall],
            ['campaigns', 'campaigns.php', 'fa-envelopes-bulk', 'Email campaigns', 0],
            ['marketing', 'marketing.php', 'fa-bullhorn',     'Social & ads', $queued],
        ],
        'Setup' => [
            ['settings', 'settings.php', 'fa-sliders',   'Settings',   0],
            ['account',  'account.php',  'fa-user-gear', 'My account', 0],
        ],
    ];
    $name = $ADMIN['full_name'] ?: $ADMIN['email'];
    pageHead($title . ' – Admin – ' . APP_NAME, ['css' => ['style.css', 'admin.css'], 'tracking' => false]);
    ?>
<body class="adm">
<div class="adm-scrim" id="admScrim"></div>
<aside class="adm-side" id="admSide">
  <?= brandMark('brand--light') ?>
  <?php foreach ($nav as $group => $links): ?>
    <div class="adm-side__label"><?= e($group) ?></div>
    <?php foreach ($links as [$slug, $href, $icon, $label, $count]): ?>
      <a href="<?= $href ?>" class="<?= $active === $slug ? 'is-active' : '' ?>">
        <i class="fa-solid <?= $icon ?>"></i><span><?= e($label) ?></span>
        <?php if ($count): ?><b class="n"><?= (int) $count ?></b><?php endif; ?>
      </a>
    <?php endforeach; ?>
  <?php endforeach; ?>
  <div class="adm-side__foot">
    <a href="<?= APP_URL ?>/chat.php"><i class="fa-solid fa-comments"></i><span>Open the chat app</span></a>
    <a href="<?= APP_URL ?>/" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i><span>View website</span></a>
  </div>
</aside>

<div class="adm-main">
  <header class="adm-top">
    <button class="icon-btn adm-burger" id="admBurger" aria-label="Menu"><i class="fa-solid fa-bars"></i></button>
    <form class="adm-search" action="users.php" method="get" role="search">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="search" name="q" placeholder="Search users by email, name or company…" value="<?= e($_GET['q'] ?? '') ?>">
    </form>
    <div class="adm-top__right">
      <button class="icon-btn" id="themeBtn" title="Switch light / dark"><i class="fa-solid fa-circle-half-stroke"></i></button>
      <div class="adm-me">
        <div class="avatar"><?= e(strtoupper(mb_substr($name, 0, 1))) ?></div>
        <div class="adm-me__txt"><strong><?= e($name) ?></strong><small>Administrator</small></div>
      </div>
      <form method="POST" action="<?= APP_URL ?>/logout.php"><?= csrfField() ?><button class="icon-btn" title="Sign out"><i class="fa-solid fa-arrow-right-from-bracket"></i></button></form>
    </div>
  </header>

  <main class="adm-body">
    <div class="adm-head">
      <div><h1><?= e($title) ?></h1><?php if ($sub): ?><p><?= $sub ?></p><?php endif; ?></div>
      <?php if ($actions): ?><div class="adm-head__actions"><?= $actions ?></div><?php endif; ?>
    </div>
    <?= flashHtml() ?>
<?php
}

function adminClose(string $scripts = ''): void
{
    ?>
  </main>
</div>
<script>
  (function () {
    var side = document.getElementById('admSide'), scrim = document.getElementById('admScrim');
    document.getElementById('admBurger').onclick = function () { side.classList.add('open'); scrim.classList.add('show'); };
    scrim.onclick = function () { side.classList.remove('open'); scrim.classList.remove('show'); };
    document.getElementById('themeBtn').onclick = function () {
      var r = document.documentElement, dark = r.dataset.theme ? r.dataset.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
      r.dataset.theme = dark ? 'light' : 'dark';
      try { localStorage.setItem('ic-theme', r.dataset.theme); } catch (e) {}
      document.dispatchEvent(new Event('themechange'));
    };
    document.querySelectorAll('form[data-confirm]').forEach(function (f) {
      f.addEventListener('submit', function (e) { if (!confirm(f.dataset.confirm)) e.preventDefault(); });
    });
    document.querySelectorAll('[data-copy]').forEach(function (b) {
      b.addEventListener('click', function () { navigator.clipboard.writeText(b.dataset.copy); b.innerHTML = '<i class="fa-solid fa-check"></i>'; });
    });
  })();
</script>
<?= $scripts ?>
</body>
</html>
<?php
}

function statusPill(string $status): string
{
    return '<span class="pill badge-' . e($status) . '">' . e($status) . '</span>';
}

function moneyZar(float $v): string
{
    return 'R ' . number_format($v, 2, '.', ' ');
}

function timeAgo(?string $dt): string
{
    if (!$dt) return 'never';
    $s = time() - strtotime($dt);
    return match (true) {
        $s < 60     => 'just now',
        $s < 3600   => floor($s / 60) . ' min ago',
        $s < 86400  => floor($s / 3600) . ' h ago',
        $s < 604800 => floor($s / 86400) . ' d ago',
        default     => date('d M Y', strtotime($dt)),
    };
}
