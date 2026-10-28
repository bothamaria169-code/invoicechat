<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

startAppSession();
captureAttribution();
if (isLoggedIn() && getCurrentUser()) { redirect(isAdmin() ? 'admin/' : 'chat.php'); }

pageHead(APP_NAME . ' — Send invoices & quotes from a chat', [
    'noindex' => false,
    'description' => 'Create branded PDF invoices and quotes by chatting, then send them by email or WhatsApp. Free for South African freelancers and small businesses.',
]);
?>
<body>
<header class="site-nav">
  <div class="wrap">
    <?= brandMark() ?>
    <nav>
      <a href="#features" class="hide-sm">Features</a>
      <a href="#how" class="hide-sm">How it works</a>
      <a href="#pricing" class="hide-sm">Pricing</a>
      <a href="login.php">Sign in</a>
      <a href="register.php" class="btn btn-primary">Start free</a>
    </nav>
  </div>
</header>

<section class="hero">
  <div class="wrap">
    <div>
      <span class="eyebrow"><i class="fa-solid fa-bolt"></i> Built for every South African business</span>
      <h1>Invoices & quotes,<br><span>sent in a chat.</span></h1>
      <p class="lead">No spreadsheets, no templates to fight with. Answer a few questions and <?= APP_NAME ?> builds a branded PDF and sends it by email or WhatsApp.</p>
      <div class="hero__cta">
        <a href="register.php" class="btn btn-primary btn-lg">Create my first invoice <i class="fa-solid fa-arrow-right"></i></a>
        <a href="login.php" class="btn btn-ghost btn-lg">Sign in</a>
      </div>
      <div class="hero__proof">
        <span><i class="fa-solid fa-circle-check"></i>Free to start</span>
        <span><i class="fa-solid fa-circle-check"></i>Works on any phone</span>
        <span><i class="fa-solid fa-circle-check"></i>ZAR, VAT & bank details</span>
      </div>
    </div>

    <div style="position:relative">
      <div class="phone" aria-hidden="true">
        <div class="phone__screen">
          <div class="phone__bar">
            <img src="assets/img/mark.svg" alt="">
            <div><strong><?= APP_NAME ?></strong><small>● online</small></div>
          </div>
          <div class="phone__body">
            <div class="demo-bubble bot">Hi Thandi 👋 Who's this invoice for?</div>
            <div class="demo-bubble me">Acme Studio</div>
            <div class="demo-bubble bot">Added <strong>Logo design × 1 @ R 4 500</strong>. Anything else?</div>
            <div class="demo-file"><i class="fa-solid fa-file-pdf"></i><div><strong>INV-2026-00042.pdf</strong><small>R 5 175.00 incl. VAT</small></div></div>
            <div class="phone__chips"><span>Send by Email</span><span>Send via WhatsApp</span></div>
          </div>
        </div>
      </div>
      <div class="float-card one"><i class="fa-solid fa-sack-dollar"></i><div>Paid<small>INV-00041 · R 2 300</small></div></div>
      <div class="float-card two"><i class="fa-solid fa-file-signature"></i><div>Quote accepted<small>Converted to invoice</small></div></div>
    </div>
  </div>
</section>

<section class="section" id="features">
  <div class="wrap">
    <div class="section__head">
      <h2>Everything you need to get paid</h2>
      <p>The paperwork side of your business, handled in the time it takes to send a voice note.</p>
    </div>
    <div class="grid-3">
      <div class="feature"><div class="ico"><i class="fa-solid fa-comments"></i></div><h3>Chat, don't fill forms</h3><p>The assistant asks for the client, items and prices one step at a time. Edit or cancel any time.</p></div>
      <div class="feature"><div class="ico"><i class="fa-brands fa-whatsapp"></i></div><h3>Send by WhatsApp or email</h3><p>Deliver the PDF where your clients actually read it, straight from the chat.</p></div>
      <div class="feature"><div class="ico"><i class="fa-solid fa-swatchbook"></i></div><h3>Your brand, 5 templates</h3><p>Add your logo and colour once. Classic, Modern, Freelancer, Corporate or Banded.</p></div>
      <div class="feature"><div class="ico"><i class="fa-solid fa-file-signature"></i></div><h3>Quotes → invoices</h3><p>Send a quote, mark it accepted, and turn it into an invoice with one tap.</p></div>
      <div class="feature"><div class="ico"><i class="fa-solid fa-receipt"></i></div><h3>VAT, discounts & banking</h3><p>Tax, discounts, payment terms and your bank details appear on every document.</p></div>
      <div class="feature"><div class="ico"><i class="fa-solid fa-clock-rotate-left"></i></div><h3>Every document in one place</h3><p>Search past invoices, see what's paid and re-send anything in seconds.</p></div>
    </div>
  </div>
