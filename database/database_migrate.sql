-- InvoiceChat — UPGRADE an existing (older) database to the current schema
-- ─────────────────────────────────────────────────────────────────
-- Use this ONLY if you already imported an older version of the schema
-- before and now need to bring it up to date. For a brand-new/empty
-- database, import database_cpanel.sql instead (no need to run this).
--
-- How to run on cPanel:
--   phpMyAdmin → click your database (swiftfusionco_invoicechat) in the
--   left list so it is selected → SQL tab → paste this whole file → Go.
--
-- Safe to run more than once: ADD COLUMN IF NOT EXISTS skips columns that
-- already exist, and the MODIFY statements just re-assert the enum lists.
--
-- NOTE: "IF NOT EXISTS" on ADD COLUMN is supported by MariaDB (which most
-- cPanel/XAMPP hosts use). If your host runs Oracle MySQL and reports a
-- syntax error, simply delete the words "IF NOT EXISTS" from the three
-- ADD COLUMN lines and run each only once.
-- ─────────────────────────────────────────────────────────────────

-- 1. User roles + active flag (admin panel)
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS role ENUM('user','admin') DEFAULT 'user' AFTER password_hash;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS is_active TINYINT(1) DEFAULT 1 AFTER role;

-- 2. Invoices ↔ Quotes
ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS doc_type ENUM('invoice','quote') NOT NULL DEFAULT 'invoice' AFTER user_id;

-- 3. Widen the status options (adds accepted / rejected for quotes)
ALTER TABLE invoices
  MODIFY COLUMN status ENUM('draft','sent','paid','accepted','rejected') DEFAULT 'draft';

-- 4. Add the two new templates to the allowed list
ALTER TABLE invoices
  MODIFY COLUMN template_name ENUM('classic','modern','freelancer','corporate','banded') DEFAULT 'classic';

-- 5. (Optional) make ZAR the default currency for NEW rows
--    Existing rows keep whatever currency they already have.
ALTER TABLE invoices
  MODIFY COLUMN currency VARCHAR(3) DEFAULT 'ZAR';

-- ─────────────────────────────────────────────────────────────────
-- Nothing to do for the accent-colour feature — it lives in the
-- existing users.settings JSON column, so no column change is needed.
--
-- After upgrading, make your account an admin:
--   UPDATE users SET role = 'admin' WHERE email = 'your@email.com';
-- ─────────────────────────────────────────────────────────────────
