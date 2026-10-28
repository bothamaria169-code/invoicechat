<?php
declare(strict_types=1);

// Payment slips are private (uploads/payments is closed to the web); admins view them here.
require_once __DIR__ . '/_shell.php';

$s = getDB()->prepare('SELECT proof_path FROM payments WHERE id = ?');
$s->execute([(int) ($_GET['id'] ?? 0)]);
$file = BASE_DIR . '/uploads/payments/' . basename((string) $s->fetchColumn());
if (!is_file($file)) { http_response_code(404); exit('Slip not found.'); }
header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($file));
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, no-store');
readfile($file);