</section>

<section class="section" id="how" style="padding-top:0">
  <div class="wrap">
    <div class="section__head"><h2>Three steps. Two minutes.</h2></div>
    <div class="steps">
      <div class="step"><h3>Create your free account</h3><p>Sign up with email or Google. Tell the assistant your business name, contact and bank details once.</p></div>
      <div class="step"><h3>Chat your invoice</h3><p>Type <strong>/new</strong> and answer: client, items, quantities, prices. Pick a template.</p></div>
      <div class="step"><h3>Send & get paid</h3><p>Download the PDF or send it by email or WhatsApp. Mark it paid when the money lands.</p></div>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/includes/billing.php'; $bc = billingConfig(); ?>
<section class="section" id="pricing" style="padding-top:0">
  <div class="wrap">
    <div class="section__head"><h2>Simple pricing for every business</h2><p>Plumbers, salons, caterers, shops, consultants, builders — if you send invoices or quotes, this is for you. Pay with an OTT voucher from any shop; no card needed.</p></div>
    <div class="grid-3 pricing">
      <div class="feature price">
        <h3>Free</h3><div class="price__amt">R0</div><p>For getting started</p>
        <ul><li><?= $bc['free_docs'] ?> invoices or quotes a month</li><li>Your logo & bank details</li><li>Send by WhatsApp & email</li><li>Free logo maker</li></ul>
        <a href="register.php" class="btn btn-ghost btn-block">Start free</a>
      </div>
      <div class="feature price price--pro">
        <span class="eyebrow" style="margin:0 0 .6rem">Most popular</span>
        <h3>Pro</h3><div class="price__amt">R<?= $bc['pro_price'] ?><small>/month</small></div><p>For busy businesses</p>
        <ul><li><?= $bc['pro_docs'] ?> invoices or quotes a month</li><li>No “Created with InvoiceChat” footer</li><li>✨ AI drafts from one sentence</li><li>All 5 templates & logo maker</li></ul>
        <a href="register.php?utm_source=site&utm_campaign=pro" class="btn btn-primary btn-block">Go Pro</a>
      </div>
      <div class="feature price">
        <h3>Top-ups</h3><div class="price__amt">R<?= $bc['pack_price'] ?><small>/<?= $bc['pack_size'] ?> docs</small></div><p>Only pay for what you use</p>
        <ul><li><?= $bc['pack_size'] ?> extra invoices or quotes</li><li>Credits never expire</li><li>Works on Free or Pro</li><li>Buy with an OTT voucher</li></ul>
        <a href="register.php" class="btn btn-ghost btn-block">Get started</a>
      </div>
    </div>
  </div>
</section>

<section class="section" style="padding-top:0">
  <div class="wrap">
    <div class="cta-band">
      <h2>Your next invoice could be out the door in two minutes.</h2>
      <p>Free to start. No card. Cancel any time.</p>
      <a href="register.php" class="btn btn-primary btn-lg">Start free <i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </div>
</section>

<footer class="site-foot">
  <div class="wrap">
    <span>© <?= date('Y') ?> <?= APP_NAME ?> by SwiftFusion</span>
    <span><a href="privacy.php">Privacy</a><a href="terms.php">Terms</a><a href="mailto:<?= e(ADMIN_EMAIL) ?>">Contact</a></span>
  </div>
</footer>
</body>
</html>
