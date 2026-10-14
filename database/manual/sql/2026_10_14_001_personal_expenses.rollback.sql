-- Undoes 2026_10_14_001_personal_expenses.sql.
--
-- WHAT IS LOST
--   Every personal diary entry and every personal category, in every workshop.
--   Nothing in the books is touched — no transaction, ledger entry or report
--   ever read these tables. Take a dump of the two tables first if the entries
--   are wanted.
--
-- Safe at any time: with the permission gone the Personal tab is not drawn, and
-- with the tables gone the API answers PERSONAL_EXPENSES_NOT_INSTALLED rather
-- than failing.

DELETE `rp` FROM `role_permission` `rp`
JOIN `permissions` `p` ON `p`.`id` = `rp`.`permission_id`
WHERE `p`.`resource` = 'PERSONAL_EXPENSES';

DELETE FROM `permissions` WHERE `resource` = 'PERSONAL_EXPENSES';

DROP TABLE IF EXISTS `personal_expenses`;
DROP TABLE IF EXISTS `personal_expense_categories`;
