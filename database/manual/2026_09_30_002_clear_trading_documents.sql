-- =============================================================================
-- 2026_09_30_002  ·  Clear one workshop's trading documents, keep its catalogue
--
-- WHAT IT DOES
--   Deletes every document that moved stock, and every settlement of one, for a
--   single workshop. There is no `qty_on_hand` column and no `avg_cost` column
--   anywhere in this schema — a position is `SUM(quantity)` over
--   `stock_movements` — so removing the movements *is* setting stock to nil.
--   Nothing has to be recalculated afterwards and no cache has to be cleared.
--
--   Deleted:  sale · purchase · sales_return · purchase_return · opening ·
--             stock_adjustment · receipt · payment
--             ...and their lines, ledger entries, payment splits, stock
--             movements, allocations, staff attributions, share links and
--             opening imports.
--
--   Kept:     items, variants, recipes, categories, attributes, brands, units,
--             parties, job cards, staff, payroll, staff advances, expenses,
--             manual journals, users, roles, the chart of accounts, and the
--             document numbering series.
--
-- WHY RECEIPTS AND PAYMENTS ARE IN THE DOOMED LIST
--   They are not stock documents, but they settle the ones that are. Leave a
--   receipt whose invoice has been deleted and Sundry Debtors (1400) carries a
--   credit for a customer with no invoice behind it — which is a worse tangle
--   than the one this is clearing up. They go together or not at all.
--
-- WHY EXPENSES AND MANUAL JOURNALS ARE NOT
--   Neither moves stock, and both are ordinary running records a workshop wants
--   to keep. But a manual journal *can* be posted straight to Inventory, Sales
--   or COGS — block 6 of the preview lists any that were. Deal with those by
--   hand before running this; this file will not guess at them.
--
-- LOCKS AND RUNTIME
--   Row locks only. No ALTER, no DDL on any real table, no index rebuild, no
--   table rewrite. Everything runs inside one transaction, so either all of it
--   lands or none of it does.
--
--   I could not measure the runtime against your server because I have no
--   access to it. The figure to judge by is block 4 of the preview: on InnoDB
--   this is a straightforward row delete, and tens of thousands of rows finish
--   in seconds. What matters more than the clock is that the workshop is NOT
--   billing while it runs — every row it touches is locked until it commits,
--   and a posting attempt during the window will block or deadlock.
--   Run it with the application stopped, or out of hours.
--
-- THERE IS NO UNDO
--   See the .rollback.sql beside this file. It restores from a dump, because
--   nothing else can. Take that dump first.
--
-- HOW TO RUN IT
--   1.  mysqldump -u <user> -p <database> > before-clear-$(date +%F).sql
--   2.  mysql -u <user> -p <database> < database/manual/2026_09_30_002_clear_trading_documents.preview.sql
--       Read all nine blocks. Stop if anything surprises you.
--   3.  Edit @tenant below.
--   4.  mysql -u <user> -p <database> < database/manual/2026_09_30_002_clear_trading_documents.sql
--
--   Run the whole file in ONE session — it builds a TEMPORARY table, which does
--   not survive a reconnect. Do not paste it block by block into separate
--   clients.
--
--   The last blocks are verification SELECTs. Read them. If block V2 does not
--   print zero, this did not do what it says.
-- =============================================================================

SET @tenant := 1;   -- <<< CHANGE ME. Must match the id you previewed.

-- Fail-safe: if @tenant is NULL or wrong, every WHERE below matches nothing and
-- this file deletes nothing at all. If the next SELECT prints no row, STOP —
-- everything after it will be a no-op and the counts at the end will say so.
SELECT id AS tenant_id, name AS workshop FROM tenants WHERE id = @tenant;


START TRANSACTION;

