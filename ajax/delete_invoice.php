<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

startAppSession();

if (!isLoggedIn()) jsonResponse(['error' => 'Unauthenticated'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
if (!csrfValid()) jsonResponse(['error' => 'Session expired — refresh the page.'], 419);

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$invoiceId = (int)($body['invoice_id'] ?? 0);
$userId    = (int)$_SESSION['user_id'];

if (!$invoiceId) jsonResponse(['error' => 'Invoice ID required'], 400);

$db = getDB();
$im = new \InvoiceChat\InvoiceManager($db);
$im->delete($invoiceId, $userId);

// Clear session if this was the active invoice
if (($_SESSION['invoice_id'] ?? null) === $invoiceId) {
    $_SESSION['invoice_id'] = null;
    $_SESSION['bot_state']  = 'idle';
    $_SESSION['bot_data']   = [];
}

jsonResponse(['success' => true]);
