<?php
require_once __DIR__ . '/includes/auth.php';

startAppSession();
// Signing out is a POST with the CSRF token, so another site can't sign people out.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfValid()) {
    logoutUser();
    redirect('login.php');
}
redirect(isLoggedIn() ? 'chat.php' : 'login.php');
