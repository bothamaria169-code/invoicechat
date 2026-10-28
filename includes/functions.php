<?php
declare(strict_types=1);

// ── JSON response helper ──────────────────────────────────────────
function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ── Text message builder ──────────────────────────────────────────
function msg(string $text): array
{
    return ['type' => 'text', 'content' => $text];
}

function msgButtons(string $text, array $buttons): array
{
    return ['type' => 'buttons', 'content' => $text, 'buttons' => $buttons];
}

function msgFile(string $url, string $label = 'Download PDF'): array
{
    return ['type' => 'file', 'url' => $url, 'label' => $label];
}

function msgUpload(string $text): array
{
    return ['type' => 'upload', 'content' => $text];
}

// ── Currency formatting ───────────────────────────────────────────
function formatMoney(float $amount, string $currency = 'USD'): string
{
    $symbols = [
        'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥',
        'CAD' => 'CA$', 'AUD' => 'A$', 'CHF' => 'CHF', 'CNY' => '¥',
        'INR' => '₹', 'BRL' => 'R$', 'ZAR' => 'R', 'NGN' => '₦',
    ];
    $sym = $symbols[$currency] ?? $currency . ' ';
    return $sym . number_format($amount, 2);
}

// ── Invoice totals ────────────────────────────────────────────────
function calculateTotals(array $items, float $taxRate, float $discountPct, string $currency): array
{
    $subtotal  = array_sum(array_map(fn($i) => $i['quantity'] * $i['unit_price'], $items));
    $discount  = $subtotal * ($discountPct / 100);
    $taxable   = $subtotal - $discount;
    $tax       = $taxable  * ($taxRate   / 100);
    $total     = $taxable  + $tax;

    return compact('subtotal', 'discount', 'tax', 'total');
}

// ── Invoice summary text ──────────────────────────────────────────
function buildInvoiceSummary(array $invoice, array $items, string $currency): string
{
    $isQuote = ($invoice['doc_type'] ?? 'invoice') === 'quote';
    $label   = $isQuote ? 'Quote' : 'Invoice';
    $dateLbl = $isQuote ? 'Valid until' : 'Due';
    $totals  = calculateTotals($items, (float)$invoice['tax_rate'], (float)$invoice['discount_percent'], $currency);
    $lines   = ["*{$label} #{$invoice['invoice_number']}*"];
    $lines[] = "Client: {$invoice['client_name']}";
    $lines[] = "{$dateLbl}: " . ($invoice['due_date'] ?? 'TBD');
    $lines[] = '';
    $lines[] = '*Items:*';
    foreach ($items as $item) {
        $subtotal = $item['quantity'] * $item['unit_price'];
        $lines[]  = "• {$item['product_name']} × {$item['quantity']} @ " . formatMoney((float)$item['unit_price'], $currency) . ' = ' . formatMoney($subtotal, $currency);
    }
    $lines[] = '';
    $lines[] = 'Subtotal:  ' . formatMoney($totals['subtotal'], $currency);
    if ($totals['discount'] > 0) {
        $lines[] = "Discount ({$invoice['discount_percent']}%): -" . formatMoney($totals['discount'], $currency);
    }
    if ($totals['tax'] > 0) {
        $lines[] = "Tax ({$invoice['tax_rate']}%): " . formatMoney($totals['tax'], $currency);
    }
    $lines[] = '*Total: ' . formatMoney($totals['total'], $currency) . '*';
    $lines[] = "Template: {$invoice['template_name']}";
    return implode("\n", $lines);
}

// ── Date helpers ──────────────────────────────────────────────────
function defaultDueDate(int $days = 30): string
{
    return date('Y-m-d', strtotime("+{$days} days"));
}

function isValidDate(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

// ── Safe string ───────────────────────────────────────────────────
function e(mixed $val): string
{
    return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── Colour helpers (for themed invoice templates) ─────────────────
function hexClean(string $hex): string
{
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) $hex = '2563eb';
    return strtolower($hex);
}

function hexToRgb(string $hex): array
{
    $hex = hexClean($hex);
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

/** Mix a hex colour toward a target RGB by $weight (0..1). */
function colorMix(string $hex, float $weight, array $target): string
{
    [$r, $g, $b]    = hexToRgb($hex);
    [$tr, $tg, $tb] = $target;
    $r = (int) round($r + ($tr - $r) * $weight);
    $g = (int) round($g + ($tg - $g) * $weight);
    $b = (int) round($b + ($tb - $b) * $weight);
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

/** Lighten toward white. */
function tint(string $hex, float $w = 0.85): string  { return colorMix($hex, $w, [255, 255, 255]); }
/** Darken toward black. */
function shade(string $hex, float $w = 0.22): string { return colorMix($hex, $w, [0, 0, 0]); }

/** Pick black/white text that contrasts with a background hex. */
function contrastText(string $hex): string
{
    [$r, $g, $b] = hexToRgb($hex);
    // Perceived luminance
    $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return $lum > 0.6 ? '#1a1a1a' : '#ffffff';
}

/** Resolve the user's chosen accent colour (hex with leading #). */
function accentColor(array $user): string
{
    $s = json_decode($user['settings'] ?? '{}', true) ?: [];
    return '#' . hexClean($s['accent_color'] ?? '#2563eb');
}

/**
 * Return a Dompdf-loadable logo path, or null if there's no usable logo.
 * Normalises Windows backslashes (mixed separators break Dompdf's loader).
 */
function logoPath(array $user): ?string
{
    $p = (string)($user['logo_path'] ?? '');
    if ($p === '') return null;
    $norm = str_replace('\\', '/', $p);
    return file_exists($norm) ? $norm : null;
}

/**
 * The PDF file for a stored pdf_path. Paths are saved absolute, so a site
 * moved between XAMPP and the server is looked up by file name in PDF_DIR.
 */
function pdfFile(?string $stored): ?string
{
    if (!$stored) return null;
    if (is_file($stored)) return $stored;
    $name = basename(str_replace('\\', '/', $stored));
    return is_file(PDF_DIR . $name) ? PDF_DIR . $name : null;
}

// ── Number to words (for invoice amount line) ─────────────────────
function amountInWords(float $amount, string $currency = 'ZAR'): string
{
    $whole = (int) floor($amount);
    $cents = (int) round(($amount - $whole) * 100);

    $words = ucfirst(numberToWords($whole));
    return sprintf('%s %s and %02d cents', $words, strtoupper($currency), $cents);
}

function numberToWords(int $num): string
{
    if ($num === 0) return 'zero';
    if ($num < 0)  return 'minus ' . numberToWords(-$num);

    $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine',
             'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen',
             'seventeen', 'eighteen', 'nineteen'];
    $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

    $out = '';
    if ($num >= 1000000) {
        $out .= numberToWords(intdiv($num, 1000000)) . ' million ';
        $num %= 1000000;
    }
    if ($num >= 1000) {
        $out .= numberToWords(intdiv($num, 1000)) . ' thousand ';
        $num %= 1000;
    }
    if ($num >= 100) {
        $out .= $ones[intdiv($num, 100)] . ' hundred ';
        $num %= 100;
        if ($num > 0) $out .= 'and ';
    }
    if ($num >= 20) {
        $out .= $tens[intdiv($num, 10)];
        if ($num % 10) $out .= '-' . $ones[$num % 10];
    } elseif ($num > 0) {
        $out .= $ones[$num];
    }
    return trim($out);
}
