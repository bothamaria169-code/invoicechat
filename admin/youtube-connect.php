<?php
declare(strict_types=1);

// Google login for YouTube uploads: start (?start) and callback (?code).
require_once __DIR__ . '/_shell.php';
require_once BASE_DIR . '/includes/marketing.php';

if (isset($_GET['start'])) {
    if (!YouTube::ready()) { flash('error', 'Add the Google Client ID and secret first (Settings → Google sign-in).'); redirect('admin/marketing.php?tab=connect'); }
    $_SESSION['yt_state'] = bin2hex(random_bytes(16));
    header('Location: ' . YouTube::loginUrl($_SESSION['yt_state']));
    exit;
}

if (isset($_GET['error'])) {
    flash('error', 'YouTube connection cancelled: ' . $_GET['error']);
} elseif (isset($_GET['code'])) {
    if (!hash_equals((string) ($_SESSION['yt_state'] ?? ''), (string) ($_GET['state'] ?? ''))) {
        flash('error', 'That Google link expired. Press Connect YouTube again.');
    } else {
        $err = YouTube::exchangeCode((string) $_GET['code']);
        flash($err === '' ? 'success' : 'error', $err ?: 'YouTube connected' . (get_setting('youtube_channel') ? ': ' . get_setting('youtube_channel') : '') . '.');
    }
    unset($_SESSION['yt_state']);
}
redirect('admin/marketing.php?tab=connect');
