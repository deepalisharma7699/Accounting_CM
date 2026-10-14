-- =============================================================================
-- 2026_09_30_002  ·  PREVIEW — reports only. Deletes nothing, changes no row.
--
-- Run this FIRST and read every block. It reports exactly what
-- `2026_09_30_002_clear_trading_documents.sql` would delete, and flags the four
-- things that survive the clear and can still make the books look wrong after.
--
--   mysql -u <user> -p <database> --table \
--     < database/manual/2026_09_30_002_clear_trading_documents.preview.sql
--
-- Run the whole file in ONE session: it builds a TEMPORARY table, which does not
-- survive a reconnect. The table holds ids, not type names, deliberately —
-- matching a VARCHAR column against a session variable compares two different
-- collations and errors (1267), and the set has to be defined once or the blocks
-- below would be reporting on different sets from each other.
-- =============================================================================

SET @tenant := 1;   -- <<< CHANGE ME. The workshop whose trading data is tangled.

-- 1 ---------------------------------------------------------------------------
-- Which workshop. If this prints NOTHING, @tenant is wrong. Stop here.
SELECT '1. WORKSHOP' AS step;
SELECT id AS tenant_id, name AS workshop FROM tenants WHERE id = @tenant;

-- The doomed set. This literal list is the ONLY place it is written in this
-- file, and it must stay identical to the one in the forward step.
CREATE TEMPORARY TABLE doomed_txn (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO doomed_txn (id)
SELECT id FROM transactions
WHERE tenant_id = @tenant
  AND type IN ('sale','purchase','sales_return','purchase_return',
               'opening','stock_adjustment','receipt','payment');

-- 2 ---------------------------------------------------------------------------
SELECT '2. DOCUMENTS THAT WILL BE DELETED' AS step;
SELECT t.type, t.status, COUNT(*) AS documents, SUM(t.total) AS total_value
FROM transactions t JOIN doomed_txn d ON d.id = t.id
GROUP BY t.type, t.status WITH ROLLUP;

-- 3 ---------------------------------------------------------------------------
-- What stays. Read this and make sure you agree with it.
SELECT '3. DOCUMENTS THAT WILL SURVIVE' AS step;
SELECT t.type, t.status, COUNT(*) AS documents, SUM(t.total) AS total_value
FROM transactions t
WHERE t.tenant_id = @tenant AND t.id NOT IN (SELECT id FROM doomed_txn)
GROUP BY t.type, t.status WITH ROLLUP;

-- 4 ---------------------------------------------------------------------------
-- The rows hanging off them. One statement each: MySQL cannot reference the same
-- TEMPORARY table twice in a single query (1137, "Can't reopen table"), so these
-- cannot be folded into one UNION.
SELECT '4. CHILD ROWS THAT GO WITH THEM' AS step;
SELECT 'stock_movements'      AS child_table, COUNT(*) AS rows_affected FROM stock_movements      x JOIN doomed_txn d ON d.id = x.transaction_id;
SELECT 'transaction_lines'    AS child_table, COUNT(*) AS rows_affected FROM transaction_lines    x JOIN doomed_txn d ON d.id = x.transaction_id;
SELECT 'journal_entries'      AS child_table, COUNT(*) AS rows_affected FROM journal_entries      x JOIN doomed_txn d ON d.id = x.transaction_id;
SELECT 'transaction_payments' AS child_table, COUNT(*) AS rows_affected FROM transaction_payments x JOIN doomed_txn d ON d.id = x.transaction_id;
SELECT 'transaction_staff'    AS child_table, COUNT(*) AS rows_affected FROM transaction_staff    x JOIN doomed_txn d ON d.id = x.transaction_id;
SELECT 'invoice_shares'       AS child_table, COUNT(*) AS rows_affected FROM invoice_shares       x JOIN doomed_txn d ON d.id = x.transaction_id;
SELECT 'allocations (as settlement)' AS child_table, COUNT(*) AS rows_affected FROM transaction_allocations x JOIN doomed_txn d ON d.id = x.settlement_transaction_id;
SELECT 'allocations (as bill)'       AS child_table, COUNT(*) AS rows_affected FROM transaction_allocations x JOIN doomed_txn d ON d.id = x.bill_transaction_id;
SELECT 'opening_imports'      AS child_table, COUNT(*) AS rows_affected FROM opening_imports WHERE tenant_id = @tenant;

-- 5 ---------------------------------------------------------------------------
-- Stock as it stands now. After the clear every one of these is nil and the
-- Inventory account (1200) is nil with it. KEEP THIS OUTPUT — it is the
-- before-picture you will check the after-picture against.
SELECT '5. STOCK NOW — all of it goes to nil' AS step;
SELECT COUNT(DISTINCT variant_id) AS variants_with_movement,
       SUM(quantity)              AS total_quantity,
       SUM(value)                 AS total_stock_value
FROM stock_movements WHERE tenant_id = @tenant;

SELECT '5b. STOCK NOW, per variant' AS step;
SELECT sm.variant_id, i.name AS item, SUM(sm.quantity) AS qty_on_hand, SUM(sm.value) AS stock_value
FROM stock_movements sm JOIN items i ON i.id = sm.item_id
WHERE sm.tenant_id = @tenant GROUP BY sm.variant_id, i.name ORDER BY i.name;

-- 6 ---------------------------------------------------------------------------
-- *** WARNING 1 ***  Manual journals and expenses are KEPT. A manual journal
-- posted straight to Inventory, Debtors, Creditors, Sales or COGS will be left
-- standing with nothing behind it. Every row here is a decision you have to take
-- BEFORE running the forward step — this file will not guess at them.
SELECT '6. WARNING — surviving documents that touch the trading accounts' AS step;
SELECT t.id, t.type, t.doc_no, t.date, coa.code, coa.name AS account, je.debit, je.credit
FROM journal_entries je
JOIN transactions t        ON t.id = je.transaction_id
JOIN chart_of_accounts coa ON coa.id = je.account_id
WHERE t.tenant_id = @tenant
  AND t.id NOT IN (SELECT id FROM doomed_txn)
  AND coa.code IN ('1200','1400','2100','4000','5000')
ORDER BY t.date, t.id;

-- 7 ---------------------------------------------------------------------------
-- *** WARNING 2 ***  Invoice numbers about to be erased. If any of these were
-- printed, e-mailed or handed to a customer, deleting them leaves a hole in a
-- GST series that is required to be consecutive. That is your decision, not SQL's.
SELECT '7. WARNING — invoice and credit-note numbers that will be erased' AS step;
SELECT t.type, MIN(t.doc_no) AS first_doc_no, MAX(t.doc_no) AS last_doc_no, COUNT(*) AS documents
FROM transactions t JOIN doomed_txn d ON d.id = t.id
WHERE t.type IN ('sale','sales_return') AND t.status = 'posted' AND t.doc_no IS NOT NULL
GROUP BY t.type;

SELECT '7b. WARNING — invoices with a live public share link' AS step;
SELECT t.id, t.doc_no, ish.token, ish.revoked_at
FROM invoice_shares ish JOIN transactions t ON t.id = ish.transaction_id
WHERE ish.tenant_id = @tenant AND ish.revoked_at IS NULL;

-- 8 ---------------------------------------------------------------------------
-- *** WARNING 3 ***  Job cards survive. Their parts are un-billed by the clear,
-- so these jobs become billable again and will reappear as work to invoice.
SELECT '8. WARNING — job cards whose parts become billable again' AS step;
SELECT wj.id, wj.job_no, wj.status, COUNT(*) AS parts_unbilled
FROM workshop_job_parts wjp
JOIN transaction_lines tl ON tl.id = wjp.transaction_line_id
JOIN doomed_txn d         ON d.id = tl.transaction_id
JOIN workshop_jobs wj     ON wj.id = wjp.workshop_job_id
GROUP BY wj.id, wj.job_no, wj.status;

-- 9 ---------------------------------------------------------------------------
-- *** WARNING 4 ***  What will STILL hold an item down after the clear. The
-- forward step does not touch either of these, so anything listed here has to be
-- dealt with by hand before that item can be deleted.
SELECT '9. AFTER THE CLEAR, these still block an item delete' AS step;
SELECT i.id AS item_id, i.name AS item, 'workshop_job_parts' AS held_by, COUNT(*) AS refs
FROM workshop_job_parts wjp JOIN items i ON i.id = wjp.item_id
WHERE wjp.tenant_id = @tenant GROUP BY i.id, i.name ORDER BY i.id;

-- 9b --------------------------------------------------------------------------
-- The other thing that holds an item down: being a component in somebody's
-- recipe. SKIP THIS STATEMENT if `item_components` does not exist on your server
-- — it is created by `database/manual/sql/2026_09_29_001_item_components.sql`,
-- and if that step has not been run yet this will error with "table doesn't
-- exist" and nothing else here is affected. It is a separate statement rather
-- than part of block 9 for exactly that reason.
SELECT '9b. AFTER THE CLEAR, items held down by a recipe' AS step;
SELECT i.id AS item_id, i.name AS item, COUNT(*) AS used_in_recipes
FROM item_components ic
JOIN item_variants iv ON iv.id = ic.component_variant_id
JOIN items i          ON i.id = iv.item_id
WHERE ic.tenant_id = @tenant GROUP BY i.id, i.name ORDER BY i.id;

DROP TEMPORARY TABLE doomed_txn;