-- 1 ---------------------------------------------------------------------------
-- The doomed set, decided ONCE and held in a temporary table, so no statement
-- below can disagree with another about which documents are going.
CREATE TEMPORARY TABLE doomed_txn (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB;

INSERT INTO doomed_txn (id)
SELECT id FROM transactions
WHERE tenant_id = @tenant
  AND type IN ('sale','purchase','sales_return','purchase_return',
               'opening','stock_adjustment','receipt','payment');


-- 2 ---------------------------------------------------------------------------
-- Break the within-table links first. `transactions.reverses_id`,
-- `transactions.against_transaction_id` and `transaction_lines.against_line_id`
-- all point inside their own table under RESTRICT, so a single multi-row DELETE
-- can fail on whatever row order InnoDB happens to pick. Nulling first makes the
-- order irrelevant.

UPDATE transactions SET reverses_id = NULL
WHERE reverses_id IN (SELECT id FROM doomed_txn);

UPDATE transactions SET against_transaction_id = NULL
WHERE against_transaction_id IN (SELECT id FROM doomed_txn);

-- A credit note's line points at the invoice line it credits.
UPDATE transaction_lines tl
JOIN  transaction_lines target ON target.id = tl.against_line_id
JOIN  doomed_txn d             ON d.id = target.transaction_id
SET   tl.against_line_id = NULL;

-- A job's part points at the invoice line it was billed on. Nulling it is what
-- un-bills the part, so the job card survives and its parts are billable again.
-- `workshop_job_parts.transaction_line_id` is RESTRICT, so without this the
-- delete in block 3 fails rather than cascading.
UPDATE workshop_job_parts wjp
JOIN  transaction_lines tl ON tl.id = wjp.transaction_line_id
JOIN  doomed_txn d         ON d.id = tl.transaction_id
SET   wjp.transaction_line_id = NULL;


-- 3 ---------------------------------------------------------------------------
-- Everything hanging off a doomed document, deepest first. Foreign keys stay
-- ENABLED throughout, deliberately: if something in this schema references a row
-- this file did not expect, the right outcome is a loud failure inside the
-- transaction, not a silently orphaned row found months later.

-- Who did the work on a sale. The employees themselves are untouched.
DELETE ts FROM transaction_staff ts JOIN doomed_txn d ON d.id = ts.transaction_id;

-- The stock ledger. THIS is the statement that sets stock to nil.
DELETE sm FROM stock_movements sm JOIN doomed_txn d ON d.id = sm.transaction_id;

-- Public invoice links stop working, which is correct: the invoice is gone.
DELETE ish FROM invoice_shares ish JOIN doomed_txn d ON d.id = ish.transaction_id;

-- Two statements, not one with an OR. MySQL cannot reference the same TEMPORARY
-- table twice in a single query (error 1137, "Can't reopen table").
DELETE ta FROM transaction_allocations ta JOIN doomed_txn d ON d.id = ta.settlement_transaction_id;
DELETE ta FROM transaction_allocations ta JOIN doomed_txn d ON d.id = ta.bill_transaction_id;

-- Scoped to the doomed set, never truncated: half a payroll voucher is worse
-- than none, and payroll and staff advances are surviving here.
DELETE tp FROM transaction_payments tp JOIN doomed_txn d ON d.id = tp.transaction_id;
DELETE je FROM journal_entries je      JOIN doomed_txn d ON d.id = je.transaction_id;

-- Explicit, although the FK would cascade — so the order above is the real
-- dependency order rather than something InnoDB happens to get right.
DELETE tl FROM transaction_lines tl JOIN doomed_txn d ON d.id = tl.transaction_id;

DELETE t FROM transactions t JOIN doomed_txn d ON d.id = t.id;


-- 4 ---------------------------------------------------------------------------
-- The go-live declarations themselves. The transactions they created are gone.
DELETE FROM opening_imports WHERE tenant_id = @tenant;

-- Audit rows whose subject no longer exists. Items, variants, parties, staff and
-- users are all KEPT here, so their trails are kept too — only the sale
-- attributions lost their document.
DELETE FROM audit_logs WHERE tenant_id = @tenant AND resource = 'sale_attribution';


DROP TEMPORARY TABLE doomed_txn;

COMMIT;


-- =============================================================================
-- VERIFICATION. Read every one of these. A step that prints the wrong number
-- did not do what it says.
-- =============================================================================

SELECT 'V1. Documents left, by type — expect NO sale/purchase/receipt/payment/opening/stock_adjustment/*_return' AS check_;
SELECT type, status, COUNT(*) AS documents FROM transactions
WHERE tenant_id = @tenant GROUP BY type, status ORDER BY type;

SELECT 'V2. Stock movements left — MUST be 0' AS check_;
SELECT COUNT(*) AS stock_movements_remaining FROM stock_movements WHERE tenant_id = @tenant;

SELECT 'V3. Every variant is out of stock — MUST print 0 rows' AS check_;
SELECT sm.variant_id, SUM(sm.quantity) AS qty FROM stock_movements sm
WHERE sm.tenant_id = @tenant GROUP BY sm.variant_id HAVING SUM(sm.quantity) <> 0;

SELECT 'V4. Inventory (1200), Debtors (1400), Creditors (2100), Sales (4000), COGS (5000) — what is left on each' AS check_;
SELECT coa.code, coa.name, ROUND(SUM(je.debit) - SUM(je.credit), 2) AS balance
FROM journal_entries je JOIN chart_of_accounts coa ON coa.id = je.account_id
WHERE je.tenant_id = @tenant AND coa.code IN ('1200','1400','2100','4000','5000')
GROUP BY coa.code, coa.name ORDER BY coa.code;

SELECT 'V5. The books still balance — MUST be 0.00' AS check_;
SELECT ROUND(SUM(debit) - SUM(credit), 2) AS debits_minus_credits
FROM journal_entries WHERE tenant_id = @tenant;

SELECT 'V6. Catalogue survived — these should all be non-zero if they were before' AS check_;
SELECT 'items' AS kept, COUNT(*) AS rows_ FROM items WHERE tenant_id = @tenant
UNION ALL SELECT 'item_variants', COUNT(*) FROM item_variants WHERE tenant_id = @tenant
UNION ALL SELECT 'parties',       COUNT(*) FROM parties       WHERE tenant_id = @tenant
UNION ALL SELECT 'workshop_jobs', COUNT(*) FROM workshop_jobs WHERE tenant_id = @tenant
UNION ALL SELECT 'employees',     COUNT(*) FROM employees     WHERE tenant_id = @tenant;

SELECT 'V7. Orphan check — all three MUST be 0' AS check_;
SELECT 'lines with no transaction'     AS orphan, COUNT(*) AS n FROM transaction_lines tl LEFT JOIN transactions t ON t.id = tl.transaction_id WHERE tl.tenant_id = @tenant AND t.id IS NULL
UNION ALL SELECT 'entries with no transaction', COUNT(*) FROM journal_entries je LEFT JOIN transactions t ON t.id = je.transaction_id WHERE je.tenant_id = @tenant AND t.id IS NULL
UNION ALL SELECT 'movements with no transaction', COUNT(*) FROM stock_movements sm LEFT JOIN transactions t ON t.id = sm.transaction_id WHERE sm.tenant_id = @tenant AND t.id IS NULL;


-- =============================================================================
-- OPTIONAL · Restart document numbering at 1001.
--
-- NOT part of the step above, and off by default. Numbering is only safe to
-- restart if NOTHING from the erased series was ever printed, e-mailed or given
-- to a customer — a GST invoice series has to be consecutive, and re-issuing
-- INV/26-27/1001 against a number somebody already holds is a worse problem than
-- a gap. Preview block 7 lists exactly which numbers are at stake.
--
-- If you are sure, uncomment and run:
--
--   DELETE FROM document_sequences
--   WHERE tenant_id = @tenant AND series IN ('INV','PUR','RCT','PAY','CN','DN','ADJ','OB');
--
-- 'JOB', 'EXP', 'JV', 'ADV' and 'SAL' are deliberately absent: job cards,
-- expenses, journals, staff advances and payroll runs all survive this clear and
-- still carry the numbers those series issued.
-- =============================================================================
