<?php
declare(strict_types=1);

require_once __DIR__ . '/billing.php';

/**
 * A user's documents and clients: who each invoice is for, and whether it was
 * emailed, sent on WhatsApp, opened by the client and paid. Every step is a
 * row in document_events, shown as the timeline on the user's dashboard.
 */
const DOC_EVENT_LABELS = [
    'pdf'      => ['PDF created', 'fa-file-pdf'],
    'email'    => ['Emailed', 'fa-envelope'],
    'whatsapp' => ['Sent on WhatsApp', 'fa-brands fa-whatsapp'],
    'viewed'   => ['Opened by client', 'fa-eye'],
    'paid'     => ['Marked paid', 'fa-circle-check'],
    'status'   => ['Status changed', 'fa-pen'],
    'reminder' => ['Payment reminder sent', 'fa-bell'],
];

function logDocEvent(int $invoiceId, int $userId, string $kind, string $detail = ''): void
{
    $db = getDB();
    $db->prepare('INSERT INTO document_events (invoice_id, user_id, kind, detail) VALUES (?, ?, ?, ?)')
       ->execute([$invoiceId, $userId, $kind, mb_substr($detail, 0, 255) ?: null]);
    $col = ['email' => 'emailed_at', 'whatsapp' => 'whatsapped_at'][$kind] ?? null;
    if ($col) {
        $db->prepare("UPDATE invoices SET {$col} = NOW(), status = IF(status = 'draft', 'sent', status) WHERE id = ?")->execute([$invoiceId]);
    }
    if ($kind === 'viewed') {
        $db->prepare('UPDATE invoices SET view_count = view_count + 1, viewed_at = COALESCE(viewed_at, NOW()) WHERE id = ?')->execute([$invoiceId]);
    }
}

/** Saves (or updates) the document's client in the user's client list and links them. */
function syncClient(int $userId, int $invoiceId): void
{
    $db = getDB();
    $s = $db->prepare('SELECT client_name, client_email, client_phone FROM invoices WHERE id = ? AND user_id = ?');
    $s->execute([$invoiceId, $userId]);
    $d = $s->fetch();
    $name = trim((string) ($d['client_name'] ?? ''));
    if ($name === '') return;
    $db->prepare('INSERT INTO clients (user_id, name, name_key, email, phone) VALUES (?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE email = COALESCE(VALUES(email), email), phone = COALESCE(VALUES(phone), phone), last_used_at = NOW()')
       ->execute([$userId, mb_substr($name, 0, 255), mb_strtolower(mb_substr($name, 0, 255)), $d['client_email'] ?: null, $d['client_phone'] ?: null]);
    $db->prepare('UPDATE invoices i JOIN clients c ON c.user_id = i.user_id AND c.name_key = ? SET i.client_id = c.id WHERE i.id = ?')
       ->execute([mb_strtolower(mb_substr($name, 0, 255)), $invoiceId]);
}

/** International digits for wa.me: 082… → 2782…, keeps numbers that already have a country code. */
function waDigits(?string $phone): string
{
    $d = preg_replace('/\D/', '', (string) $phone);
    if ($d === '') return '';
    if (str_starts_with($d, '00')) $d = substr($d, 2);
    if (str_starts_with($d, '0') && strlen($d) === 10) $d = '27' . substr($d, 1);
    return $d;
}

