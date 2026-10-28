<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

startAppSession();
pageHead('Terms of Use – ' . APP_NAME, ['noindex' => false]);
?>
<body>
<header class="site-nav"><div class="wrap"><?= brandMark() ?><nav><a href="login.php">Sign in</a><a href="register.php" class="btn btn-primary">Start free</a></nav></div></header>
<main class="wrap prose">
  <h1>Terms of Use</h1>
  <p>Last updated <?= date('j F Y', filemtime(__FILE__)) ?>. By creating an <?= APP_NAME ?> account you agree to these terms.</p>

  <h2>The service</h2>
  <p><?= APP_NAME ?> helps you create invoices and quotes and send them to your clients. You are responsible for the accuracy of the documents you create, including prices, tax and banking details.</p>

  <h2>Your account</h2>
  <p>Keep your password private. You are responsible for activity on your account. Tell us straight away at <a href="mailto:<?= e(ADMIN_EMAIL) ?>"><?= e(ADMIN_EMAIL) ?></a> if you think someone else has access.</p>

  <h2>Acceptable use</h2>
  <p>Don't use <?= APP_NAME ?> to send fraudulent invoices, spam, or anything unlawful. We may suspend accounts that do.</p>

  <h2>Your content</h2>
  <p>You own your documents and business information. You allow us to store and process them only to provide the service.</p>

  <h2>Availability and liability</h2>
  <p>We work to keep the service running but can't promise it will always be available or error-free. To the extent the law allows, we aren't liable for indirect losses from using the service.</p>

  <h2>Changes</h2>
  <p>We may update these terms; we'll email you about significant changes. Continuing to use the service means you accept them.</p>
</main>
</body>
</html>
