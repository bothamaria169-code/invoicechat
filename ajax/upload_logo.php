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

$userId = (int)$_SESSION['user_id'];

if (empty($_FILES['logo'])) {
    jsonResponse(['error' => 'No file uploaded'], 400);
}

$file     = $_FILES['logo'];
$allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);

if (!in_array($mimeType, $allowed, true)) {
    jsonResponse(['error' => 'Only JPG, PNG, GIF and WebP images are allowed.'], 400);
}
if ($file['size'] > MAX_LOGO_SIZE) {
    jsonResponse(['error' => 'File is too large (max 5 MB).'], 400);
}

if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

$ext      = match($mimeType) {
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
};
$filename = 'logo_' . $userId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$dest     = UPLOAD_DIR . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    jsonResponse(['error' => 'Failed to save file.'], 500);
}

// Delete old logo
$pm   = new \InvoiceChat\ProfileManager(getDB());
$user = $pm->get($userId);
if ($user && $user['logo_path'] && file_exists($user['logo_path'])) {
    unlink($user['logo_path']);
}

$pm->update($userId, ['logo_path' => $dest]);

jsonResponse([
    'success'  => true,
    'logo_url' => APP_URL . '/uploads/logos/' . $filename,
    'message'  => 'uploaded',
]);
