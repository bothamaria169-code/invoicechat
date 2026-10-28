<?php
// The admin dashboard moved to /admin/.
require_once __DIR__ . '/config/config.php';
header('Location: ' . APP_URL . '/admin/', true, 301);
exit;
