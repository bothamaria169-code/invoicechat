<?php
declare(strict_types=1);

// Serves invoice PDFs. /pdfs/ itself is closed (see .htaccess): a file opens
// only with a valid signature (the link in the chat, email or WhatsApp
// message), or for its owner or an admin who is signed in.
require_once __DIR__ . '/includes/auth.php';

$file = basename(str_replace('\\', '/', (string) ($_GET['f'] ?? '')));
$sig  = (string) ($_GET['s'] ?? '');
$path = PDF_DIR . $file;

$signed  = $file !== '' && str_ends_with(strtolower($file), '.pdf') && is_file($path) && pdfSignatureValid($file, $sig);
$allowed = $signed;
$doc     = false;
$viewer  = null;

if ($file !== '' && is_file($path)) {
    $s = getDB()->prepare('SELECT id, user_id FROM invoices WHERE pdf_path LIKE ? LIMIT 1');
    $s->execute(['%' . $file]);
    $doc = $s->fetch();
    startAppSession();
    $viewer = isLoggedIn() ? getCurrentUser() : null;
    if (!$allowed && $viewer) {
        $allowed = $viewer['role'] === 'admin' || ($doc && (int) $doc['user_id'] === (int) $viewer['id']);
    }
}

// Someone other than the owner opened the signed link: the client saw it.
// (Link preview bots are skipped so a WhatsApp preview doesn't count.)
if ($allowed && $signed && $doc && (!$viewer || (int) $viewer['id'] !== (int) $doc['user_id'] && $viewer['role'] !== 'admin')
    && !preg_match('/bot|crawler|spider|facebookexternalhit|whatsapp|preview|slack|telegram|skype/i', userAgent())) {
    require_once __DIR__ . '/includes/documents.php';
    logDocEvent((int) $doc['id'], (int) $doc['user_id'], 'viewed', substr(userAgent(), 0, 120));
}

if (!$allowed) {
    http_response_code(404);
    exit('This document link is invalid or has been removed.');
}

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . preg_replace('/_u\d+_[a-f0-9]{8}(?=\.pdf$)/', '', $file) . '"');
header('X-Robots-Tag: noindex');
header('Cache-Control: private, max-age=3600');
readfile($path);
