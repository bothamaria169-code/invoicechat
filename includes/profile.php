<?php
declare(strict_types=1);

/**
 * A user's business profile: the forms in setup.php (first-time wizard) and
 * settings.php (manage it any time) save through these, so both stay in step.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

const SA_BANKS = [
    'ABSA Bank' => '632005', 'African Bank' => '430000', 'Bank Zero' => '888000', 'Bidvest Bank' => '462005',
    'Capitec Bank' => '470010', 'Discovery Bank' => '679000', 'First National Bank (FNB)' => '250655', 'Investec Bank' => '580105',
    'Nedbank' => '198765', 'Old Mutual' => '462005', 'Sasfin Bank' => '683000', 'Standard Bank' => '051001', 'TymeBank' => '678910',
];
const TEMPLATES = [
    'classic'    => ['Classic', 'White and clean, logo left, colour rule'],
    'modern'     => ['Modern', 'Bold colour header band'],
    'freelancer' => ['Freelancer', 'Minimal, big "amount due" card'],
    'corporate'  => ['Corporate', 'Dark header, boxed details'],
    'banded'     => ['Banded', 'Colour side band, tinted boxes'],
];
const PAYMENT_TERMS = ['Due on receipt', 'Due in 7 days', 'Due in 14 days', 'Due in 30 days'];
const CURRENCIES    = ['ZAR', 'USD', 'EUR', 'GBP', 'BWP', 'NAD', 'NGN'];

/** "Account Holder: X\nBank Name: Y" → ['Account Holder' => 'X', ...] */
function bankFields(?string $text): array
{
    $out = [];
    foreach (preg_split('/\R/', (string) $text) as $line) {
        if (preg_match('/^\s*([^:]+):\s*(.+)$/', $line, $m)) $out[trim($m[1])] = trim($m[2]);
    }
    return $out;
}

/** A trimmed, length-limited POST field. */
function postText(string $key, int $len = 255): string
{
    return mb_substr(trim((string) ($_POST[$key] ?? '')), 0, $len);
}

/** Returns an error message, or '' when saved. */
function saveBusinessForm(int $uid, \InvoiceChat\ProfileManager $pm): string
{
    if (postText('company_name') === '') return 'Please add your business name — it goes at the top of every invoice.';
    $pm->update($uid, ['company_name' => postText('company_name'), 'address' => postText('address', 500), 'phone' => postText('phone', 50),
        'website' => postText('website'), 'tax_number' => postText('tax_number', 100)]);
    if (postText('full_name') !== '') getDB()->prepare('UPDATE users SET full_name = ? WHERE id = ?')->execute([postText('full_name', 150), $uid]);
    return '';
}

function saveBankingForm(int $uid, \InvoiceChat\ProfileManager $pm): void
{
    $bank  = postText('bank_name', 100) === 'Other' ? postText('bank_other', 100) : postText('bank_name', 100);
    $parts = array_filter([
        'Account Holder' => postText('holder', 150), 'Bank Name' => $bank, 'Account Number' => preg_replace('/[^\d ]/', '', postText('account', 30)),
        'Branch Code' => preg_replace('/\D/', '', postText('branch', 10)), 'Account Type' => postText('type', 30),
    ]);
    $pm->update($uid, ['bank_details' => implode("\n", array_map(fn($k, $v) => "{$k}: {$v}", array_keys($parts), $parts)) ?: null]);
    if (in_array(postText('payment_terms'), PAYMENT_TERMS, true)) $pm->updateSettings($uid, ['payment_terms' => postText('payment_terms')]);
}

function saveLookForm(int $uid, \InvoiceChat\ProfileManager $pm): void
{
    $currency = strtoupper(postText('currency', 3));
    $pm->updateSettings($uid, [
        'accent_color' => '#' . hexClean(postText('accent_color', 7) ?: '#3b5bfd'),
        'template'     => isset(TEMPLATES[$_POST['template'] ?? '']) ? $_POST['template'] : 'classic',
        'tax_rate'     => max(0, min(100, (float) ($_POST['tax_rate'] ?? 0))),
        'currency'     => in_array($currency, CURRENCIES, true) ? $currency : 'ZAR',
    ]);
}

/** Invoice numbers like INV-2026-00001; letters and digits only. */
function cleanPrefix(string $prefix, string $default): string
{
    $p = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix));
    return $p === '' ? $default : substr($p, 0, 8);
}

// ── Saved products & services ───────────────────────────────────

/** Most-used first. */
function userProducts(int $uid, int $limit = 200): array
{
    $s = getDB()->prepare('SELECT * FROM products WHERE user_id = ? ORDER BY use_count DESC, name LIMIT ' . $limit);
    $s->execute([$uid]);
    return $s->fetchAll();
}

function findProduct(int $uid, string $name): ?array
{
    $s = getDB()->prepare('SELECT * FROM products WHERE user_id = ? AND name_key = ?');
    $s->execute([$uid, mb_strtolower(trim($name))]);
    return $s->fetch() ?: null;
}

/** Adds or updates by name. */
function saveProduct(int $uid, string $name, float $price, string $desc = ''): void
{
    $name = mb_substr(trim($name), 0, 200);
    if ($name === '') return;
    getDB()->prepare('INSERT INTO products (user_id, name, name_key, unit_price, description) VALUES (?, ?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE name = VALUES(name), unit_price = VALUES(unit_price), description = VALUES(description)')
        ->execute([$uid, $name, mb_strtolower($name), max(0, $price), mb_substr(trim($desc), 0, 500)]);
}

function markProductUsed(int $productId): void
{
    getDB()->prepare('UPDATE products SET use_count = use_count + 1 WHERE id = ?')->execute([$productId]);
}

/** Fills the list from past invoices (latest price per item); returns how many were added. */
function importProductsFromInvoices(int $uid): int
{
    $s = getDB()->prepare('INSERT IGNORE INTO products (user_id, name, name_key, unit_price, description, use_count)
        SELECT ?, MAX(t.product_name), t.k, SUBSTRING_INDEX(GROUP_CONCAT(t.unit_price ORDER BY t.id DESC), \',\', 1), \'\', COUNT(*)
        FROM (SELECT ii.id, ii.product_name, LOWER(TRIM(ii.product_name)) AS k, ii.unit_price
              FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id
              WHERE i.user_id = ? AND TRIM(ii.product_name) <> \'\' AND ii.unit_price > 0) t
        GROUP BY t.k');
    $s->execute([$uid, $uid]);
    return $s->rowCount();
}
