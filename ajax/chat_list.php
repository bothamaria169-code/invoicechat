<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

startAppSession();

if (!isLoggedIn()) jsonResponse(['error' => 'Unauthenticated'], 401);

$userId = (int)$_SESSION['user_id'];
$db     = getDB();
$im     = new \InvoiceChat\InvoiceManager($db);

$invoices = $im->listForUser($userId, 100);

jsonResponse(['invoices' => $invoices]);
