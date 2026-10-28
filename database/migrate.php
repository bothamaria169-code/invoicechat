<?php
declare(strict_types=1);

/**
 * Brings the database up to the current schema on the first request after a
 * deploy — no SQL import needed. Each step runs once; app_settings remembers
 * the version reached. Works on MySQL and MariaDB (columns are checked in
 * information_schema rather than with MariaDB-only "ADD COLUMN IF NOT EXISTS").
 */
const SCHEMA_VERSION = 2;

function runMigrations(PDO $db): void
{
    try {
        $v = (int) $db->query("SELECT v FROM app_settings WHERE k = 'schema_version'")->fetchColumn();
    } catch (PDOException) {
        $v = 0;
    }
    if ($v >= SCHEMA_VERSION) {
        return;
    }

    // Two requests can arrive together right after a deploy; let one migrate.
    if ((int) $db->query("SELECT GET_LOCK('invoicechat_migrate', 10)")->fetchColumn() !== 1) {
        return;
    }
    try {
        if ($v < 1) migrationV1($db);
        if ($v < 2) migrationV2($db);
        $db->prepare("REPLACE INTO app_settings (k, v) VALUES ('schema_version', ?)")->execute([(string) SCHEMA_VERSION]);
    } finally {
        $db->query("SELECT RELEASE_LOCK('invoicechat_migrate')");
    }
}

function columnExists(PDO $db, string $table, string $column): bool
{
    $s = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $s->execute([$table, $column]);
    return (bool) $s->fetchColumn();
}

function indexExists(PDO $db, string $table, string $index): bool
{
    $s = $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $s->execute([$table, $index]);
    return (bool) $s->fetchColumn();
}

