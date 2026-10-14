-- 2026_10_13_001 · transactions.vendor_invoice_no — the number on the vendor's own bill.
--
-- Run by hand (§4.6). Adds one nullable column; writes no row. MySQL 8.0.29+
-- does this INSTANT — no table rebuild, only a brief metadata lock, so the
-- table's size does not matter. The code works before and after it runs.
-- Undo: 2026_10_13_001_vendor_invoice_no.rollback.sql

ALTER TABLE transactions
    ADD COLUMN vendor_invoice_no VARCHAR(50) NULL AFTER doc_no,
    ALGORITHM=INSTANT;

SELECT COUNT(*) AS ok FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'vendor_invoice_no';
