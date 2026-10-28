<?php
  $isQuote   = ($invoice['doc_type'] ?? 'invoice') === 'quote';
  $docLabel  = $isQuote ? 'Quote' : 'Invoice';
  $dateLabel = $isQuote ? 'Valid date' : 'Due date';
  $accent    = accentColor($user);
  $accentDk  = shade($accent, 0.45);
  $onAccent  = contrastText($accent);

  $subtotal = array_sum(array_map(fn($i) => $i['quantity'] * $i['unit_price'], $items));
  $discount = $subtotal * ($invoice['discount_percent'] / 100);
  $taxable  = $subtotal - $discount;
  $tax      = $taxable * ($invoice['tax_rate'] / 100);
  $total    = $taxable + $tax;
  $cur      = $invoice['currency'];

  $minRows  = 6;
  $fillRows = max(0, $minRows - count($items));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 12px; color: #3a3a3a; background: #fff; }
  .page { padding: 44px 50px; }

  /* Top row: doc word left, logo right */
  .head { width: 100%; border-collapse: collapse; margin-bottom: 36px; }
  .head td { vertical-align: top; }
  .doc-word { font-size: 30px; font-weight: 400; letter-spacing: 1px; color: #2b2b2b; text-transform: uppercase; }
  .logo-cell { text-align: right; }
  .logo-cell img { max-height: 80px; max-width: 200px; }
  .logo-name { font-size: 20px; font-weight: 800; color: #2b2b2b; line-height: 1.1; }

  .biz-line { font-size: 11px; color: #9a9a9a; margin-bottom: 18px; }

  /* Bill-to + meta */
  .addr { width: 100%; border-collapse: collapse; margin-bottom: 26px; }
  .addr td { vertical-align: top; }
  .bill-h { font-size: 11px; font-weight: 800; color: #2b2b2b; margin-bottom: 4px; }
  .bill-b { font-size: 11.5px; line-height: 1.7; }
  .meta { width: 280px; }
  .meta td { font-size: 11.5px; padding: 2px 0; }
  .meta .k { color: #6b6b6b; }
  .meta .v { text-align: right; font-weight: 700; }

  /* Coloured band */
  .band { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
  .band td { padding: 14px 16px; color: <?= $onAccent ?>; background: <?= $accent ?>; vertical-align: top; }
  .band td.total-cell { background: <?= $accentDk ?>; color: #fff; }
  .band .b-label { font-size: 10px; opacity: .85; margin-bottom: 3px; }
  .band .b-value { font-size: 16px; font-weight: 700; }

  /* Items */
  .items { width: 100%; border-collapse: collapse; margin-top: 22px; }
  .items th { font-size: 11px; font-weight: 700; color: #2b2b2b; text-align: left;
              border-bottom: 2px solid #cfcfcf; padding: 8px 6px; }
  .items th.r, .items td.r { text-align: right; }
  .items th.c, .items td.c { text-align: center; }
  .items td { font-size: 11.5px; padding: 9px 6px; border-bottom: 1px solid #ececec; height: 24px; }
  .item-desc { font-size: 9.5px; color: #9a9a9a; margin-top: 2px; }

  /* Total line */
  .grand { width: 100%; border-collapse: collapse; margin-top: 6px; }
  .grand td { padding: 12px 6px; font-size: 16px; font-weight: 800; color: #2b2b2b; border-top: 2px solid #2b2b2b; }
  .grand td.r { text-align: right; }
  .subtot { width: 100%; border-collapse: collapse; }
  .subtot td { font-size: 11.5px; padding: 3px 6px; }
  .subtot td.k { text-align: right; color: #6b6b6b; }
  .subtot td.v { text-align: right; width: 130px; font-weight: 700; }

  /* Signature */
  .sign { margin-top: 50px; text-align: right; }
  .sign-label { font-size: 11px; font-weight: 700; color: #2b2b2b; }
  .sign-name { font-size: 26px; color: #8a8a8a; font-style: italic; margin-top: 6px; }

  .pay { margin-top: 30px; font-size: 11px; color: #555; line-height: 1.6; }
  .pay-h { font-weight: 800; color: #2b2b2b; margin-bottom: 3px; }

  .foot { margin-top: 40px; border-top: 1px solid #e3e3e3; padding-top: 12px; text-align: right; font-size: 10.5px; color: #9a9a9a; }
</style>
</head>
<body>
<div class="page">

  <!-- Head -->
  <table class="head">
    <tr>
      <td><div class="doc-word"><?= e($docLabel) ?></div></td>
      <td class="logo-cell">
        <?php if ($logo = logoPath($user)): ?>
          <img src="<?= e($logo) ?>" alt="logo">
        <?php else: ?>
          <div class="logo-name"><?= e($user['company_name'] ?? 'Your Company') ?></div>
        <?php endif; ?>
      </td>
    </tr>
  </table>

  <div class="biz-line">
    <?= e($user['company_name'] ?? '') ?><?php if (!empty($user['address'])): ?>, <?= e($user['address']) ?><?php endif; ?>
  </div>

  <!-- Bill to + meta -->
  <table class="addr">
    <tr>
      <td>
        <div class="bill-h"><?= $isQuote ? 'QUOTE FOR' : 'BILL TO' ?></div>
        <div class="bill-b">
          <?= e($invoice['client_name']) ?><br>
          <?php if (!empty($invoice['client_email'])): ?><?= e($invoice['client_email']) ?><br><?php endif; ?>
          <?php if (!empty($invoice['client_phone'])): ?><?= e($invoice['client_phone']) ?><?php endif; ?>
        </div>
      </td>
      <td style="text-align:right">
        <table class="meta" style="float:right">
          <tr><td class="k"><?= $isQuote ? 'Quote No.:' : 'Invoice No.:' ?></td><td class="v"><?= e($invoice['invoice_number']) ?></td></tr>
          <tr><td class="k">Issue date:</td><td class="v"><?= e($invoice['issue_date'] ?? date('Y-m-d')) ?></td></tr>
          <tr><td class="k"><?= e($dateLabel) ?>:</td><td class="v"><?= e($invoice['due_date'] ?? '—') ?></td></tr>
        </table>
      </td>
    </tr>
  </table>

  <!-- Coloured band -->
  <table class="band">
    <tr>
      <td style="width:25%"><div class="b-label"><?= $isQuote ? 'Quote No.' : 'Invoice No.' ?></div><div class="b-value"><?= e($invoice['invoice_number']) ?></div></td>
      <td style="width:25%"><div class="b-label">Issue date</div><div class="b-value"><?= e($invoice['issue_date'] ?? date('Y-m-d')) ?></div></td>
      <td style="width:25%"><div class="b-label"><?= e($dateLabel) ?></div><div class="b-value"><?= e($invoice['due_date'] ?? '—') ?></div></td>
      <td style="width:25%" class="total-cell"><div class="b-label">Total due (<?= e($cur) ?>)</div><div class="b-value"><?= e($cur) ?> <?= number_format($total, 2) ?></div></td>
    </tr>
  </table>

  <!-- Items -->
  <table class="items">
    <thead>
      <tr>
        <th style="width:48%">Description</th>
        <th class="c" style="width:14%">Quantity</th>
        <th class="r" style="width:19%">Unit price</th>
        <th class="r" style="width:19%">Amount</th>
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

  <!-- Subtotals (only if discount/tax) -->
  <?php if ($discount > 0 || $tax > 0): ?>
  <table class="subtot">
    <tr><td class="k">Subtotal</td><td class="v"><?= e($cur) ?> <?= number_format($subtotal, 2) ?></td></tr>
    <?php if ($discount > 0): ?>
    <tr><td class="k">Discount (<?= e($invoice['discount_percent']) ?>%)</td><td class="v">-<?= e($cur) ?> <?= number_format($discount, 2) ?></td></tr>
    <?php endif; ?>
    <?php if ($tax > 0): ?>
    <tr><td class="k">Tax (<?= e($invoice['tax_rate']) ?>%)</td><td class="v"><?= e($cur) ?> <?= number_format($tax, 2) ?></td></tr>
    <?php endif; ?>
  </table>
  <?php endif; ?>

  <!-- Grand total -->
  <table class="grand">
    <tr>
      <td>Total (<?= e($cur) ?>):</td>
      <td class="r"><?= e($cur) ?> <?= number_format($total, 2) ?></td>
    </tr>
  </table>

  <!-- Signature -->
  <div class="sign">
    <div class="sign-label">Issued by, signature:</div>
    <div class="sign-name"><?= e($user['company_name'] ?? '') ?></div>
  </div>

  <?php if (!empty($user['bank_details'])): ?>
  <div class="pay">
    <div class="pay-h">Payment Details</div>
    <?= nl2br(e($user['bank_details'])) ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($invoice['notes'])): ?>
  <div class="pay">
    <div class="pay-h">Notes</div>
    <?= nl2br(e($invoice['notes'])) ?>
  </div>
  <?php endif; ?>

  <div class="foot">
    <?php if (!empty($user['email'])): ?><?= e($user['email']) ?><?php endif; ?>
    <?php if (!empty($user['website'])): ?> &middot; <?= e($user['website']) ?><?php endif; ?>
  </div>

</div>
</body>
</html>
