<?php
declare(strict_types=1);

// Serves invoice PDFs. /pdfs/ itself is closed (see .htaccess): a file opens
// only with a valid signature (the link in the chat, email or WhatsApp
// message), or for its owner or an admin who is signed in.
require_once __DIR__ . '/includes/auth.php';

$file = basename(str_replace('\\', '/', (string) ($_GET['f'] ?? '')));
$sig  = (string) ($_GET['s'] ?? '');
$path = PDF_DIR . $file;

$allowed = $file !== '' && str_ends_with(strtolower($file), '.pdf') && is_file($path) && pdfSignatureValid($file, $sig);

if (!$allowed && $file !== '' && is_file($path)) {
    startAppSession();
    $user = getCurrentUser();
    if ($user) {
        $s = getDB()->prepare('SELECT user_id FROM invoices WHERE pdf_path LIKE ? LIMIT 1');
        $s->execute(['%' . $file]);
        $owner = $s->fetchColumn();
        $allowed = $user['role'] === 'admin' || ($owner !== false && (int) $owner === (int) $user['id']);
    }
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
