<?php
declare(strict_types=1);

// Both legs of "Continue with Google": without ?code it sends the visitor to
// Google; Google sends them back here with ?code and ?state.
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/classes/GoogleAuth.php';

startAppSession();

if (!GoogleAuth::enabled()) {
    flash('error', 'Google sign-in is not switched on yet.');
    redirect('login.php');
}

if (isset($_GET['error'])) {
    flash('error', 'Google sign-in was cancelled.');
    redirect('login.php');
}

if (!isset($_GET['code'])) {
    $_SESSION['google_state'] = bin2hex(random_bytes(16));
    header('Location: ' . GoogleAuth::loginUrl($_SESSION['google_state']));
    exit;
}

$state = (string) ($_GET['state'] ?? '');
if ($state === '' || !hash_equals((string) ($_SESSION['google_state'] ?? ''), $state)) {
    flash('error', 'That Google sign-in link expired. Please try again.');
    redirect('login.php');
}
unset($_SESSION['google_state']);

try {
    $user = loginWithGoogle(GoogleAuth::profile((string) $_GET['code']));
} catch (\Throwable $e) {
    error_log('InvoiceChat Google sign-in: ' . $e->getMessage());
    flash('error', $e->getMessage());
    redirect('login.php');
}

if (is_string($user)) {
    flash('error', $user);
    redirect('login.php');
}

redirect($user['role'] === 'admin' ? 'admin/' : 'chat.php');
