-- ---------------------------------------------------------------------------
-- 2026_10_14_001 · personal_expenses — the owner's own diary.
--
-- Run by hand, in a window you chose, against a database you have just backed
-- up. `php artisan migrate` does not scan this directory (§4.6).
--
-- WHY
--   An owner wants to keep track of what they spend out of their own pocket —
--   groceries, school fees, fuel — inside the product they already open every
--   day. That money never passes through the workshop, so it is NOT a business
--   transaction: it posts nothing, it is in no ledger, no P&L, no day book and
--   no Insights figure. These two tables are a diary that happens to live
--   beside the books, and nothing in the books reads them.
--
-- WHAT IT DOES
--   1. Creates `personal_expense_categories` and `personal_expenses`.
--   2. Gives every existing workshop the twelve opening categories (a new
--      workshop gets them at provisioning time).
--   3. Adds the PERSONAL_EXPENSES permission (read, write, update, delete) and
--      grants it to every OWNER role — and to no other role.
--   It alters no existing table and writes no existing row.
--
-- WHAT IT LOCKS
--   Effectively nothing. Two CREATE TABLEs; the foreign keys take brief
--   metadata locks on `tenants`, `users` and the new category table. The
--   INSERTs touch `permissions` (4 rows), `role_permission` (4 per workshop)
--   and the new category table (12 per workshop) — milliseconds. Safe during
--   trading hours.
--
-- AFTERWARDS
--   Role grants are cached for up to an hour (config/rbac.php). Run
--   `php artisan cache:clear` once, or the Personal tab appears for owners
--   when their role's cache entry expires.
--
-- VERIFY IT WORKED
--   The SELECT at the bottom of this file must return one row reading `ok`.
--
-- UNDO
--   database/manual/sql/2026_10_14_001_personal_expenses.rollback.sql
-- ---------------------------------------------------------------------------

-- 1 ·  What the money went on. Edited by the owner from the Personal tab.
CREATE TABLE IF NOT EXISTS `personal_expense_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `name` varchar(80) NOT NULL,

  -- Archived rather than deleted once an entry is filed under it.
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `display_order` smallint unsigned NOT NULL DEFAULT '0',

  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_expense_categories_tenant_id_name_unique` (`tenant_id`,`name`),
  KEY `personal_expense_categories_tenant_active_order_index` (`tenant_id`,`is_active`,`display_order`),

  CONSTRAINT `personal_expense_categories_tenant_id_foreign`
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2 ·  One entry in the diary. Shared by everybody holding the grant in that
--      workshop — one diary per workshop, not one per login.
CREATE TABLE IF NOT EXISTS `personal_expenses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `date` date NOT NULL,

  -- RESTRICT: a category with entries is archived, never deleted.
  `category_id` bigint unsigned NOT NULL,

  `amount` decimal(15,2) NOT NULL,

  -- cash | upi | card | bank — App\Enums\PersonalPaymentMethod. For the
  -- owner's own reference only; nothing settles against it.
  `paid_by` varchar(20) NOT NULL DEFAULT 'cash',

  `notes` varchar(500) DEFAULT NULL,

  -- Who wrote it, for "who put this here" between two owners. SET NULL so a
  -- removed login does not take the diary with it.
  `created_by` bigint unsigned DEFAULT NULL,

  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,

  PRIMARY KEY (`id`),
  KEY `personal_expenses_tenant_id_date_index` (`tenant_id`,`date`),
  KEY `personal_expenses_tenant_category_date_index` (`tenant_id`,`category_id`,`date`),

  CONSTRAINT `personal_expenses_tenant_id_foreign`
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `personal_expenses_category_id_foreign`
    FOREIGN KEY (`category_id`) REFERENCES `personal_expense_categories` (`id`),
  CONSTRAINT `personal_expenses_created_by_foreign`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `personal_expenses_amount_positive` CHECK (`amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3 ·  The opening categories, for every workshop that already exists. The
--      same twelve as App\Services\Personal\PersonalCategoryDefaults. INSERT
--      IGNORE against the unique name, so a second run adds nothing.
INSERT IGNORE INTO `personal_expense_categories`
    (`tenant_id`, `name`, `is_active`, `display_order`, `created_at`, `updated_at`)
SELECT `t`.`id`, `d`.`name`, 1, `d`.`display_order`, NOW(), NOW()
FROM `tenants` `t`
CROSS JOIN (
              SELECT 'Household & groceries' AS `name`, 1 AS `display_order`
    UNION ALL SELECT 'Home rent', 2
    UNION ALL SELECT 'Home utilities', 3
    UNION ALL SELECT 'Fuel & travel', 4
    UNION ALL SELECT 'Children''s education', 5
    UNION ALL SELECT 'Medical', 6
    UNION ALL SELECT 'Mobile & internet', 7
    UNION ALL SELECT 'Clothing & shopping', 8
    UNION ALL SELECT 'Functions & gifts', 9
    UNION ALL SELECT 'Dining & entertainment', 10
    UNION ALL SELECT 'Loan EMI & insurance', 11
    UNION ALL SELECT 'Other', 12
) `d`;

-- 4 ·  The permission. INSERT IGNORE against (action, resource).
INSERT IGNORE INTO `permissions` (`action`, `resource`, `description`, `created_at`, `updated_at`) VALUES
    ('READ',   'PERSONAL_EXPENSES', 'READ access to PERSONAL_EXPENSES.',   NOW(), NOW()),
    ('WRITE',  'PERSONAL_EXPENSES', 'WRITE access to PERSONAL_EXPENSES.',  NOW(), NOW()),
    ('UPDATE', 'PERSONAL_EXPENSES', 'UPDATE access to PERSONAL_EXPENSES.', NOW(), NOW()),
    ('DELETE', 'PERSONAL_EXPENSES', 'DELETE access to PERSONAL_EXPENSES.', NOW(), NOW());

-- 5 ·  Granted to every OWNER role and nothing else — each workshop's own,
--      AND the old shared OWNER row (`tenant_id` NULL). Owners who have not yet
--      been moved onto their workshop's own roles (database/manual
--      2026_09_19_100003_move_workshop_users_onto_their_own_workshops_roles)
--      still sign in on that shared row, and would otherwise never see the tab.
--      The diary itself is tenant-scoped, so the grant on a shared row reads
--      nothing across workshops; a platform user with no workshop is refused.
INSERT IGNORE INTO `role_permission` (`role_id`, `permission_id`, `created_at`, `updated_at`)
SELECT `r`.`id`, `p`.`id`, NOW(), NOW()
FROM `roles` `r`
JOIN `permissions` `p` ON `p`.`resource` = 'PERSONAL_EXPENSES'
WHERE `r`.`slug` = 'OWNER';

-- ---------------------------------------------------------------------------
-- VERIFY — must return exactly one row, reading `ok`.
-- ---------------------------------------------------------------------------
SELECT CASE WHEN
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'personal_expense_categories') = 1
AND (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'personal_expenses') = 1
AND (SELECT COUNT(*) FROM `permissions` WHERE `resource` = 'PERSONAL_EXPENSES') = 4
AND (SELECT COUNT(*) FROM `role_permission` `rp`
       JOIN `permissions` `p` ON `p`.`id` = `rp`.`permission_id`
       JOIN `roles` `r` ON `r`.`id` = `rp`.`role_id`
      WHERE `p`.`resource` = 'PERSONAL_EXPENSES' AND `r`.`slug` <> 'OWNER') = 0
THEN 'ok' ELSE 'FAILED — re-read the block above before going further' END AS result;
