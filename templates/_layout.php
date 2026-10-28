<?php
/**
 * The one invoice/quote layout every template uses; $variant picks the look:
 *   classic    – white, logo left, accent rule
 *   modern     – full accent header band
 *   freelancer – minimal, big "amount due" card
 *   corporate  – dark header bar, boxed details
 *   banded     – accent side band, tinted sections
 *
 * Built with tables and plain blocks only: Dompdf doesn't support flexbox or
 * grid, which is what broke the old templates. Expects $invoice, $items,
 * $user (and $variant) from the caller.
 */
$variant   = $variant ?? 'classic';
$isQuote   = ($invoice['doc_type'] ?? 'invoice') === 'quote';
$docLabel  = $isQuote ? 'Quotation' : 'Invoice';
$accent    = accentColor($user);
$accentLt  = tint($accent, 0.92);
$accentMd  = tint($accent, 0.75);
$accentDk  = shade($accent, 0.25);
$onAccent  = contrastText($accent);
$ink       = '#0e1726';
$cur       = (string) $invoice['currency'];
$money     = fn(float $v) => formatMoney($v, $cur);
$date      = fn(?string $d) => $d ? date('j M Y', strtotime($d)) : '—';
$qty       = fn($q) => rtrim(rtrim(number_format((float) $q, 2), '0'), '.');

$subtotal  = array_sum(array_map(fn($i) => $i['quantity'] * $i['unit_price'], $items));
$discount  = $subtotal * ((float) $invoice['discount_percent'] / 100);
$tax       = ($subtotal - $discount) * ((float) $invoice['tax_rate'] / 100);
$total     = $subtotal - $discount + $tax;

