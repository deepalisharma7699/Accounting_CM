-- Undoes 2026_09_29_001_item_components.sql.
--
-- Safe while no recipe has been entered. Once recipes exist this DROPs them,
-- and there is nothing else that holds them — the movements a recipe produced
-- are on `stock_movements` and are unaffected, because a posted document never
-- consults a recipe again.

DROP TABLE IF EXISTS `item_components`;
