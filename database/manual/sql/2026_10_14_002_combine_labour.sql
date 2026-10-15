-- 2026_10_14_002 · transactions.combine_labour — print a sale's labour as one row.
--
-- Run by hand (§4.6). Adds one column; writes no row. MySQL 8.0.29+ does this
-- INSTANT — no table rebuild, only a brief metadata lock, so the table's size
-- does not matter. Every existing invoice gets 0 and reprints exactly as before.
-- The code works before and after it runs: until then the box is accepted and
-- every invoice prints itemised.
-- Undo: 2026_10_14_002_combine_labour.rollback.sql

ALTER TABLE transactions
    ADD COLUMN combine_labour TINYINT(1) NOT NULL DEFAULT 0 AFTER notes,
    ALGORITHM=INSTANT;

SELECT COUNT(*) AS ok FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'combine_labour';
