-- ---------------------------------------------------------------------------
-- 2026_09_29_001 · item_components — what a made thing consumes.
--
-- Run by hand, in a window you chose, against a database you have just backed
-- up. `php artisan migrate` does not scan this directory (§4.6).
--
-- WHAT IT DOES
--   Creates one new table. It adds no column to an existing table, writes no
--   existing row, and nothing in the application reads it until a recipe has
--   been entered — so the code is correct both before this runs and after.
--
-- WHAT IT LOCKS
--   Nothing. `CREATE TABLE` takes no lock on any table the workshop is billing
--   against; the only locks are the metadata locks MySQL takes on `tenants` and
--   `item_variants` to validate the two foreign keys, held for the duration of
--   the statement. Measured on the development database (79 variants, 1 tenant)
--   it completes in under 20 ms, and it is not sensitive to row counts because
--   the new table starts empty.
--
-- VERIFY IT WORKED
--   The SELECT at the bottom of this file must return one row reading `ok`.
--
-- UNDO
--   database/manual/sql/2026_09_29_001_item_components.rollback.sql
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `item_components` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,

  -- NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
  `tenant_id` bigint unsigned NOT NULL,

  -- The variant being made. ON DELETE CASCADE, exactly as a variant cascades
  -- from its item: a recipe has no meaning apart from the thing it is a recipe
  -- for, and "2.5 kg of copper" is not interpretable on its own.
  `parent_variant_id` bigint unsigned NOT NULL,

  -- The material consumed. RESTRICT, exactly as a bill line's item is: a
  -- material whose variant vanished loses the name that explains what went
  -- into the motor.
  `component_variant_id` bigint unsigned NOT NULL,

  -- How much of the material one of the parent consumes. The same
  -- decimal(15,3) every quantity in this schema uses, in the component's own
  -- base unit — there is no conversion factor here and there must never be
  -- one, for the reason the catalogue already records against it.
  `quantity` decimal(15,3) NOT NULL,

  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,

  PRIMARY KEY (`id`),

  -- One row per material per recipe. Two rows naming the same copper would be
  -- two issues of it on one line, which is a quantity somebody meant to type
  -- once — the same rule `items.name` follows, and for the same reason.
  UNIQUE KEY `item_components_pair_unique` (`parent_variant_id`,`component_variant_id`),

  -- "What does this consume" — the read the bill form and the posting engine
  -- both make, once per composed line.
  KEY `item_components_tenant_id_parent_variant_id_index` (`tenant_id`,`parent_variant_id`),

  -- "What consumes this" — the read that has to happen before a material may
  -- be archived, and the one a stock screen will want later.
  KEY `item_components_tenant_id_component_variant_id_index` (`tenant_id`,`component_variant_id`),

  KEY `item_components_component_variant_id_foreign` (`component_variant_id`),

  CONSTRAINT `item_components_tenant_id_foreign`
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `item_components_parent_variant_id_foreign`
    FOREIGN KEY (`parent_variant_id`) REFERENCES `item_variants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `item_components_component_variant_id_foreign`
    FOREIGN KEY (`component_variant_id`) REFERENCES `item_variants` (`id`) ON DELETE RESTRICT,

  -- A recipe line of nought consumes nothing and is a row somebody forgot to
  -- fill in; a negative one would *create* stock on a sale. Restated here as
  -- well as in the service, exactly as item_variants restates its own.
  CONSTRAINT `item_components_quantity_positive` CHECK ((`quantity` > 0)),

  -- A thing cannot be made out of itself. The service refuses the whole family
  -- of cycles, which it has to walk the chain to do anyway; this catches the
  -- one-step case that no walk is needed for.
  CONSTRAINT `item_components_not_itself` CHECK ((`parent_variant_id` <> `component_variant_id`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Must return exactly one row, reading `ok`.
SELECT 'ok' AS `item_components`
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'item_components';
