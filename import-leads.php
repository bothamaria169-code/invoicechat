<?php
declare(strict_types=1);

/**
 * Command-line lead import for files too big to upload through the browser.
 *
 *   php import-leads.php <file.xlsx|csv> "<List name>" [--business] [--limit=1000]
 *
 * --business  only rows with a business/company name
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/includes/leads.php';

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Usage: php import-leads.php <file.xlsx|csv> \"List name\" [--business] [--limit=N]\n");
    exit(1);
}
$source   = $argv[2] ?? pathinfo($file, PATHINFO_FILENAME);
$business = in_array('--business', $argv, true);
$limit    = 0;
foreach ($argv as $a) {
    if (str_starts_with($a, '--limit=')) $limit = (int) substr($a, 8);
}

ini_set('memory_limit', '1024M');
$t = microtime(true);
$r = importLeads($file, $file, mb_substr($source, 0, 120), $business, $limit);
if ($r['error']) {
    fwrite(STDERR, "Error: {$r['error']}\n");
    exit(1);
}
printf("%s: %d new, %d already known, %d skipped (%.1fs). Columns: %s\n",
    $source, $r['added'], $r['updated'], $r['skipped'], microtime(true) - $t, implode(', ', $r['columns']));
