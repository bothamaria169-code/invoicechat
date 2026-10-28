<?php
  $isQuote   = ($invoice['doc_type'] ?? 'invoice') === 'quote';
  $docLabel  = $isQuote ? 'Quotation' : 'Invoice';
  $dateLabel = $isQuote ? 'Valid Until' : 'Due Date';
  $accent    = accentColor($user);
  $accentLt  = tint($accent, 0.90);
  $fillRows  = max(0, 8 - count($items));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 13px; color: #222; background: #fff; }
  .page { padding: 40px 50px; }

  /* Header */
  .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 36px; border-bottom: 3px solid <?= $accent ?>; padding-bottom: 24px; }
  .logo img { max-height: 70px; max-width: 200px; }
  .logo-placeholder { width: 70px; height: 70px; background: <?= $accentLt ?>; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: <?= $accent ?>; }
  .company-info { text-align: right; }
  .company-name { font-size: 20px; font-weight: 700; color: <?= $accent ?>; }
  .company-sub { font-size: 11px; color: #555; margin-top: 4px; line-height: 1.6; }

  /* Invoice title block */
  .invoice-title-block { display: flex; justify-content: space-between; margin-bottom: 30px; }
  .invoice-label { font-size: 28px; font-weight: 700; color: <?= $accent ?>; letter-spacing: 1px; text-transform: uppercase; }
  .invoice-meta { text-align: right; }
  .invoice-meta table { border-collapse: collapse; }
  .invoice-meta td { padding: 3px 6px; font-size: 12px; }
  .invoice-meta td:first-child { color: #666; }
  .invoice-meta td:last-child { font-weight: 600; }

  /* Bill to */
  .bill-to-block { background: <?= $accentLt ?>; border-left: 4px solid <?= $accent ?>; padding: 14px 18px; margin-bottom: 28px; border-radius: 0 6px 6px 0; }
  .bill-to-label { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: <?= $accent ?>; margin-bottom: 6px; font-weight: 700; }
  .bill-to-name { font-size: 16px; font-weight: 700; }
  .bill-to-sub { font-size: 11px; color: #444; margin-top: 3px; }

  /* Items table */
  .items-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
  .items-table th { background: <?= $accent ?>; color: #fff; padding: 10px 12px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; text-align: left; }
  .items-table th:last-child, .items-table td:last-child { text-align: right; }
  .items-table th:nth-child(2), .items-table th:nth-child(3) { text-align: center; }
  .items-table td { padding: 10px 12px; border-bottom: 1px solid #e8ecf0; vertical-align: top; }
  .items-table td:nth-child(2), .items-table td:nth-child(3) { text-align: center; }
  .items-table tr:last-child td { border-bottom: none; }
  .items-table tr:nth-child(even) td { background: #f8f9fd; }
  .item-desc { font-size: 10px; color: #666; margin-top: 3px; }

  /* Totals */
  .totals-block { float: right; width: 280px; border: 1px solid #dde3f0; border-radius: 6px; overflow: hidden; margin-bottom: 30px; }
  .totals-block table { width: 100%; border-collapse: collapse; }
  .totals-block td { padding: 8px 14px; font-size: 13px; }
  .totals-block td:last-child { text-align: right; }
  .tot-sep { border-bottom: 1px solid #e8ecf0; }
  .tot-label { color: #555; }
  .tot-total td { background: <?= $accent ?>; color: #fff; font-weight: 700; font-size: 15px; }

  /* Notes & bank */
  .footer-grid { clear: both; display: flex; gap: 24px; margin-top: 10px; }
  .footer-box { flex: 1; background: #f8f9fd; border: 1px solid #e0e7f0; border-radius: 6px; padding: 14px 16px; }
  .footer-box-title { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: <?= $accent ?>; font-weight: 700; margin-bottom: 6px; }
  .footer-box p { font-size: 11px; color: #444; line-height: 1.6; }

  /* Footer */
  .page-footer { margin-top: 40px; text-align: center; font-size: 10px; color: #999; border-top: 1px solid #e0e7f0; padding-top: 16px; }
  .status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }
  .status-paid     { background: #d1fae5; color: #065f46; }
  .status-accepted { background: #d1fae5; color: #065f46; }
  .status-sent     { background: #dbeafe; color: #1e40af; }
  .status-draft    { background: #fef3c7; color: #92400e; }
  .status-rejected { background: #fee2e2; color: #991b1b; }
</style>
</head>
<body>
<div class="page">

  <!-- Header -->
  <div class="header">
    <div class="logo">
      <?php if ($logo = logoPath($user)): ?>
        <img src="<?= e($logo) ?>" alt="Logo">
      <?php else: ?>
        <div class="logo-placeholder">IC</div>
      <?php endif; ?>
    </div>
    <div class="company-info">
      <div class="company-name"><?= e($user['company_name'] ?? 'Your Company') ?></div>
      <div class="company-sub">
        <?= e($user['address'] ?? '') ?><br>
        <?= e($user['phone'] ?? '') ?><br>
        <?= e($user['email'] ?? '') ?>
        <?php if (!empty($user['website'])): ?><br><?= e($user['website']) ?><?php endif; ?>
        <?php if (!empty($user['tax_number'])): ?><br>Tax/VAT: <?= e($user['tax_number']) ?><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Invoice title -->
  <div class="invoice-title-block">
    <div>
      <div class="invoice-label"><?= e($docLabel) ?></div>
      <span class="status-badge status-<?= e($invoice['status']) ?>"><?= e($invoice['status']) ?></span>
    </div>
    <div class="invoice-meta">
      <table>
        <tr><td><?= $isQuote ? 'Quote #' : 'Invoice #' ?></td><td><?= e($invoice['invoice_number']) ?></td></tr>
        <tr><td>Issue Date</td><td><?= e($invoice['issue_date'] ?? date('Y-m-d')) ?></td></tr>
        <tr><td><?= e($dateLabel) ?></td><td><?= e($invoice['due_date'] ?? '') ?></td></tr>
        <tr><td>Currency</td><td><?= e($invoice['currency']) ?></td></tr>
      </table>
    </div>
  </div>

  <!-- Bill to -->
  <div class="bill-to-block">
    <div class="bill-to-label"><?= $isQuote ? 'Quote For' : 'Bill To' ?></div>
    <div class="bill-to-name"><?= e($invoice['client_name']) ?></div>
    <div class="bill-to-sub">
      <?php if (!empty($invoice['client_email'])): ?><?= e($invoice['client_email']) ?><br><?php endif; ?>
      <?php if (!empty($invoice['client_phone'])): ?><?= e($invoice['client_phone']) ?><?php endif; ?>
    </div>
  </div>

  <!-- Items -->
  <table class="items-table">
    <thead>
      <tr>
        <th style="width:40%">Description</th>
        <th style="width:12%">Qty</th>
        <th style="width:20%">Unit Price</th>
        <th style="width:20%">Amount</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $item): ?>
      <?php $lineTotal = $item['quantity'] * $item['unit_price']; ?>
      <tr>
        <td>
          <?= e($item['product_name']) ?>
          <?php if (!empty($item['description'])): ?>
            <div class="item-desc"><?= e($item['description']) ?></div>
          <?php endif; ?>
        </td>
        <td><?= rtrim(rtrim(number_format($item['quantity'], 2), '0'), '.') ?></td>
        <td><?= number_format($item['unit_price'], 2) ?></td>
        <td><?= number_format($lineTotal, 2) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php for ($f = 0; $f < $fillRows; $f++): ?>
      <tr class="filler"><td>&nbsp;</td><td></td><td></td><td></td></tr>
      <?php endfor; ?>
    </tbody>
  </table>

  <!-- Totals -->
  <?php
    $subtotal = array_sum(array_map(fn($i) => $i['quantity'] * $i['unit_price'], $items));
    $discount = $subtotal * ($invoice['discount_percent'] / 100);
    $taxable  = $subtotal - $discount;
    $tax      = $taxable * ($invoice['tax_rate'] / 100);
    $total    = $taxable + $tax;
    $cur      = $invoice['currency'];
  ?>
  <div class="totals-block">
    <table>
      <tr class="tot-sep">
        <td class="tot-label">Subtotal</td>
        <td><?= $cur ?>&nbsp;<?= number_format($subtotal, 2) ?></td>
      </tr>
      <?php if ($discount > 0): ?>
      <tr class="tot-sep">
        <td class="tot-label">Discount (<?= e($invoice['discount_percent']) ?>%)</td>
        <td>-<?= $cur ?>&nbsp;<?= number_format($discount, 2) ?></td>
      </tr>
      <?php endif; ?>
      <?php if ($tax > 0): ?>
      <tr class="tot-sep">
        <td class="tot-label">Tax (<?= e($invoice['tax_rate']) ?>%)</td>
        <td><?= $cur ?>&nbsp;<?= number_format($tax, 2) ?></td>
      </tr>
      <?php endif; ?>
      <tr class="tot-total">
        <td>Total</td>
        <td><?= $cur ?>&nbsp;<?= number_format($total, 2) ?></td>
      </tr>
    </table>
  </div>

  <!-- Notes & Bank -->
  <div class="footer-grid">
    <?php if (!empty($invoice['notes'])): ?>
    <div class="footer-box">
      <div class="footer-box-title">Notes / Terms</div>
      <p><?= nl2br(e($invoice['notes'])) ?></p>
    </div>
    <?php endif; ?>
    <?php if (!empty($user['bank_details'])): ?>
    <div class="footer-box">
      <div class="footer-box-title">Payment Details</div>
      <p><?= nl2br(e($user['bank_details'])) ?></p>
    </div>
    <?php endif; ?>
  </div>

  <div class="page-footer">
    Thank you for your business! &mdash; Generated by <?= APP_NAME ?>
  </div>
</div>
</body>
</html>
