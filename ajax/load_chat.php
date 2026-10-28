<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

startAppSession();

if (!isLoggedIn()) jsonResponse(['error' => 'Unauthenticated'], 401);

$invoiceId = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : null;
$userId    = (int)$_SESSION['user_id'];
$db        = getDB();

// Sync session invoice_id when loading a past chat
if ($invoiceId) {
    $_SESSION['invoice_id'] = $invoiceId;
    $_SESSION['bot_state']  = 'invoice_send'; // Past invoice — allow re-send
    $_SESSION['bot_data']   = [];
}

// Load messages
$where  = $invoiceId ? 'invoice_id = ?' : 'invoice_id IS NULL';
$params = $invoiceId ? [$userId, $invoiceId] : [$userId];
$stmt   = $db->prepare(
    "SELECT id, sender, message, message_type, extra_data, timestamp
     FROM chats
     WHERE user_id = ? AND {$where}
     ORDER BY timestamp ASC, id ASC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Decode extra_data JSON
$messages = array_map(function ($row) {
    $extra = $row['extra_data'] ? json_decode($row['extra_data'], true) : null;
    // Older messages link straight to /pdfs/, which is now closed; sign them.
    if (is_array($extra) && ($extra['type'] ?? '') === 'file' && !empty($extra['url'])) {
        $path = (string) parse_url($extra['url'], PHP_URL_PATH);
        if (str_contains($path, '/pdfs/')) {
            $extra['url'] = signedPdfUrl(rawurldecode(basename($path)));
        }
    }
    return [
        'id'        => $row['id'],
        'sender'    => $row['sender'],
        'type'      => $row['message_type'],
        'content'   => $row['message'],
        'extra'     => $extra,
        'timestamp' => $row['timestamp'],
    ];
}, $rows);

// Load invoice info for the header
$invoice = null;
if ($invoiceId) {
    $stmt = $db->prepare(
        'SELECT doc_type, invoice_number, client_name, status, total_amount, currency
         FROM invoices WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$invoiceId, $userId]);
    $invoice = $stmt->fetch() ?: null;
}

jsonResponse(['messages' => $messages, 'invoice' => $invoice]);