$company   = trim((string) ($user['company_name'] ?? '')) ?: (trim((string) ($user['full_name'] ?? '')) ?: ucfirst(strtok((string) $user['email'], '@')));
$fromLines = array_filter([
    $user['address'] ?? '',
    implode('  ·  ', array_filter([$user['phone'] ?? '', $user['email'] ?? ''])),
    $user['website'] ?? '',
    !empty($user['tax_number']) ? 'VAT/Tax no: ' . $user['tax_number'] : '',
]);
$logo      = logoPath($user);
$paid      = !$isQuote && $invoice['status'] === 'paid';
$accepted  = $isQuote && $invoice['status'] === 'accepted';
$headDark  = in_array($variant, ['modern', 'corporate'], true);
$headBg    = $variant === 'corporate' ? $ink : $accent;
$headText  = $variant === 'corporate' ? '#ffffff' : $onAccent;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  @page { margin: 0; }
  * { margin: 0; padding: 0; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: <?= $ink ?>; line-height: 1.45; }
  table { border-collapse: collapse; width: 100%; }
  td, th { vertical-align: top; }
  .page { padding: <?= $headDark ? '0' : '38px' ?> 42px 70px <?= $variant === 'banded' ? '58px' : '42px' ?>; }
  .muted { color: #6b7688; }
  .small { font-size: 9px; }
  .label { font-size: 8px; text-transform: uppercase; letter-spacing: 1.2px; font-weight: bold; color: <?= $variant === 'corporate' ? '#6b7688' : $accent ?>; margin-bottom: 4px; }
  .right { text-align: right; }

  /* Header */
  .head { <?= $headDark ? "background: {$headBg}; color: {$headText}; margin: 0 -42px 26px; padding: 30px 42px 26px;" : 'padding-bottom: 18px; margin-bottom: 22px; border-bottom: 2px solid ' . ($variant === 'freelancer' ? '#e4e8f1' : $accent) . ';' ?> }
  .head .logo img { max-height: 62px; max-width: 170px; }
  .logo-box { <?= $headDark ? 'background:#fff; padding:8px; border-radius:10px; display:inline-block;' : '' ?> }
  .company { font-size: 17px; font-weight: bold; color: <?= $headDark ? $headText : $ink ?>; }
  .from { font-size: 9px; color: <?= $headDark ? $headText : '#4a5768' ?>; <?= $headDark ? 'opacity:.85;' : '' ?> margin-top: 4px; line-height: 1.55; }
  .doc-title { font-size: 26px; font-weight: bold; letter-spacing: 2px; text-transform: uppercase; color: <?= $headDark ? $headText : $accent ?>; }
  .doc-no { font-size: 11px; margin-top: 2px; color: <?= $headDark ? $headText : '#4a5768' ?>; }

  /* Info row */
  .info td { padding-right: 14px; }
  .box { <?= $variant === 'corporate' || $variant === 'banded' ? "background: {$accentLt}; border-radius: 8px; padding: 12px 14px;" : 'padding: 2px 0;' ?> }
  .client { font-size: 13px; font-weight: bold; margin-bottom: 2px; }
  .meta td { padding: 2px 0; }
  .meta td.k { color: #6b7688; width: 48%; }
  .meta td.v { font-weight: bold; text-align: right; }

  /* Amount due card (freelancer) */
  .due-card { background: <?= $accent ?>; color: <?= $onAccent ?>; border-radius: 10px; padding: 14px 16px; }
  .due-card .amt { font-size: 20px; font-weight: bold; margin-top: 2px; }

  /* Items */
  .items { margin-top: 22px; }
  .items th { font-size: 8px; text-transform: uppercase; letter-spacing: 1px; text-align: left; padding: 9px 10px;
    <?= $variant === 'freelancer' ? "color:#6b7688; border-bottom: 1.5px solid {$ink};" : ($variant === 'corporate' ? "background: {$ink}; color:#fff;" : "background: {$accent}; color: {$onAccent};") ?> }
  .items td { padding: 9px 10px; border-bottom: 1px solid #e9edf4; }
  .items tr.alt td { background: <?= $variant === 'freelancer' ? '#ffffff' : '#f8f9fc' ?>; }
  .items .num { text-align: right; white-space: nowrap; }
  .items .desc { font-size: 8.5px; color: #6b7688; margin-top: 2px; }

  /* Totals */
  .totals { width: 46%; margin-left: 54%; margin-top: 12px; }
  .totals td { padding: 5px 10px; }
  .totals td.v { text-align: right; white-space: nowrap; }
  .totals tr.grand td { font-size: 13px; font-weight: bold; padding: 10px; <?= "background: {$accent}; color: {$onAccent};" ?> }
  .words { text-align: right; font-size: 8.5px; color: #6b7688; margin-top: 6px; font-style: italic; }

  /* Bottom */
  .bottom { margin-top: 24px; }
  .bottom td { padding-right: 12px; }
  .panel { border: 1px solid #e4e8f1; border-radius: 8px; padding: 11px 13px; font-size: 9.5px; color: #33404f; }
  .panel .ref { margin-top: 6px; padding-top: 6px; border-top: 1px dashed #d5dbe6; }
  .stamp { position: absolute; top: 250px; right: 60px; border: 3px solid #10b981; color: #10b981; font-size: 26px; font-weight: bold; letter-spacing: 3px; padding: 4px 16px; border-radius: 8px; transform: rotate(-12deg); opacity: .8; }
  .band { position: fixed; top: 0; left: 0; bottom: 0; width: 18px; background: <?= $accent ?>; }
  .foot { position: fixed; bottom: 22px; left: 42px; right: 42px; text-align: center; font-size: 8.5px; color: #8b95a7; border-top: 1px solid #e9edf4; padding-top: 8px; }
</style>
</head>
<body>
<?php if ($variant === 'banded'): ?><div class="band"></div><?php endif; ?>
<?php if ($paid): ?><div class="stamp">PAID</div><?php elseif ($accepted): ?><div class="stamp">ACCEPTED</div><?php endif; ?>
<div class="page">

  <!-- Header: logo + business on one side, document title on the other -->
  <div class="head">
    <table><tr>
      <td style="width:58%">
        <?php if ($logo): ?><div class="logo"><span class="logo-box"><img src="<?= e($logo) ?>" alt=""></span></div><div style="height:8px"></div><?php endif; ?>
        <div class="company"><?= e($company) ?></div>
        <div class="from"><?= implode('<br>', array_map('e', $fromLines)) ?></div>
      </td>
      <td class="right" style="width:42%">
        <div class="doc-title"><?= $docLabel ?></div>
        <div class="doc-no"># <?= e($invoice['invoice_number']) ?></div>
      </td>
    </tr></table>
  </div>

  <!-- Who it's for + key dates (+ amount due) -->
  <table class="info"><tr>
    <td style="width:<?= $variant === 'freelancer' ? '40%' : '55%' ?>">
      <div class="box">
        <div class="label"><?= $isQuote ? 'Quote for' : 'Bill to' ?></div>
        <div class="client"><?= e($invoice['client_name'] ?: 'Client') ?></div>
        <?php if (!empty($invoice['client_email'])): ?><div class="muted"><?= e($invoice['client_email']) ?></div><?php endif; ?>
        <?php if (!empty($invoice['client_phone'])): ?><div class="muted"><?= e($invoice['client_phone']) ?></div><?php endif; ?>
      </div>
    </td>
    <td style="width:<?= $variant === 'freelancer' ? '30%' : '45%' ?>;padding-right:<?= $variant === 'freelancer' ? '16px' : '0' ?>">
      <div class="box">
        <table class="meta">
          <tr><td class="k"><?= $isQuote ? 'Quote date' : 'Invoice date' ?></td><td class="v"><?= $date($invoice['issue_date'] ?? null) ?></td></tr>
          <tr><td class="k"><?= $isQuote ? 'Valid until' : 'Due date' ?></td><td class="v"><?= $date($invoice['due_date'] ?? null) ?></td></tr>
          <tr><td class="k"><?= $isQuote ? 'Quote no.' : 'Invoice no.' ?></td><td class="v"><?= e($invoice['invoice_number']) ?></td></tr>
          <?php if ($variant !== 'freelancer'): ?><tr><td class="k"><?= $isQuote ? 'Quote total' : 'Amount due' ?></td><td class="v" style="color:<?= $accent ?>"><?= $money($total) ?></td></tr><?php endif; ?>
        </table>
      </div>
    </td>
    <?php if ($variant === 'freelancer'): ?>
    <td style="width:30%;padding-right:0"><div class="due-card"><div class="small" style="text-transform:uppercase;letter-spacing:1px"><?= $isQuote ? 'Quote total' : 'Amount due' ?></div><div class="amt"><?= $money($total) ?></div><div class="small"><?= $isQuote ? 'Valid until ' : 'Due ' ?><?= $date($invoice['due_date'] ?? null) ?></div></div></td>
    <?php endif; ?>
  </tr></table>

  <!-- Line items -->
  <table class="items">
    <thead><tr>
      <th style="width:52%">Description</th><th class="num" style="width:10%">Qty</th><th class="num" style="width:18%">Unit price</th><th class="num" style="width:20%">Amount</th>
    </tr></thead>
    <tbody>
    <?php foreach (array_values($items) as $n => $item): ?>
      <tr class="<?= $n % 2 ? 'alt' : '' ?>">
        <td><strong><?= e($item['product_name']) ?></strong><?php if (!empty($item['description'])): ?><div class="desc"><?= e($item['description']) ?></div><?php endif; ?></td>
        <td class="num"><?= $qty($item['quantity']) ?></td>
        <td class="num"><?= $money((float) $item['unit_price']) ?></td>
        <td class="num"><strong><?= $money($item['quantity'] * $item['unit_price']) ?></strong></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Totals -->
  <table class="totals">
    <tr><td class="muted">Subtotal</td><td class="v"><?= $money($subtotal) ?></td></tr>
    <?php if ($discount > 0): ?><tr><td class="muted">Discount (<?= $qty($invoice['discount_percent']) ?>%)</td><td class="v">−<?= $money($discount) ?></td></tr><?php endif; ?>
    <?php if ($tax > 0): ?><tr><td class="muted">VAT / Tax (<?= $qty($invoice['tax_rate']) ?>%)</td><td class="v"><?= $money($tax) ?></td></tr><?php endif; ?>
    <tr class="grand"><td><?= $isQuote ? 'Total' : ($paid ? 'Total paid' : 'Total due') ?></td><td class="v"><?= $money($total) ?></td></tr>
  </table>
  <?php if ($cur === 'ZAR'): ?><div class="words"><?= e(amountInWords($total, $cur)) ?></div><?php endif; ?>

  <!-- Payment details + notes -->
  <?php if (!empty($user['bank_details']) || !empty($invoice['notes'])): ?>
  <table class="bottom"><tr>
    <?php if (!empty($user['bank_details']) && !$isQuote): ?>
    <td style="width:<?= !empty($invoice['notes']) ? '50%' : '60%' ?>"><div class="panel">
      <div class="label">How to pay</div>
      <?= nl2br(e($user['bank_details'])) ?>
      <div class="ref">Payment reference: <strong><?= e($invoice['invoice_number']) ?></strong></div>
    </div></td>
    <?php endif; ?>
    <?php if (!empty($invoice['notes'])): ?>
    <td style="padding-right:0"><div class="panel"><div class="label"><?= $isQuote ? 'Terms' : 'Notes' ?></div><?= nl2br(e($invoice['notes'])) ?></div></td>
    <?php endif; ?>
  </tr></table>
  <?php endif; ?>
</div>

<div class="foot">
  <?= $isQuote ? 'Thank you for the opportunity to quote.' : 'Thank you for your business.' ?>
  <?= e($company) ?><?= !empty($user['phone']) ? ' · ' . e($user['phone']) : '' ?><?= !empty($user['email']) ? ' · ' . e($user['email']) : '' ?>
</div>
</body>
</html>
