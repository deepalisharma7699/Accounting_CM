-- Undo 2026_10_14_002. Invoices posted with the box ticked print itemised again.

ALTER TABLE transactions DROP COLUMN combine_labour, ALGORITHM=INSTANT;
