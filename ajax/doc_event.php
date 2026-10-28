<?php
declare(strict_types=1);

// The user tapped "Send on WhatsApp" (their own WhatsApp opens with the
// message ready). We can't see WhatsApp itself, so the tap is what we record.
require_once dirname(__DIR__) . '/includes/documents.php';

startAppSession();
if (!isLoggedIn()) jsonResponse(['error' => 'Unauthenticated'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValid()) jsonResponse(['error' => 'Session expired — refresh the page.'], 419);

$body   = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
$id     = (int) ($body['invoice_id'] ?? 0);
$kind   = in_array($body['kind'] ?? '', ['whatsapp', 'reminder_whatsapp'], true) ? $body['kind'] : 'whatsapp';
$userId = (int) $_SESSION['user_id'];

$s = getDB()->prepare('SELECT id FROM invoices WHERE id = ? AND user_id = ?');
$s->execute([$id, $userId]);
if (!$s->fetchColumn()) jsonResponse(['error' => 'Not found'], 404);

logDocEvent($id, $userId, $kind === 'reminder_whatsapp' ? 'reminder' : 'whatsapp', $kind === 'reminder_whatsapp' ? 'WhatsApp reminder' : 'From the user\'s WhatsApp');
jsonResponse(['ok' => true]);
