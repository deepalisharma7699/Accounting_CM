-- ---------------------------------------------------------------------------
-- 2026_09_30_001 · job_kinds — what comes in on a trolley.
--
-- Run by hand, in a window you chose, against a database you have just backed
-- up. `php artisan migrate` does not scan this directory (§4.6).
--
-- WHY
--   The bench's "Kind" list was `item_categories` filtered on `holds_stock`.
--   That filter separates "kept on a shelf" from "made when it is sold", which
--   says nothing about whether a customer wheels one through a door — so the
--   intake form offered Part, Bulk material, Bearing and Wire, and offered no
--   cooler, fan, mixer or submersible at all, because a repair shop does not
--   stock the things it repairs. The two lists were never one list.
--
-- WHAT IT DOES
--   Creates two new tables and adds one nullable column to `workshop_jobs`.
--   It writes no existing row and drops nothing. `workshop_jobs.category_id`
--   is deliberately LEFT IN PLACE: the code reads the new column when these
--   tables exist and falls back to the old one when they do not, so the
--   application is correct before this runs and after it. A later block drops
--   it, once kinds are in use.
--
-- WHAT IT LOCKS
--   Effectively nothing. The two CREATE TABLEs take no lock on any table the
--   workshop is billing against — only the metadata locks MySQL holds on
--   `tenants` and `job_kinds` for the duration of the statement, to validate
--   the foreign keys. The ALTER on `workshop_jobs` is an ADD COLUMN of a
--   nullable column with no default, which MySQL 8 performs INSTANT (no table
--   rebuild, no row touched); the foreign key that follows it is a separate
--   statement and takes a brief metadata lock on `workshop_jobs` and
--   `job_kinds`. Measured against the real table: the operator states
--   `workshop_jobs` is EMPTY on production as of 30 September 2026, so there is
--   no row count for it to be sensitive to. It is safe during trading hours.
--
-- VERIFY IT WORKED
--   The SELECT at the bottom of this file must return one row reading `ok`.
--
-- SEED
--   The tables come up empty. `php artisan catalogue:provision` (or the next
--   workshop provisioning) fills a workshop's opening kinds from
--   `App\Services\Workshop\JobKindDefaults`; nothing here inserts a row, so
--   this block is identical on every installation.
--
-- UNDO
--   database/manual/sql/2026_09_30_001_job_kinds.rollback.sql
-- ---------------------------------------------------------------------------