/** The ready-to-send WhatsApp message for a document, as a wa.me link. */
function whatsappShareUrl(array $invoice, array $user, string $pdfUrl, bool $reminder = false): string
{
    $isQuote = ($invoice['doc_type'] ?? 'invoice') === 'quote';
    $word    = $isQuote ? 'quote' : 'invoice';
    $company = $user['company_name'] ?: ($user['full_name'] ?: APP_NAME);
    $first   = trim(explode(' ', trim((string) $invoice['client_name']))[0] ?? '') ?: 'there';
    $amount  = formatMoney((float) $invoice['total_amount'], (string) $invoice['currency']);
    $text = $reminder
        ? "Hi {$first}, a friendly reminder that {$word} {$invoice['invoice_number']} for {$amount} was due on " . date('j M', strtotime((string) $invoice['due_date'])) . ".\n\nYou can view it here: {$pdfUrl}\n\nThank you,\n{$company}"
        : "Hi {$first}, please find your {$word} {$invoice['invoice_number']} for {$amount}" . ($isQuote ? '' : ', due ' . date('j M Y', strtotime((string) $invoice['due_date']))) . ".\n\nView / download: {$pdfUrl}\n\nThank you,\n{$company}";
    return 'https://wa.me/' . waDigits($invoice['client_phone'] ?? '') . '?text=' . rawurlencode($text);
}

/** The document's PDF link, or '' when it hasn't been generated yet. */
function docPdfUrl(array $invoice): string
{
    $f = pdfFile($invoice['pdf_path'] ?? null);
    return $f ? signedPdfUrl($f) : '';
}

/** Overdue = an unpaid invoice past its due date. */
function isOverdue(array $d): bool
{
    return $d['doc_type'] === 'invoice' && !in_array($d['status'], ['paid', 'draft'], true) && !empty($d['due_date']) && $d['due_date'] < date('Y-m-d');
}

/** Emails a document (or a payment reminder) to the client with the PDF attached. Returns '' or the error. */
function emailDocument(int $userId, int $invoiceId, string $to, bool $reminder = false): string
{
    $s = getDB()->prepare('SELECT * FROM invoices WHERE id = ? AND user_id = ?');
    $s->execute([$invoiceId, $userId]);
    $inv = $s->fetch();
    if (!$inv) return 'Document not found.';
    $pdf = pdfFile($inv['pdf_path']);
    if (!$pdf) return 'Create the PDF first (open it in the chat and choose Generate PDF).';
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return 'That email address isn\'t valid.';

    $user     = freshUser($userId);
    $word     = $inv['doc_type'] === 'quote' ? 'Quote' : 'Invoice';
    $company  = $user['company_name'] ?: ($user['full_name'] ?: APP_NAME);
    $total    = formatMoney((float) $inv['total_amount'], (string) $inv['currency']);
    $first    = e(trim(explode(' ', trim((string) $inv['client_name']))[0] ?? '') ?: 'there');
    $subject  = $reminder ? "Reminder: {$word} {$inv['invoice_number']} from {$company}" : "{$word} {$inv['invoice_number']} from {$company}";
    $body     = $reminder
        ? "<p>Hi {$first},</p><p>This is a friendly reminder that " . strtolower($word) . " <strong>" . e($inv['invoice_number']) . "</strong> for <strong>" . e($total) . "</strong> was due on <strong>" . date('j F Y', strtotime((string) $inv['due_date'])) . "</strong>.</p><p>If you've already paid, thank you, and please ignore this message.</p><p>" . e($company) . '</p>'
        : "<p>Hi {$first},</p><p>Please find attached " . strtolower($word) . " <strong>" . e($inv['invoice_number']) . "</strong> for <strong>" . e($total) . '</strong>' . ($inv['doc_type'] === 'invoice' && $inv['due_date'] ? ', due ' . date('j F Y', strtotime((string) $inv['due_date'])) : '') . ".</p><p>Thank you for your business!</p><p>" . e($company) . '</p>';
    $err = sendMail($to, $subject, emailLayout(e($subject), $body, "View {$word}", signedPdfUrl($pdf)), [$pdf], $user['email']);
    if ($err !== '') return $err;
    logDocEvent($invoiceId, $userId, $reminder ? 'reminder' : 'email', $to);
    if (!$reminder) getDB()->prepare('UPDATE invoices SET client_email = COALESCE(NULLIF(client_email, \'\'), ?) WHERE id = ?')->execute([$to, $invoiceId]);
    return '';
}
