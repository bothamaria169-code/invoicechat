<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

startAppSession();
pageHead('Privacy Policy – ' . APP_NAME, ['noindex' => false]);
?>
<body>
<header class="site-nav"><div class="wrap"><?= brandMark() ?><nav><a href="login.php">Sign in</a><a href="register.php" class="btn btn-primary">Start free</a></nav></div></header>
<main class="wrap prose">
  <h1>Privacy Policy</h1>
  <p>Last updated <?= date('j F Y', filemtime(__FILE__)) ?>. <?= APP_NAME ?> is operated by SwiftFusion, South Africa. We process personal information in line with the Protection of Personal Information Act (POPIA).</p>

  <h2>What we collect</h2>
  <ul>
    <li><strong>Account details:</strong> your name, email address and, if you use Google sign-in, your Google profile name and photo.</li>
    <li><strong>Business details you enter:</strong> company name, address, phone, website, tax number, bank details and logo, used to print on your documents.</li>
    <li><strong>Documents:</strong> invoices and quotes, including your clients' names, emails and phone numbers that you enter.</li>
    <li><strong>Usage data:</strong> sign-in times, IP address, browser, and how you found us (for example an ad or link).</li>
  </ul>

  <h2>How we use it</h2>
  <p>To run your account, create and send your documents, keep the service secure (for example blocking repeated failed sign-ins), send service emails such as password resets, and measure which of our ads bring sign-ups.</p>

  <h2>Advertising and analytics</h2>
  <p>Our public pages may use the Meta (Facebook/Instagram) Pixel, the TikTok Pixel and Google Analytics. When you create an account we tell these platforms a sign-up happened, sending only a one-way scrambled (hashed) version of your email so they can match it to an ad. We never sell your information or share your documents or clients' details with advertisers.</p>

  <h2>Who we share with</h2>
  <p>Only the services needed to deliver what you ask for: our hosting provider, our email provider, and WhatsApp (Meta) when you choose to send a document by WhatsApp.</p>

  <h2>Your rights</h2>
  <p>You can ask to see, correct or delete your information at any time. Email <a href="mailto:<?= e(ADMIN_EMAIL) ?>"><?= e(ADMIN_EMAIL) ?></a>. Deleting your account deletes your documents and chat history.</p>

  <h2>Data deletion</h2>
  <p>To delete data connected through Facebook or Google sign-in, email <a href="mailto:<?= e(ADMIN_EMAIL) ?>"><?= e(ADMIN_EMAIL) ?></a> with the subject “Delete my data”. We remove it within 30 days and confirm by email.</p>
</main>
</body>
</html>
