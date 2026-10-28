<?php
declare(strict_types=1);

/**
 * Shared <head> for every page: fonts, icons, the design system, the theme
 * (light / dark, remembered per browser) and the marketing pixels.
 */
function pageHead(string $title, array $opt = []): void
{
    $css      = $opt['css'] ?? ['style.css'];
    $robots   = $opt['noindex'] ?? true;
    $desc     = $opt['description'] ?? APP_TAGLINE;
    $tracking = $opt['tracking'] ?? true;
    $v        = '?v=' . (string) @filemtime(BASE_DIR . '/assets/css/style.css');
    ?>
<!DOCTYPE html>
<html lang="en-ZA">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e($desc) ?>">
  <?php if ($robots): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
  <meta name="theme-color" content="#0e1726">
  <meta property="og:title" content="<?= e($title) ?>">
  <meta property="og:description" content="<?= e($desc) ?>">
  <meta property="og:type" content="website">
  <meta property="og:url" content="<?= e(APP_URL) ?>">
  <link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/assets/img/mark.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700;12..96,800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <?php foreach ($css as $file): ?>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/<?= e($file) . $v ?>">
  <?php endforeach; ?>
  <script>try{var t=localStorage.getItem('ic-theme');if(t)document.documentElement.dataset.theme=t;}catch(e){}</script>
  <?= $tracking ? trackingHead() : '' ?>
</head>
<?php
}

function brandMark(string $class = ''): string
{
    return '<a href="' . APP_URL . '/" class="brand ' . e($class) . '" aria-label="' . APP_NAME . ' home">'
         . '<img src="' . APP_URL . '/assets/img/mark.svg" alt="" class="brand__mark" width="32" height="32">'
         . '<span class="brand__word">Invoice<em>Chat</em></span></a>';
}

function flashHtml(): string
{
    $out = '';
    foreach (takeFlashes() as [$type, $msg]) {
        $icon = $type === 'error' ? 'fa-circle-exclamation' : ($type === 'success' ? 'fa-circle-check' : 'fa-circle-info');
        $out .= '<div class="alert alert-' . e($type) . '"><i class="fa-solid ' . $icon . '"></i><span>' . e($msg) . '</span></div>';
    }
    return $out;
}

/** Split-screen shell for sign-in, sign-up and reset pages. */
function authOpen(string $title, string $heading, string $sub): void
{
    pageHead($title . ' – ' . APP_NAME);
    ?>
<body class="auth">
  <aside class="auth__brand">
    <?= brandMark('brand--light') ?>
    <div class="auth__pitch">
      <h2>Bill clients in a<br><span>two-minute chat.</span></h2>
      <p>Answer a few questions. Get a branded PDF invoice or quote, and send it by email or WhatsApp — from your phone or desk.</p>
      <div class="auth__demo" aria-hidden="true">
        <div class="demo-bubble bot">Who is this invoice for?</div>
        <div class="demo-bubble me">Acme Studio</div>
        <div class="demo-bubble bot">Added: Logo design × 1 @ R 4 500</div>
        <div class="demo-file"><i class="fa-solid fa-file-pdf"></i><div><strong>INV-2026-00042.pdf</strong><small>Sent via WhatsApp · just now</small></div><i class="fa-solid fa-check-double"></i></div>
      </div>
      <ul class="auth__ticks">
        <li><i class="fa-solid fa-check"></i>5 professional templates</li>
        <li><i class="fa-solid fa-check"></i>Quotes convert to invoices in one tap</li>
        <li><i class="fa-solid fa-check"></i>Free to start — no card needed</li>
      </ul>
    </div>
  </aside>
  <main class="auth__main">
    <div class="auth__card">
      <div class="auth__mobile-brand"><?= brandMark() ?></div>
      <h1><?= e($heading) ?></h1>
      <p class="auth__sub"><?= $sub ?></p>
      <?= flashHtml() ?>
<?php
}

function authClose(): void
{
    ?>
    </div>
    <p class="auth__legal">© <?= date('Y') ?> <?= APP_NAME ?> · <a href="<?= APP_URL ?>/privacy.php">Privacy</a> · <a href="<?= APP_URL ?>/terms.php">Terms</a></p>
  </main>
  <script>
    document.querySelectorAll('[data-toggle-pw]').forEach(function (b) {
      b.addEventListener('click', function () {
        var i = document.getElementById(b.dataset.togglePw);
        i.type = i.type === 'password' ? 'text' : 'password';
        b.innerHTML = i.type === 'password' ? '<i class="fa-regular fa-eye"></i>' : '<i class="fa-regular fa-eye-slash"></i>';
      });
    });
    document.querySelectorAll('form[data-busy]').forEach(function (f) {
      f.addEventListener('submit', function () { var b = f.querySelector('[type=submit]'); if (b) { b.disabled = true; b.classList.add('is-busy'); } });
    });
  </script>
</body>
</html>
<?php
}

function googleButton(string $label = 'Continue with Google'): string
{
    require_once BASE_DIR . '/classes/GoogleAuth.php';
    if (!GoogleAuth::enabled()) return '';
    return '<a class="btn btn-google" href="' . APP_URL . '/auth/google.php">'
         . '<svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>'
         . e($label) . '</a><div class="divider"><span>or</span></div>';
}
