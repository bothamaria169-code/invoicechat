<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/billing.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use InvoiceChat\BotEngine;

startAppSession();

if (!isLoggedIn()) jsonResponse(['error' => 'Unauthenticated'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
if (!csrfValid()) jsonResponse(['error' => 'Session expired — refresh the page.'], 419);

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$input     = trim((string)($body['message'] ?? ''));
$invoiceId = isset($body['invoice_id']) ? (int)$body['invoice_id'] : null;
if ($invoiceId === 0) $invoiceId = null;

// Retrieve session state
$state     = $_SESSION['bot_state']  ?? 'idle';
$botData   = $_SESSION['bot_data']   ?? [];
$sessInv   = $_SESSION['invoice_id'] ?? null;

// Use the invoice_id from the client (it knows the active chat)
if ($invoiceId === null && $sessInv) $invoiceId = $sessInv;

$userId = (int)$_SESSION['user_id'];
$db     = getDB();

// The open document may have been deleted (another tab, or by an admin): start fresh.
if ($invoiceId !== null) {
    $own = $db->prepare('SELECT 1 FROM invoices WHERE id = ? AND user_id = ?');
    $own->execute([$invoiceId, $userId]);
    if (!$own->fetchColumn()) {
        $invoiceId = null;
        $_SESSION['invoice_id'] = null;
        if (!str_starts_with($state, 'onboard_') && !str_starts_with($state, 'settings_')) $state = 'idle';
    }
}
$bot    = new BotEngine($db, $userId);

// ── Handle special init call ──────────────────────────────────────
if ($input === '__init__') {
    $messages = $bot->greet($state);
    $_SESSION['bot_state'] = $state;
    saveBotMessages($db, $userId, $invoiceId, $messages);
    jsonResponse(['messages' => $messages, 'invoice_id' => $invoiceId]);
}

// ── Save user message ─────────────────────────────────────────────
if ($input !== '') {
    $db->prepare(
        'INSERT INTO chats (user_id, invoice_id, sender, message, message_type)
         VALUES (?, ?, "user", ?, "text")'
    )->execute([$userId, $invoiceId, $input]);
}

// ── Process through bot ───────────────────────────────────────────
try {
    // "Describe it" AI drafts: /ai <sentence>, or a sentence typed while idle.
    if (preg_match('#^/ai\b#i', $input)) {
        $text = trim(substr($input, 3));
        $messages = $text === ''
            ? [msg("Describe the invoice or quote in one message, e.g.\n\n*/ai Invoice Acme Ltd for 3 logo designs at R500 each and hosting R200, 15% VAT, due in 14 days*")]
            : aiDraftInChat($userId, $text, $state, $botData, $invoiceId);
    } elseif ($state === 'idle' && !str_starts_with($input, '/') && looksLikeDocumentRequest($input)) {
        $messages = aiDraftInChat($userId, $input, $state, $botData, $invoiceId);
    } else {
        $messages = $bot->process($input, $state, $botData, $invoiceId);
    }
    $messages = handleSpecialMessages($messages, $db, $userId, $invoiceId);
    $usage    = usageBadge(freshUser($userId));
} catch (\Throwable $e) {
    error_log('InvoiceChat BotEngine error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonResponse([
        'messages'   => [['type' => 'text', 'content' => 'Something went wrong. Please type /cancel and try again.']],
        'invoice_id' => $invoiceId,
    ]);
}

// ── Persist state ─────────────────────────────────────────────────
$_SESSION['bot_state']  = $state;
$_SESSION['bot_data']   = $botData;
$_SESSION['invoice_id'] = $invoiceId;

// ── Save bot messages ─────────────────────────────────────────────
saveBotMessages($db, $userId, $invoiceId, $messages);

jsonResponse(['messages' => $messages, 'invoice_id' => $invoiceId, 'usage' => $usage]);

// ─────────────────────────────────────────────────────────────────

function saveBotMessages(PDO $db, int $userId, ?int $invoiceId, array $messages): void
{
    foreach ($messages as $m) {
        $type  = $m['type'] ?? 'text';
        $text  = $m['content'] ?? ($m['label'] ?? '');
        $extra = null;
        if ($type !== 'text') {
            $extra = json_encode($m);
            if ($type === 'buttons') $text = $m['content'];
        }
        $db->prepare(
            'INSERT INTO chats (user_id, invoice_id, sender, message, message_type, extra_data)
             VALUES (?, ?, "bot", ?, ?, ?)'
        )->execute([$userId, $invoiceId, $text, $type, $extra]);
    }
}

function handleSpecialMessages(array $messages, PDO $db, int $userId, ?int &$invoiceId): array
{
    $result = [];
    foreach ($messages as $m) {
        if (($m['type'] ?? '') === 'generate_pdf') {
            $result = array_merge($result, generatePdfInline($db, $userId, $m['invoice_id']));
        } elseif (($m['type'] ?? '') === 'send_email') {
            $result = array_merge($result, sendEmailInline($db, $userId, $m['invoice_id'], $m['email']));
        } elseif (($m['type'] ?? '') === 'send_whatsapp') {
            $result = array_merge($result, sendWhatsAppInline($db, $userId, $m['invoice_id'], $m['phone']));
        } else {
            $result[] = $m;
        }
    }
    return $result;
}

function generatePdfInline(PDO $db, int $userId, int $invoiceId): array
{
    require_once dirname(__DIR__) . '/includes/functions.php';
    require_once dirname(__DIR__) . '/config/config.php';

    $im      = new \InvoiceChat\InvoiceManager($db);
    $pm      = new \InvoiceChat\ProfileManager($db);
    $invoice = $im->get($invoiceId, $userId);
    $items   = $im->getItems($invoiceId);
    $user    = $pm->get($userId) ?? [];

    if (!$invoice || empty($items)) {
        return [['type' => 'text', 'content' => "Cannot generate PDF: invoice or items missing."]];
    }

    // Plans & credits: the first PDF of a document uses one from the monthly
    // allowance or one credit; re-making it later is free.
    $blocked = chargeDocument($userId, $invoiceId);
    if ($blocked !== '') {
        $GLOBALS['state'] = 'invoice_confirm'; // so "Generate PDF" works again after topping up
        return [
            ['type' => 'text', 'content' => $blocked],
            ['type' => 'buttons', 'content' => 'Once your top-up is approved, tap below.', 'buttons' => ['Generate PDF']],
        ];
    }

    $tpl     = $invoice['template_name'] ?? 'classic';
    $tplFile = dirname(__DIR__) . "/templates/{$tpl}.php";
    if (!file_exists($tplFile)) $tplFile = dirname(__DIR__) . '/templates/classic.php';

    ob_start();
    include $tplFile;
    $html = ob_get_clean();

    // Free plan documents carry a small footer — it's also our best advert.
    if (!isPro(freshUser($userId))) {
        $footer = '<div style="position:fixed;bottom:-18px;left:0;right:0;text-align:center;font-family:DejaVu Sans,sans-serif;font-size:8px;color:#9aa3b2">'
                . 'Created free with InvoiceChat · ' . e(preg_replace('#^https?://#', '', APP_URL)) . '</div>';
        $html = stripos($html, '</body>') !== false ? str_ireplace('</body>', $footer . '</body>', $html) : $html . $footer;
    }

    try {
        $options = new \Dompdf\Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('chroot', BASE_DIR);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        if (!is_dir(PDF_DIR)) mkdir(PDF_DIR, 0755, true);
        // Numbers restart per user, so the file name carries the user id and a
        // random tag: users can't overwrite each other's PDFs or guess names.
        $old = pdfFile($invoice['pdf_path'] ?? null);
        if ($old) @unlink($old);
        $word     = ($invoice['doc_type'] ?? 'invoice') === 'quote' ? 'Quote' : 'Invoice';
        $filename = "{$word}_{$invoice['invoice_number']}_u{$userId}_" . bin2hex(random_bytes(4)) . '.pdf';
        $path     = PDF_DIR . $filename;
        file_put_contents($path, $dompdf->output());

        $im->update($invoiceId, $userId, ['pdf_path' => $path, 'status' => 'draft']);
        $url = signedPdfUrl($filename);

        $isQuote = ($invoice['doc_type'] ?? 'invoice') === 'quote';
        $word    = $isQuote ? 'Quote' : 'Invoice';
        $buttons = $isQuote
            ? ['Send by Email', 'Send via WhatsApp', 'Accept quote', 'Done']
            : ['Send by Email', 'Send via WhatsApp', 'Done'];

        return [
            ['type' => 'text',    'content' => "{$word} PDF generated successfully!"],
            ['type' => 'file',    'url' => $url, 'label' => "Download {$invoice['invoice_number']}.pdf"],
            ['type' => 'buttons', 'content' => "What would you like to do next?",
             'buttons' => $buttons],
        ];
    } catch (\Throwable $e) {
        refundDocument($userId, $invoiceId);
        return [['type' => 'text', 'content' => "PDF generation failed: " . $e->getMessage()]];
    }
}

function sendEmailInline(PDO $db, int $userId, int $invoiceId, string $toEmail): array
{
    $im      = new \InvoiceChat\InvoiceManager($db);
    $pm      = new \InvoiceChat\ProfileManager($db);
    $invoice = $im->get($invoiceId, $userId);
    $user    = $pm->get($userId) ?? [];
    $pdf     = $invoice ? pdfFile($invoice['pdf_path']) : null;

    if (!$pdf) {
        return [['type' => 'text', 'content' => "Please generate the PDF first."]];
    }

    $isQuote  = ($invoice['doc_type'] ?? 'invoice') === 'quote';
    $word     = $isQuote ? 'Quote' : 'Invoice';
    $fromName = $user['company_name'] ?: APP_NAME;
    $total    = formatMoney((float) $invoice['total_amount'], (string) $invoice['currency']);
    $html = emailLayout(
        e("{$word} {$invoice['invoice_number']} from {$fromName}"),
        '<p>Hi ' . e($invoice['client_name'] ?: 'there') . ',</p>'
        . '<p>Please find attached ' . strtolower($word) . ' <strong>' . e($invoice['invoice_number']) . '</strong> for <strong>' . e($total) . '</strong>.</p>'
        . '<p>Thank you for your business!</p><p>' . e($fromName) . '</p>',
        "View {$word}", signedPdfUrl($pdf)
    );
    // Replies go to the business, not to the InvoiceChat mailbox.
    $err = sendMail($toEmail, "{$word} {$invoice['invoice_number']} from {$fromName}", $html, [$pdf], $user['email'] ?? null);
    if ($err !== '') {
        return [['type' => 'text', 'content' => "Failed to send email: {$err}"]];
    }

    $im->update($invoiceId, $userId, ['status' => 'sent']);
    return [
        ['type' => 'text', 'content' => "{$word} sent successfully to *{$toEmail}*!"],
        ['type' => 'buttons', 'content' => "Anything else?",
         'buttons' => $isQuote ? ['Send via WhatsApp', '/accept – Mark accepted', 'Done'] : ['Send via WhatsApp', '/paid – Mark as paid', 'Done']],
    ];
}

function sendWhatsAppInline(PDO $db, int $userId, int $invoiceId, string $phone): array
{
    $im      = new \InvoiceChat\InvoiceManager($db);
    $invoice = $im->get($invoiceId, $userId);
    $pdf     = $invoice ? pdfFile($invoice['pdf_path']) : null;

    if (!$pdf) {
        return [['type' => 'text', 'content' => "Please generate the PDF first."]];
    }

    $isQuote = ($invoice['doc_type'] ?? 'invoice') === 'quote';
    $word    = $isQuote ? 'Quote' : 'Invoice';
    $pdfUrl  = signedPdfUrl($pdf);
    $caption = "{$word} {$invoice['invoice_number']}";
    $okMsgs  = [
        ['type' => 'text', 'content' => "{$word} sent via WhatsApp to *{$phone}*!"],
        ['type' => 'buttons', 'content' => "Anything else?",
         'buttons' => $isQuote ? ['/accept – Mark accepted', 'Done'] : ['/paid – Mark as paid', 'Done']],
    ];

    // ── Preferred: Meta WhatsApp Cloud API ────────────────────────
    if (WHATSAPP_TOKEN !== '' && WHATSAPP_PHONE_NUMBER_ID !== '') {
        // PDF link must be publicly reachable over HTTPS for Meta to fetch it.
        if (str_starts_with($pdfUrl, 'http://localhost')) {
            return [['type' => 'text', 'content' =>
                "WhatsApp Cloud API needs a public HTTPS link to the PDF, but APP_URL is still localhost.\n\n"
                . "Upload the app online and set APP_URL in config/config.php to your live domain, then try again.\n\n"
                . "PDF: {$pdfUrl}"]];
        }
        try {
            $wa  = new \InvoiceChat\WhatsApp(WHATSAPP_TOKEN, WHATSAPP_PHONE_NUMBER_ID, WHATSAPP_API_VERSION);
            $res = $wa->sendDocument($phone, $pdfUrl, basename($pdf), $caption);
            if ($res['ok']) {
                $im->update($invoiceId, $userId, ['status' => 'sent']);
                return $okMsgs;
            }
            return [['type' => 'text', 'content' =>
                "WhatsApp sending failed: {$res['error']}\n\n"
                . "Tip: the recipient must have messaged your number in the last 24h, "
                . "or you must use an approved template. You can also share this link:\n{$pdfUrl}"]];
        } catch (\Throwable $e) {
            return [['type' => 'text', 'content' => "WhatsApp sending failed: " . $e->getMessage()]];
        }
    }

    // ── Fallback: Twilio ──────────────────────────────────────────
    if (TWILIO_SID !== '' && TWILIO_TOKEN !== '') {
        try {
            $client = new \Twilio\Rest\Client(TWILIO_SID, TWILIO_TOKEN);
            $to     = 'whatsapp:' . preg_replace('/\s+/', '', $phone);
            $client->messages->create($to, [
                'from'      => TWILIO_FROM,
                'body'      => "{$caption} from your supplier.",
                'mediaUrl'  => [$pdfUrl],
            ]);
            $im->update($invoiceId, $userId, ['status' => 'sent']);
            return $okMsgs;
        } catch (\Throwable $e) {
            return [['type' => 'text', 'content' => "WhatsApp sending failed: " . $e->getMessage()]];
        }
    }

    // ── Nothing configured ────────────────────────────────────────
    return [['type' => 'text', 'content' =>
        "WhatsApp sending isn't configured yet. Add your Meta WhatsApp Cloud API credentials "
        . "(WHATSAPP_TOKEN, WHATSAPP_PHONE_NUMBER_ID) in config/config.php.\n\n"
        . "For now you can share this PDF link manually:\n{$pdfUrl}"]];
}