-- 1 ·  The list itself.
--
-- Deliberately flatter than `item_categories`. No `parent_id`: a category tree
-- exists so "Submersible Motor" can inherit a motor's question set and add to
-- it, which is worth its complexity on a catalogue of hundreds of products. A
-- bench takes in eight or ten kinds of thing and a cooler inherits nothing from
-- a fan. No `holds_stock`, no `uses_sac_code`, no default unit, HSN or GST
-- rate either — none of those describe a thing standing on a bench, and every
-- one of them was a column the Kind list carried and could not use.
CREATE TABLE IF NOT EXISTS `job_kinds` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,

  -- NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
  `tenant_id` bigint unsigned NOT NULL,

  `name` varchar(120) NOT NULL,
  `description` varchar(500) DEFAULT NULL,

  -- Seeded rows a workshop may rename, re-describe and switch off, but not
  -- delete — the same protection `item_categories.is_system` gives, and for the
  -- same reason: a job already booked in refers to what one meant.
  `is_system` tinyint(1) NOT NULL DEFAULT '0',

  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `display_order` smallint unsigned NOT NULL DEFAULT '0',

  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,

  PRIMARY KEY (`id`),

  -- One name, one kind. Two rows called "Cooler" would split the bench's own
  -- history in half and both halves would look plausible.
  UNIQUE KEY `job_kinds_tenant_id_name_unique` (`tenant_id`,`name`),

  -- The intake form's select: active only, in the workshop's own order.
  KEY `job_kinds_tenant_id_is_active_display_order_index` (`tenant_id`,`is_active`,`display_order`),

  CONSTRAINT `job_kinds_tenant_id_foreign`
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2 ·  What each kind is described by.
--
-- Column for column what `item_attributes` is, and that is on purpose: one
-- renderer (`components/attribute-fields.js`) and one resolver draw both, so a
-- second *shape* would be a second contract for them to go stale against. What
-- is separate here is the list, not the machinery.
CREATE TABLE IF NOT EXISTS `job_kind_attributes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,

  `tenant_id` bigint unsigned NOT NULL,

  -- cascadeOnDelete, as `item_attributes.category_id` is: an attribute
  -- definition has no meaning apart from the kind that asks it. "Head" is
  -- uninterpretable without knowing it belongs to Submersible pump. The
  -- protection sits on the kind, which cannot be deleted once a job refers to
  -- it.
  `job_kind_id` bigint unsigned NOT NULL,

  -- The JSON key inside `workshop_jobs.specs`. Write-once — renaming it would
  -- not rename it inside the bags already written, it would orphan them.
  `key` varchar(40) NOT NULL,

  `label` varchar(80) NOT NULL,

  -- text | number | decimal | dropdown | boolean | date — App\Enums\AttributeType.
  `data_type` varchar(20) NOT NULL DEFAULT 'text',

  -- The unit printed after the input — 'HP', 'RPM', 'mm'. A code into `units`.
  `unit_code` varchar(20) DEFAULT NULL,

  -- Stored, and deliberately not enforced on a job. `is_required` says a
  -- *product* cannot exist without a rating; a pump whose plate nobody could
  -- read is still on the bench. The intake form draws these without required
  -- marks and the server validates none of them — see JobService.
  `is_required` tinyint(1) NOT NULL DEFAULT '0',

  `default_value` varchar(120) DEFAULT NULL,
  `options` json DEFAULT NULL,
  `min_value` decimal(15,3) DEFAULT NULL,
  `max_value` decimal(15,3) DEFAULT NULL,
  `help_text` varchar(255) DEFAULT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',

  -- Switched off rather than deleted once values exist against it. An inactive
  -- attribute stops appearing on the form and keeps explaining the values
  -- already recorded under its key.
  `is_active` tinyint(1) NOT NULL DEFAULT '1',

  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,

  PRIMARY KEY (`id`),

  -- One definition per key per kind. Two rows called `hp` would be two rules
  -- over one JSON field and the resolver would have to guess.
  UNIQUE KEY `job_kind_attributes_job_kind_id_key_unique` (`job_kind_id`,`key`),

  -- The form build: every field of one kind, in order. The single read this
  -- table exists to serve. Named explicitly because the generated name would
  -- run past MySQL's 64-character identifier limit.
  KEY `job_kind_attributes_form_build_index` (`tenant_id`,`job_kind_id`,`is_active`,`display_order`),

  CONSTRAINT `job_kind_attributes_tenant_id_foreign`
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `job_kind_attributes_job_kind_id_foreign`
    FOREIGN KEY (`job_kind_id`) REFERENCES `job_kinds` (`id`) ON DELETE CASCADE,

  -- A range whose floor is above its ceiling accepts nothing, and the form
  -- would refuse every value typed into it without being able to say why.
  CONSTRAINT `job_kind_attributes_range_ordered`
    CHECK (`min_value` IS NULL OR `max_value` IS NULL OR `min_value` <= `max_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3 ·  Point a job at its kind.
--
-- Nullable, because nothing on the intake form is compulsory — a pump a driver
-- could not identify is already on the bench, and a form that refused it is a
-- form that got a job card written on paper instead.
--
-- `restrictOnDelete` rather than cascade: deleting "Cooler" must not take the
-- coolers with it. JobKindService refuses a kind that any job refers to and
-- says so; this is what catches anything that does not come through it.
ALTER TABLE `workshop_jobs`
  ADD COLUMN `job_kind_id` bigint unsigned DEFAULT NULL AFTER `category_id`;

ALTER TABLE `workshop_jobs`
  ADD CONSTRAINT `workshop_jobs_job_kind_id_foreign`
    FOREIGN KEY (`job_kind_id`) REFERENCES `job_kinds` (`id`);

-- ---------------------------------------------------------------------------
-- VERIFY — must return exactly one row, reading `ok`.
-- ---------------------------------------------------------------------------
SELECT CASE WHEN
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_kinds') = 1
AND (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_kind_attributes') = 1
AND (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workshop_jobs'
        AND COLUMN_NAME = 'job_kind_id') = 1
AND (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE()
        AND CONSTRAINT_NAME = 'workshop_jobs_job_kind_id_foreign') = 1
THEN 'ok' ELSE 'FAILED — re-read the block above before going further' END AS result;
