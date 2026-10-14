-- Undo 2026_10_13_001. Drops every vendor invoice number recorded since.
ALTER TABLE transactions DROP COLUMN vendor_invoice_no, ALGORITHM=INSTANT;