function addColumn(PDO $db, string $table, string $column, string $definition): void
{
    if (!columnExists($db, $table, $column)) {
        $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

/** The original app tables, plus older installs brought up to date. */
function migrationV1(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS app_settings (
        k          VARCHAR(100) PRIMARY KEY,
        v          TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id            INT          PRIMARY KEY AUTO_INCREMENT,
        email         VARCHAR(255) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NULL,
        role          ENUM('user','admin') DEFAULT 'user',
        is_active     TINYINT(1)   DEFAULT 1,
        company_name  VARCHAR(255),
        address       TEXT,
        phone         VARCHAR(50),
        website       VARCHAR(255),
        bank_details  TEXT,
        tax_number    VARCHAR(100),
        logo_path     VARCHAR(255),
        settings      JSON,
        created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS invoices (
        id               INT           PRIMARY KEY AUTO_INCREMENT,
        user_id          INT           NOT NULL,
        doc_type         ENUM('invoice','quote') NOT NULL DEFAULT 'invoice',
        invoice_number   VARCHAR(50)   NOT NULL,
        client_name      VARCHAR(255),
        client_email     VARCHAR(255),
        client_phone     VARCHAR(50),
        issue_date       DATE,
        due_date         DATE,
        currency         VARCHAR(3)    DEFAULT 'ZAR',
        tax_rate         DECIMAL(5,2)  DEFAULT 0.00,
        discount_percent DECIMAL(5,2)  DEFAULT 0.00,
        notes            TEXT,
        template_name    ENUM('classic','modern','freelancer','corporate','banded') DEFAULT 'classic',
        total_amount     DECIMAL(10,2) DEFAULT 0.00,
        status           ENUM('draft','sent','paid','accepted','rejected') DEFAULT 'draft',
        pdf_path         VARCHAR(255),
        created_at       DATETIME      DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS invoice_items (
        id           INT           PRIMARY KEY AUTO_INCREMENT,
        invoice_id   INT           NOT NULL,
        product_name VARCHAR(255)  NOT NULL,
        quantity     DECIMAL(10,2) NOT NULL DEFAULT 1,
        unit_price   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        description  TEXT,
        FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
        INDEX idx_invoice_id (invoice_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS chats (
        id           INT         PRIMARY KEY AUTO_INCREMENT,
        user_id      INT         NOT NULL,
        invoice_id   INT,
        sender       ENUM('user','bot') NOT NULL,
        message      TEXT        NOT NULL,
        message_type VARCHAR(50) DEFAULT 'text',
        extra_data   JSON,
        timestamp    DATETIME    DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
        FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
        INDEX idx_user_invoice (user_id, invoice_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Older installs predate these columns.
    addColumn($db, 'users', 'role', "ENUM('user','admin') DEFAULT 'user' AFTER password_hash");
    addColumn($db, 'users', 'is_active', 'TINYINT(1) DEFAULT 1 AFTER role');
    addColumn($db, 'invoices', 'doc_type', "ENUM('invoice','quote') NOT NULL DEFAULT 'invoice' AFTER user_id");
    $db->exec("ALTER TABLE invoices MODIFY COLUMN status ENUM('draft','sent','paid','accepted','rejected') DEFAULT 'draft'");
    $db->exec("ALTER TABLE invoices MODIFY COLUMN template_name ENUM('classic','modern','freelancer','corporate','banded') DEFAULT 'classic'");
}

/** Accounts (Google, password reset, sign-up tracking) and marketing. */
function migrationV2(PDO $db): void
{
    // Google-only accounts have no password.
    $db->exec('ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(255) NULL');
    addColumn($db, 'users', 'full_name', 'VARCHAR(150) NULL AFTER email');
    addColumn($db, 'users', 'google_id', 'VARCHAR(64) NULL AFTER password_hash');
    addColumn($db, 'users', 'avatar_url', 'VARCHAR(500) NULL AFTER google_id');
    addColumn($db, 'users', 'signup_method', "VARCHAR(20) NOT NULL DEFAULT 'email'");
    addColumn($db, 'users', 'signup_source', 'VARCHAR(500) NULL');
    addColumn($db, 'users', 'signup_ip', 'VARCHAR(45) NULL');
    addColumn($db, 'users', 'last_login_at', 'DATETIME NULL');
    addColumn($db, 'users', 'login_count', 'INT NOT NULL DEFAULT 0');
    if (!indexExists($db, 'users', 'uq_google_id')) {
        $db->exec('ALTER TABLE users ADD UNIQUE KEY uq_google_id (google_id)');
    }
    if (!indexExists($db, 'invoices', 'idx_created')) {
        $db->exec('ALTER TABLE invoices ADD INDEX idx_created (created_at)');
    }

    $db->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id          INT PRIMARY KEY AUTO_INCREMENT,
        user_id     INT NOT NULL,
        token_hash  CHAR(64) NOT NULL,
        expires_at  DATETIME NOT NULL,
        used_at     DATETIME NULL,
        ip          VARCHAR(45) NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_token (token_hash),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id          INT PRIMARY KEY AUTO_INCREMENT,
        email       VARCHAR(255) NOT NULL,
        ip          VARCHAR(45) NOT NULL,
        success     TINYINT(1) NOT NULL DEFAULT 0,
        method      VARCHAR(20) NOT NULL DEFAULT 'password',
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email_time (email, created_at),
        INDEX idx_ip_time (ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS marketing_posts (
        id           INT PRIMARY KEY AUTO_INCREMENT,
        platform     VARCHAR(20) NOT NULL,
        kind         VARCHAR(20) NOT NULL DEFAULT 'text',
        caption      TEXT,
        link_url     VARCHAR(500) NULL,
        media_path   VARCHAR(255) NULL,
        status       VARCHAR(20) NOT NULL DEFAULT 'scheduled',
        scheduled_at DATETIME NULL,
        posted_at    DATETIME NULL,
        remote_id    VARCHAR(100) NULL,
        error        VARCHAR(500) NULL,
        created_by   INT NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status_time (status, scheduled_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS marketing_events (
        id          INT PRIMARY KEY AUTO_INCREMENT,
        event       VARCHAR(50) NOT NULL,
        user_id     INT NULL,
        channel     VARCHAR(20) NOT NULL,
        ok          TINYINT(1) NOT NULL DEFAULT 0,
        detail      VARCHAR(500) NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    seedAdmin($db);
}

/** The admin@ account: created if missing, and always kept an active admin. */
function seedAdmin(PDO $db): void
{
    $email = ADMIN_EMAIL;
    $s = $db->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $s->execute([$email]);
    $row = $s->fetch();
    $seed = (string) secret('admin_seed_password', '');

    if (!$row) {
        if ($seed === '' || $seed === 'CHANGE_ME') {
            return;
        }
        $db->prepare("INSERT INTO users (email, full_name, password_hash, role, is_active, company_name, settings, signup_method)
                      VALUES (?, 'Administrator', ?, 'admin', 1, 'SwiftFusion', ?, 'seed')")
           ->execute([$email, password_hash($seed, PASSWORD_DEFAULT), json_encode([
               'currency' => 'ZAR', 'tax_rate' => 0, 'discount' => 0, 'template' => 'classic',
               'payment_terms' => 'Due in 30 days', 'accent_color' => '#3b5bfd',
           ])]);
        return;
    }
    $db->prepare("UPDATE users SET role = 'admin', is_active = 1 WHERE id = ?")->execute([$row['id']]);
    if (empty($row['password_hash']) && $seed !== '' && $seed !== 'CHANGE_ME') {
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($seed, PASSWORD_DEFAULT), $row['id']]);
    }
}
