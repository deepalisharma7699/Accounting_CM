-- Undoes 2026_09_30_001_job_kinds.sql.
--
-- Safe at any time, and that is a property of how the forward block was
-- written rather than luck: it left `workshop_jobs.category_id` in place and
-- added `job_kind_id` beside it, so the application falls straight back to
-- reading the old column the moment these tables are gone.
--
-- WHAT IS LOST
--   Every kind a workshop defined and every attribute under it. A job's own
--   record is NOT lost: `kind_label` is a copied string on the job row and
--   `specs` is a JSON bag on the job row, so a card still says what came
--   through the door. What goes is the *schema* that resolved `{"hp": "7.5"}`
--   into "7.5 HP", so those bags print unresolved until kinds exist again —
--   which is exactly what `JobService::equipmentLabel()` already handles by
--   printing nothing it cannot resolve.
--
-- ORDER MATTERS
--   The foreign key on `workshop_jobs` must go before `job_kinds` can be
--   dropped, and `job_kind_attributes` cascades from `job_kinds` so it is
--   dropped explicitly first rather than relied upon.

ALTER TABLE `workshop_jobs` DROP FOREIGN KEY `workshop_jobs_job_kind_id_foreign`;
ALTER TABLE `workshop_jobs` DROP COLUMN `job_kind_id`;

DROP TABLE IF EXISTS `job_kind_attributes`;
DROP TABLE IF EXISTS `job_kinds`;
