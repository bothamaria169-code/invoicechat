<?php
  $isQuote   = ($invoice['doc_type'] ?? 'invoice') === 'quote';
  $docLabel  = $isQuote ? 'Quotation' : 'Invoice';
  $dateLabel = $isQuote ? 'Valid Until' : 'Due Date';
  $accent    = accentColor($user);
  $accentDk  = shade($accent, 0.30);
  $onAccent  = contrastText($accent);

  $subtotal = array_sum(array_map(fn($i) => $i['quantity'] * $i['unit_price'], $items));
  $discount = $subtotal * ($invoice['discount_percent'] / 100);
  $taxable  = $subtotal - $discount;
  $tax      = $taxable * ($invoice['tax_rate'] / 100);
  $total    = $taxable + $tax;
  $cur      = $invoice['currency'];

  $minRows  = 12;
  $fillRows = max(0, $minRows - count($items));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 12px; color: #1f2937; background: #fff; }
  .page { padding: 0 0 30px; }

  /* Accent header band */
  .top { background: <?= $accent ?>; color: <?= $onAccent ?>; width: 100%; }
  .top table { width: 100%; }
  .top td { vertical-align: middle; padding: 28px 0; }
  .top td:first-child { padding-left: 44px; }
  .top td:last-child  { padding-right: 44px; }
  .doc-word { font-size: 38px; font-weight: 900; letter-spacing: 1px; }
  .doc-num  { font-size: 13px; font-weight: 700; margin-top: 4px; opacity: .9; }
  .co-right { text-align: right; }
  .co-right img { max-height: 56px; max-width: 180px; margin-bottom: 6px; }
  .co-name  { font-size: 16px; font-weight: 800; }
  .co-sub   { font-size: 10.5px; line-height: 1.6; opacity: .9; margin-top: 2px; }

  .body { padding: 26px 44px 0; }

  /* Three info columns */
  .info { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
  .info td { width: 33.33%; vertical-align: top; padding-right: 18px; }
  .info-h { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px;
            color: <?= $accent ?>; border-bottom: 2px solid <?= $accent ?>; padding-bottom: 4px; margin-bottom: 8px; }
  .info-b { font-size: 11px; line-height: 1.7; color: #374151; }

  .meta { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
  .meta td { font-size: 11px; padding: 2px 0; }
  .meta .k { color: #6b7280; width: 90px; }
  .meta .v { font-weight: 700; }

  /* Items grid */
  .items { width: 100%; border-collapse: collapse; margin-bottom: 0; }
  .items th { background: <?= $accentDk ?>; color: #fff; font-size: 10.5px; font-weight: 700;
              text-transform: uppercase; letter-spacing: .5px; padding: 9px 10px; text-align: left;
              border: 1px solid <?= $accentDk ?>; }
  .items th.r, .items td.r { text-align: right; }
  .items th.c, .items td.c { text-align: center; }
  .items td { padding: 8px 10px; border: 1px solid #d1d5db; font-size: 11px; height: 26px; vertical-align: top; }
  .item-desc { font-size: 9.5px; color: #6b7280; margin-top: 2px; }

  /* Totals */
  .totals-wrap { width: 100%; margin-top: 16px; }
  .totals-wrap td { vertical-align: top; }
  .words { font-size: 10.5px; color: #9ca3af; font-style: italic; padding-top: 18px; width: 55%; }
  .totals { width: 45%; }
  .totals table { width: 100%; border-collapse: collapse; }
  .totals td { font-size: 12px; padding: 6px 4px; }
  .totals td.k { color: #6b7280; }
  .totals td.v { text-align: right; font-weight: 700; }
  .totals tr.grand td { border-top: 2px solid <?= $accent ?>; font-size: 14px; font-weight: 800; color: <?= $accent ?>; padding-top: 9px; }

  .notes { margin-top: 26px; font-size: 11px; color: #374151; line-height: 1.6; }
  .notes-h { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: <?= $accent ?>; margin-bottom: 4px; }

  .thanks { background: <?= $accent ?>; color: <?= $onAccent ?>; text-align: center;
            font-size: 12px; font-weight: 700; letter-spacing: 1px; padding: 12px; margin-top: 30px; }
</style>
</head>
<body>

  <!-- Accent header -->
  <div class="top">
    <table>
      <tr>
        <td>
          <div class="doc-word"><?= strtoupper(e($docLabel)) ?></div>
          <div class="doc-num"><?= e($invoice['invoice_number']) ?> &middot; <?= strtoupper(e($invoice['status'])) ?></div>
        </td>
        <td class="co-right">
          <?php if ($logo = logoPath($user)): ?>
            <img src="<?= e($logo) ?>" alt="logo"><br>
          <?php endif; ?>
          <div class="co-name"><?= e($user['company_name'] ?? 'Your Company') ?></div>
          <div class="co-sub">
            <?= e($user['address'] ?? '') ?><br>
            <?= e($user['phone'] ?? '') ?>
            <?php if (!empty($user['email'])): ?> &middot; <?= e($user['email']) ?><?php endif; ?>
          </div>
        </td>
      </tr>
    </table>
  </div>

  <div class="body">

    <!-- Three columns -->
    <table class="info">
      <tr>
        <td>
          <div class="info-h">Seller</div>
          <div class="info-b">
            <?= e($user['company_name'] ?? '') ?><br>
            <?= nl2br(e($user['address'] ?? '')) ?>
            <?php if (!empty($user['tax_number'])): ?><br>VAT: <?= e($user['tax_number']) ?><?php endif; ?>
          </div>
        </td>
        <td>
          <div class="info-h"><?= $isQuote ? 'Quote For' : 'Bill To' ?></div>
          <div class="info-b">
            <?= e($invoice['client_name']) ?>
            <?php if (!empty($invoice['client_email'])): ?><br><?= e($invoice['client_email']) ?><?php endif; ?>
            <?php if (!empty($invoice['client_phone'])): ?><br><?= e($invoice['client_phone']) ?><?php endif; ?>
          </div>
        </td>
        <td>
          <div class="info-h">Details</div>
          <table class="meta">
            <tr><td class="k"><?= $isQuote ? 'Quote No' : 'Invoice No' ?></td><td class="v"><?= e($invoice['invoice_number']) ?></td></tr>
            <tr><td class="k">Issue Date</td><td class="v"><?= e($invoice['issue_date'] ?? date('Y-m-d')) ?></td></tr>
            <tr><td class="k"><?= e($dateLabel) ?></td><td class="v"><?= e($invoice['due_date'] ?? '—') ?></td></tr>
            <tr><td class="k">Currency</td><td class="v"><?= e($cur) ?></td></tr>
          </table>
        </td>
      </tr>
    </table>

    <!-- Items grid -->
    <table class="items">
      <thead>
        <tr>
          <th style="width:46%">Description</th>
          <th class="c" style="width:12%">Qty</th>
          <th class="r" style="width:21%">Unit Price</th>
          <th class="r" style="width:21%">Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $item): ?>
        <?php $lt = $item['quantity'] * $item['unit_price']; ?>
        <tr>
          <td>
            <?= e($item['product_name']) ?>
            <?php if (!empty($item['description'])): ?><div class="item-desc"><?= e($item['description']) ?></div><?php endif; ?>
          </td>
          <td class="c"><?= rtrim(rtrim(number_format($item['quantity'], 2), '0'), '.') ?></td>
          <td class="r"><?= number_format($item['unit_price'], 2) ?></td>
          <td class="r"><?= number_format($lt, 2) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php for ($f = 0; $f < $fillRows; $f++): ?>
        <tr><td>&nbsp;</td><td></td><td></td><td></td></tr>
        <?php endfor; ?>
      </tbody>
    </table>

    <!-- Totals + amount in words -->
    <table class="totals-wrap">
      <tr>
        <td class="words"><?= e(amountInWords($total, $cur)) ?></td>
        <td class="totals">
          <table>
            <tr><td class="k">Subtotal</td><td class="v"><?= e($cur) ?> <?= number_format($subtotal, 2) ?></td></tr>
            <?php if ($discount > 0): ?>
            <tr><td class="k">Discount (<?= e($invoice['discount_percent']) ?>%)</td><td class="v">-<?= e($cur) ?> <?= number_format($discount, 2) ?></td></tr>
            <?php endif; ?>
            <?php if ($tax > 0): ?>
            <tr><td class="k">VAT (<?= e($invoice['tax_rate']) ?>%)</td><td class="v"><?= e($cur) ?> <?= number_format($tax, 2) ?></td></tr>
            <?php endif; ?>
            <tr class="grand"><td class="k"><?= $isQuote ? 'Total' : 'Total to Pay' ?></td><td class="v"><?= e($cur) ?> <?= number_format($total, 2) ?></td></tr>
          </table>
        </td>
      </tr>
    </table>

    <?php if (!empty($user['bank_details']) || !empty($invoice['notes'])): ?>
    <div class="notes">
      <?php if (!empty($user['bank_details'])): ?>
        <div class="notes-h">Payment Details</div>
        <?= nl2br(e($user['bank_details'])) ?>
      <?php endif; ?>
      <?php if (!empty($invoice['notes'])): ?>
        <div class="notes-h" style="margin-top:10px">Notes</div>
        <?= nl2br(e($invoice['notes'])) ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div>

  <div class="thanks">THANK YOU FOR YOUR BUSINESS!</div>
</body>
</html>
