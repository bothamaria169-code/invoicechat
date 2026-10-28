<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/billing.php';
require_once __DIR__ . '/includes/layout.php';

startAppSession();
requireLogin();

$user = getCurrentUser();
if (!$user) { redirect('login.php'); }

$displayName = $user['company_name'] ?: ($user['full_name'] ?: $user['email']);
$initials    = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($displayName)) ?: ['U'], 0, 2))));

pageHead(APP_NAME);
?>
<body class="app-body">

<!-- Hidden file input for logo upload -->
<input type="file" id="logoFileInput" accept="image/png,image/jpeg,image/gif,image/webp" hidden>

<div class="app">

  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <!-- ── Sidebar ──────────────────────────────────────────────── -->
  <aside class="sidebar" id="sidebar" aria-label="Your documents">
    <div class="sidebar-header">
      <?= brandMark() ?>
      <button class="btn-new-chat" id="newChatBtn"><i class="fa-solid fa-plus"></i> New</button>
    </div>

    <div class="sidebar-search">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="search" id="sidebarSearch" placeholder="Search client or number…" aria-label="Search documents">
    </div>

    <div class="sidebar-tabs" role="tablist">
      <button class="is-on" data-filter="all">All</button>
      <button data-filter="invoice">Invoices</button>
      <button data-filter="quote">Quotes</button>
      <button data-filter="unpaid">Unpaid</button>
    </div>

    <div class="chat-list" id="chatList"></div>

    <?php $use = usageFor($user); ?>
    <a class="plan-card" href="billing.php">
      <div><strong><?= $use['plan'] ?> plan</strong><small id="usageBadge"><?= $use['left'] ?>/<?= $use['allowance'] ?> documents left · <?= $use['credits'] ?> credits</small></div>
      <span><?= $use['pro'] ? 'Top up' : 'Upgrade' ?> <i class="fa-solid fa-arrow-right"></i></span>
    </a>
    <a class="plan-card plan-card--soft" href="dashboard.php"><div><strong><i class="fa-solid fa-gauge"></i> My dashboard</strong><small>Clients, unpaid invoices & who opened them</small></div><span><i class="fa-solid fa-arrow-right"></i></span></a>
    <a class="plan-card plan-card--soft" href="logo.php"><div><strong><i class="fa-solid fa-palette"></i> Logo maker</strong><small>Make a logo from your initials</small></div><span><i class="fa-solid fa-arrow-right"></i></span></a>

    <div class="sidebar-footer">
      <div class="avatar"><?php if (!empty($user['avatar_url'])): ?><img src="<?= e($user['avatar_url']) ?>" alt="" referrerpolicy="no-referrer"><?php else: ?><?= e($initials) ?><?php endif; ?></div>
      <div class="sidebar-user">
        <strong><?= e($displayName) ?></strong>
        <small><?= e($user['email']) ?></small>
      </div>
      <?php if ($user['role'] === 'admin'): ?>
        <a class="icon-btn" href="admin/" title="Admin dashboard"><i class="fa-solid fa-gauge-high"></i></a>
      <?php endif; ?>
      <button class="icon-btn" id="themeBtn" title="Switch light / dark"><i class="fa-solid fa-circle-half-stroke"></i></button>
      <form method="POST" action="logout.php">
        <?= csrfField() ?>
        <button class="icon-btn" title="Sign out"><i class="fa-solid fa-arrow-right-from-bracket"></i></button>
      </form>
    </div>
  </aside>

  <!-- ── Main chat ─────────────────────────────────────────────── -->
  <main class="chat-main">

    <header class="chat-header">
      <div class="chat-header-left">
        <button class="icon-btn mobile-menu-btn" id="mobileMenuBtn" aria-label="Open documents"><i class="fa-solid fa-bars"></i></button>
        <div class="chat-header-icon" id="chatIcon"><i class="fa-solid fa-robot"></i></div>
        <div style="min-width:0">
          <div class="chat-header-title" id="chatTitle">Assistant</div>
          <div class="chat-header-sub"   id="chatSubtitle"><span class="online">● online</span> · creates invoices & quotes</div>
        </div>
      </div>
      <div class="chat-header-actions" id="headerActions"></div>
    </header>

    <div class="messages-area" id="messagesArea" aria-live="polite"></div>

    <div class="typing-indicator" id="typingIndicator">
      <div class="bot-avatar"></div>
      <div class="typing-dots"><span></span><span></span><span></span></div>
    </div>

    <div class="input-area">
      <div class="input-area-inner">
        <div class="quick-actions">
          <button class="quick-action-btn" data-msg="/new"><i class="fa-solid fa-file-invoice"></i> New invoice</button>
          <button class="quick-action-btn" data-msg="/quote"><i class="fa-solid fa-file-signature"></i> New quote</button>
          <button class="quick-action-btn" data-fill="/ai "><i class="fa-solid fa-wand-magic-sparkles"></i> Describe it</button>
          <button class="quick-action-btn" data-msg="/history"><i class="fa-solid fa-clock-rotate-left"></i> History</button>
          <button class="quick-action-btn" data-msg="/settings"><i class="fa-solid fa-sliders"></i> Business profile</button>
          <button class="quick-action-btn" data-msg="/help"><i class="fa-regular fa-circle-question"></i> Help</button>
          <button class="quick-action-btn" data-msg="/cancel"><i class="fa-solid fa-xmark"></i> Cancel</button>
        </div>
        <div class="input-bar">
          <textarea id="messageInput" rows="1" placeholder="Type a message or / for commands…" autocomplete="off" maxlength="2000" aria-label="Message"></textarea>
          <button id="sendBtn" aria-label="Send"><i class="fa-solid fa-paper-plane"></i></button>
        </div>
        <div class="input-hint">Enter to send · Shift + Enter for a new line</div>
      </div>
    </div>

  </main>
</div>

<div class="toast" id="toast" role="status"></div>

<script>window.IC = { csrf: <?= json_encode(csrfToken()) ?> };</script>
<script src="assets/js/chat.js?v=<?= filemtime(__DIR__ . '/assets/js/chat.js') ?>"></script>
</body>
</html>
