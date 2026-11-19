-- ============================================================
-- PINJAMIN — Development Reset Script
-- HANYA untuk pengujian ulang di lingkungan development.
-- JANGAN dijalankan di production.
-- ============================================================

-- Matikan foreign key checks sementara untuk urutan drop bebas
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `returns`;
DROP TABLE IF EXISTS `borrowings`;
DROP TABLE IF EXISTS `tools`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

-- Jalankan schema produksi
SOURCE schema.sql;

-- ============================================================
-- End of reset-dev.sql
-- ============================================================