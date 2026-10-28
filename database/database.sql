-- InvoiceChat Database Schema
-- Run this in phpMyAdmin or via MySQL CLI: mysql -u root -p < database.sql

CREATE DATABASE IF NOT EXISTS invoicechat
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE invoicechat;

-- ─────────────────────────────────────────────────────────────────
-- USERS
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id            INT          PRIMARY KEY AUTO_INCREMENT,
    email         VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
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
);

-- Run these if upgrading an existing database:
-- ALTER TABLE users ADD COLUMN role ENUM('user','admin') DEFAULT 'user';
-- ALTER TABLE users ADD COLUMN is_active TINYINT(1) DEFAULT 1;
-- To make a user admin: UPDATE users SET role = 'admin' WHERE email = 'your@email.com';

-- ─────────────────────────────────────────────────────────────────
-- INVOICES
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS invoices (
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
);

-- Run these if upgrading an existing database:
-- ALTER TABLE invoices ADD COLUMN doc_type ENUM('invoice','quote') NOT NULL DEFAULT 'invoice' AFTER user_id;
-- ALTER TABLE invoices MODIFY COLUMN status ENUM('draft','sent','paid','accepted','rejected') DEFAULT 'draft';

-- ─────────────────────────────────────────────────────────────────
-- INVOICE LINE ITEMS
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS invoice_items (
    id           INT           PRIMARY KEY AUTO_INCREMENT,
    invoice_id   INT           NOT NULL,
    product_name VARCHAR(255)  NOT NULL,
    quantity     DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    description  TEXT,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    INDEX idx_invoice_id (invoice_id)
);

-- ─────────────────────────────────────────────────────────────────
-- CHAT MESSAGES
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS chats (
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
);
