<?php
  $isQuote   = ($invoice['doc_type'] ?? 'invoice') === 'quote';
  $docLabel  = $isQuote ? 'Quotation' : 'Invoice';
  $dateLabel = $isQuote ? 'Valid Until' : 'Due Date';
  $accent    = accentColor($user);
  $accentLt  = tint($accent, 0.88);
  $accentLt2 = tint($accent, 0.95);
  $fillRows  = max(0, 8 - count($items));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 12px; color: #2d2d2d; background: #fff; }
  .page { padding: 32px 44px; }

  /* Compact header */
  .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 28px; padding-bottom: 20px; border-bottom: 2px solid <?= $accent ?>; }
  .sender { flex: 1; }
  .sender .name { font-size: 17px; font-weight: 800; color: <?= $accent ?>; }
  .sender .sub { font-size: 10px; color: #666; margin-top: 3px; line-height: 1.6; }
  .logo-area { text-align: right; }
  .logo-area img { max-height: 55px; max-width: 140px; }
  .invoice-ref { text-align: right; }
  .inv-num { font-size: 22px; font-weight: 900; color: #2d2d2d; }
  .inv-date { font-size: 10px; color: #888; margin-top: 2px; }
  .badge { display: inline-block; background: <?= $accent ?>; color: #fff; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 2px 8px; border-radius: 3px; margin-top: 4px; }

  /* Client & dates in one compact row */
  .meta-strip { display: flex; gap: 0; background: <?= $accentLt2 ?>; border: 1px solid <?= $accentLt ?>; border-radius: 6px; margin-bottom: 22px; overflow: hidden; }
  .meta-cell { flex: 1; padding: 10px 14px; border-right: 1px solid <?= $accentLt ?>; }
  .meta-cell:last-child { border-right: none; }
  .mc-label { font-size: 8px; text-transform: uppercase; letter-spacing: 1.5px; color: <?= $accent ?>; font-weight: 700; margin-bottom: 4px; }
  .mc-value { font-size: 12px; font-weight: 600; }

  /* Items */
  .items-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
  .items-table th { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: <?= $accent ?>; border-bottom: 2px solid <?= $accent ?>; padding: 8px 6px; text-align: left; }
  .items-table th:last-child, .items-table td:last-child { text-align: right; }
  .items-table th:nth-child(2), .items-table th:nth-child(3) { text-align: center; }
  .items-table td { padding: 8px 6px; border-bottom: 1px dashed #ddd; font-size: 11px; }
  .items-table td:nth-child(2), .items-table td:nth-child(3) { text-align: center; color: #666; }
  .item-desc { font-size: 9px; color: #aaa; margin-top: 2px; }

  /* Summary block */
  .summary-wrap { overflow: hidden; margin-bottom: 20px; }
  .summary-table { float: right; border-collapse: collapse; min-width: 200px; }
  .summary-table td { padding: 7px 14px; border-bottom: 1px solid <?= $accentLt ?>; font-size: 11px; }
  .summary-table td:last-child { text-align: right; font-weight: 700; }
  .sum-lbl { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: <?= $accent ?>; }
  .sum-total td { background: <?= $accent ?>; color: #fff; font-size: 13px; font-weight: 800; border-bottom: none; }

  /* Two-col footer */
  .footer-cols { display: flex; gap: 16px; }
  .fc { flex: 1; border: 1px solid #e0e0e0; border-radius: 6px; padding: 12px 14px; }
  .fc-title { font-size: 9px; text-transform: uppercase; letter-spacing: 1.5px; color: <?= $accent ?>; font-weight: 700; margin-bottom: 6px; }
  .fc-body { font-size: 10px; color: #555; line-height: 1.7; }
  .bottom { margin-top: 28px; text-align: center; font-size: 9px; color: #ccc; }
</style>
</head>
<body>
<div class="page">

  <div class="header">
    <div class="sender">
      <div class="name"><?= e($user['company_name'] ?? 'Freelancer') ?></div>
      <div class="sub">
        <?= e($user['address'] ?? '') ?><br>
        <?= e($user['phone'] ?? '') ?> | <?= e($user['email'] ?? '') ?>
        <?php if (!empty($user['website'])): ?><br><?= e($user['website']) ?><?php endif; ?>
        <?php if (!empty($user['tax_number'])): ?><br>Tax/VAT: <?= e($user['tax_number']) ?><?php endif; ?>
      </div>
    </div>
    <div>
      <?php if ($logo = logoPath($user)): ?>
        <div class="logo-area"><img src="<?= e($logo) ?>" alt="logo"></div>
      <?php endif; ?>
      <div class="invoice-ref">
        <div class="inv-num"><?= strtoupper(e($docLabel)) ?></div>
        <div class="inv-date"><?= e($invoice['invoice_number']) ?></div>
        <div class="inv-date">Issued: <?= e($invoice['issue_date'] ?? date('Y-m-d')) ?></div>
        <div><span class="badge"><?= e($invoice['status']) ?></span></div>
      </div>
    </div>
  </div>

  <div class="meta-strip">
    <div class="meta-cell"><div class="mc-label">Client</div><div class="mc-value"><?= e($invoice['client_name']) ?></div></div>
    <?php if (!empty($invoice['client_email'])): ?>
    <div class="meta-cell"><div class="mc-label">Email</div><div class="mc-value"><?= e($invoice['client_email']) ?></div></div>
    <?php endif; ?>
    <div class="meta-cell"><div class="mc-label"><?= e($dateLabel) ?></div><div class="mc-value"><?= e($invoice['due_date'] ?? '—') ?></div></div>
    <div class="meta-cell"><div class="mc-label">Currency</div><div class="mc-value"><?= e($invoice['currency']) ?></div></div>
  </div>

  <table class="items-table">
    <thead>
      <tr>
        <th style="width:44%">Service / Item</th>
        <th style="width:12%">Qty</th>
        <th style="width:20%">Rate</th>
        <th style="width:20%">Subtotal</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $item): ?>
      <?php $lt = $item['quantity'] * $item['unit_price']; ?>
      <tr>
        <td>
          <?= e($item['product_name']) ?>
          <?php if (!empty($item['description'])): ?>
          <div class="item-desc"><?= e($item['description']) ?></div>
          <?php endif; ?>
        </td>
        <td><?= rtrim(rtrim(number_format($item['quantity'],2),'0'),'.') ?></td>
        <td><?= number_format($item['unit_price'],2) ?></td>
        <td><?= number_format($lt, 2) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php for ($f = 0; $f < $fillRows; $f++): ?>
      <tr><td>&nbsp;</td><td></td><td></td><td></td></tr>
      <?php endfor; ?>
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
  <div class="summary-wrap">
    <table class="summary-table">
      <tr>
        <td><span class="sum-lbl">Subtotal</span></td>
        <td><?= $cur ?>&nbsp;<?= number_format($subtotal,2) ?></td>
      </tr>
      <?php if ($discount > 0): ?>
      <tr>
        <td><span class="sum-lbl">Discount <?= e($invoice['discount_percent']) ?>%</span></td>
        <td>-<?= $cur ?>&nbsp;<?= number_format($discount,2) ?></td>
      </tr>
      <?php endif; ?>
      <?php if ($tax > 0): ?>
      <tr>
        <td><span class="sum-lbl">Tax <?= e($invoice['tax_rate']) ?>%</span></td>
        <td><?= $cur ?>&nbsp;<?= number_format($tax,2) ?></td>
      </tr>
      <?php endif; ?>
      <tr class="sum-total">
        <td>Total Due</td>
        <td><?= $cur ?>&nbsp;<?= number_format($total,2) ?></td>
      </tr>
    </table>
  </div>

  <div class="footer-cols">
    <?php if (!empty($user['bank_details'])): ?>
    <div class="fc">
      <div class="fc-title">Payment Info</div>
      <div class="fc-body"><?= nl2br(e($user['bank_details'])) ?></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($invoice['notes'])): ?>
    <div class="fc">
      <div class="fc-title">Notes</div>
      <div class="fc-body"><?= nl2br(e($invoice['notes'])) ?></div>
    </div>
    <?php endif; ?>
    <div class="fc">
      <div class="fc-title">Thank You</div>
      <div class="fc-body">It was a pleasure working with you. Please don't hesitate to reach out with any questions.</div>
    </div>
  </div>

  <div class="bottom">Invoice generated by <?= APP_NAME ?></div>
</div>
</body>
</html>
