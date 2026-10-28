<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

/**
 * Admin-editable settings (Google sign-in keys, pixels, SMTP overrides,
 * social tokens) in the app_settings table, cached for the request.
 */
function get_setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null || $key === '__flush__') {
        $cache = [];
        foreach (getDB()->query('SELECT k, v FROM app_settings') as $row) {
            $cache[$row['k']] = (string) $row['v'];
        }
        if ($key === '__flush__') return '';
    }
    return array_key_exists($key, $cache) && $cache[$key] !== '' ? $cache[$key] : $default;
}

function set_setting(string $key, string $value): void
{
    getDB()->prepare('REPLACE INTO app_settings (k, v) VALUES (?, ?)')->execute([$key, $value]);
    get_setting('__flush__');
}

/** Secrets are shown masked in forms; a blank or unchanged mask keeps the saved value. */
function save_secret_setting(string $key, string $posted): void
{
    $posted = trim($posted);
    if ($posted === '' || str_contains($posted, '•')) return;
    set_setting($key, $posted);
}

function masked(string $value): string
{
    return $value === '' ? '' : str_repeat('•', 8) . substr($value, -4);
}
