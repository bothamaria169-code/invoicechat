<?php
  $isQuote     = ($invoice['doc_type'] ?? 'invoice') === 'quote';
  $docLabel    = $isQuote ? 'Quotation' : 'Invoice';
  $statusLabel = ucfirst($invoice['status'] ?? 'draft');
  $dateLabel   = $isQuote ? 'Valid Until' : 'Due Date';
  $accent      = accentColor($user);
  $accentDk    = shade($accent, 0.25);
  $accentLt    = tint($accent, 0.92);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 13px; color: #1a1a2e; background: #fff; }
  .page { padding: 0; }

  /* Top colour bar */
  .top-bar { background: linear-gradient(135deg, <?= $accent ?> 0%, <?= $accentDk ?> 100%); height: 8px; }

  .content { padding: 36px 50px 40px; }

  /* Header row */
  .header { width: 100%; margin-bottom: 40px; }
  .header td { vertical-align: middle; }
  .brand img { max-height: 60px; max-width: 160px; margin-bottom: 6px; }
  .brand-text .company { font-size: 18px; font-weight: 700; color: #1a1a2e; }
  .brand-text .tagline { font-size: 10px; color: #888; margin-top: 2px; }
  .invoice-badge { text-align: right; }
  .invoice-badge .word { font-size: 36px; font-weight: 900; color: #e8e8f0; letter-spacing: -1px; }
  .invoice-badge .number { font-size: 14px; font-weight: 700; color: <?= $accent ?>; margin-top: -6px; }

  /* Two-col info */
  .info-row { width: 100%; margin-bottom: 36px; border-collapse: collapse; }
  .info-row td { vertical-align: top; width: 50%; }
  .info-from { padding-right: 30px; }
  .info-to   { padding-left: 30px; border-left: 2px solid #f0f0f8; }
  .info-label { font-size: 9px; text-transform: uppercase; letter-spacing: 2px; color: <?= $accent ?>; font-weight: 700; margin-bottom: 8px; }
  .info-name  { font-size: 15px; font-weight: 700; margin-bottom: 4px; }
  .info-sub   { font-size: 11px; color: #555; line-height: 1.7; }

  /* Meta pills */
  .meta-row { margin-bottom: 28px; }
  .meta-pill { display: inline-block; background: <?= $accentLt ?>; border-radius: 20px; padding: 6px 14px; font-size: 11px; margin-right: 8px; margin-bottom: 6px; }
  .meta-pill span { color: <?= $accent ?>; font-weight: 700; }

  /* Table */
  .items-table { width: 100%; border-collapse: collapse; margin-bottom: 28px; }
  .items-table thead tr { border-bottom: 2px solid #1a1a2e; }
  .items-table th { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #888; padding: 10px 8px; font-weight: 600; text-align: left; }
  .items-table th:last-child, .items-table td:last-child { text-align: right; }
  .items-table th:nth-child(2), .items-table th:nth-child(3) { text-align: center; }
  .items-table td { padding: 12px 8px; border-bottom: 1px solid #f0f0f8; }
  .items-table td:nth-child(2), .items-table td:nth-child(3) { text-align: center; color: #555; }
  .item-main { font-weight: 600; }
  .item-desc { font-size: 10px; color: #999; margin-top: 2px; }

  /* Totals */
  .totals-wrap { margin-bottom: 32px; overflow: hidden; }
  .totals { float: right; width: 260px; }
  .totals table { width: 100%; border-collapse: collapse; }
  .totals td { padding: 5px 4px; font-size: 12px; border-bottom: 1px solid #f0f0f8; }
  .totals td:last-child { text-align: right; }
  .tot-lbl { color: #888; }
  .tot-grand td { border-bottom: none; border-top: 2px solid <?= $accent ?>; padding-top: 10px; }
  .tot-grand .tot-lbl { font-size: 14px; font-weight: 700; color: #1a1a2e; }
  .tot-grand .tot-val { font-size: 18px; font-weight: 900; color: <?= $accent ?>; }

  /* Footer */
  .footer-row { width: 100%; border-collapse: collapse; }
  .footer-row td { vertical-align: top; width: 50%; padding-right: 20px; }
  .f-box { }
  .f-label { font-size: 9px; text-transform: uppercase; letter-spacing: 2px; color: <?= $accent ?>; font-weight: 700; margin-bottom: 6px; }
  .f-text { font-size: 11px; color: #555; line-height: 1.6; }
  .bottom-bar { margin-top: 40px; border-top: 1px solid #f0f0f8; padding-top: 14px; text-align: center; font-size: 10px; color: #bbb; }
  .status-chip { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; background: <?= $accentLt ?>; color: <?= $accent ?>; margin-left: 8px; }
</style>
</head>
<body>
<div class="top-bar"></div>
<div class="content page">

  <table class="header">
    <tr>
      <td class="brand">
        <?php if ($logo = logoPath($user)): ?>
          <img src="<?= e($logo) ?>" alt="logo"><br>
        <?php endif; ?>
        <div class="brand-text">
          <div class="company"><?= e($user['company_name'] ?? 'Your Company') ?></div>
          <?php if (!empty($user['website'])): ?>
          <div class="tagline"><?= e($user['website']) ?></div>
          <?php endif; ?>
        </div>
      </td>
      <td class="invoice-badge">
        <div class="word"><?= strtoupper(e($docLabel)) ?></div>
        <div class="number"><?= e($invoice['invoice_number']) ?> <span class="status-chip"><?= e($statusLabel) ?></span></div>
      </td>
    </tr>
  </table>

  <table class="info-row">
    <tr>
      <td class="info-from">
        <div class="info-label">From</div>
        <div class="info-name"><?= e($user['company_name'] ?? '') ?></div>
        <div class="info-sub">
          <?= nl2br(e($user['address'] ?? '')) ?><br>
          <?= e($user['phone'] ?? '') ?><br>
          <?= e($user['email'] ?? '') ?>
          <?php if (!empty($user['tax_number'])): ?><br>VAT: <?= e($user['tax_number']) ?><?php endif; ?>
        </div>
      </td>
      <td class="info-to">
        <div class="info-label"><?= $isQuote ? 'Quote For' : 'Bill To' ?></div>
        <div class="info-name"><?= e($invoice['client_name']) ?></div>
        <div class="info-sub">
          <?php if (!empty($invoice['client_email'])): ?><?= e($invoice['client_email']) ?><br><?php endif; ?>
          <?php if (!empty($invoice['client_phone'])): ?><?= e($invoice['client_phone']) ?><?php endif; ?>
        </div>
      </td>
    </tr>
  </table>

  <div class="meta-row">
    <div class="meta-pill">Issue Date: <span><?= e($invoice['issue_date'] ?? date('Y-m-d')) ?></span></div>
    <div class="meta-pill"><?= $isQuote ? 'Valid Until' : 'Due Date' ?>: <span><?= e($invoice['due_date'] ?? '—') ?></span></div>
    <div class="meta-pill">Currency: <span><?= e($invoice['currency']) ?></span></div>
    <?php if ($invoice['tax_rate'] > 0): ?>
    <div class="meta-pill">Tax: <span><?= e($invoice['tax_rate']) ?>%</span></div>
    <?php endif; ?>
  </div>

  <table class="items-table">
    <thead>
      <tr>
        <th style="width:44%">Item</th>
        <th style="width:12%">Qty</th>
        <th style="width:20%">Rate</th>
        <th style="width:20%">Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $item): ?>
      <?php $lt = $item['quantity'] * $item['unit_price']; ?>
      <tr>
        <td>
          <div class="item-main"><?= e($item['product_name']) ?></div>
          <?php if (!empty($item['description'])): ?>
          <div class="item-desc"><?= e($item['description']) ?></div>
          <?php endif; ?>
        </td>
        <td><?= rtrim(rtrim(number_format($item['quantity'],2),'0'),'.') ?></td>
        <td><?= number_format($item['unit_price'], 2) ?></td>
        <td><?= number_format($lt, 2) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php
    $subtotal = array_sum(array_map(fn($i) => $i['quantity'] * $i['unit_price'], $items));
    $discount = $subtotal * ($invoice['discount_percent'] / 100);
    $taxable  = $subtotal - $discount;
    $tax      = $taxable  * ($invoice['tax_rate'] / 100);
    $total    = $taxable  + $tax;
    $cur      = $invoice['currency'];
  ?>
  <div class="totals-wrap">
    <div class="totals">
      <table>
        <tr>
          <td class="tot-lbl">Subtotal</td>
          <td><?= $cur ?>&nbsp;<?= number_format($subtotal,2) ?></td>
        </tr>
        <?php if ($discount > 0): ?>
        <tr>
          <td class="tot-lbl">Discount (<?= e($invoice['discount_percent']) ?>%)</td>
          <td>-<?= $cur ?>&nbsp;<?= number_format($discount,2) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($tax > 0): ?>
        <tr>
          <td class="tot-lbl">Tax (<?= e($invoice['tax_rate']) ?>%)</td>
          <td><?= $cur ?>&nbsp;<?= number_format($tax,2) ?></td>
        </tr>
        <?php endif; ?>
        <tr class="tot-grand">
          <td class="tot-lbl">Total Due</td>
          <td class="tot-val"><?= $cur ?>&nbsp;<?= number_format($total,2) ?></td>
        </tr>
      </table>
    </div>
  </div>

  <table class="footer-row">
    <tr>
      <?php if (!empty($user['bank_details'])): ?>
      <td class="f-box">
        <div class="f-label">Payment Details</div>
        <div class="f-text"><?= nl2br(e($user['bank_details'])) ?></div>
      </td>
      <?php endif; ?>
      <?php if (!empty($invoice['notes'])): ?>
      <td class="f-box">
        <div class="f-label">Notes</div>
        <div class="f-text"><?= nl2br(e($invoice['notes'])) ?></div>
      </td>
      <?php endif; ?>
    </tr>
  </table>

  <div class="bottom-bar">Generated by <?= APP_NAME ?> &bull; Thank you for your business!</div>
</div>
</body>
</html>
