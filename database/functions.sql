-- ============================================================
-- PINJAMIN — SQL Functions
-- MariaDB 10.5+ / MySQL 8.0+
-- ============================================================
-- Tugas: fn_calculate_fine
-- Denda: Rp5.000 per hari keterlambatan per unit
-- hari_terlambat = MAX(0, DATEDIFF(return_date, due_date))
-- denda = hari_terlambat * quantity * 5000
-- ============================================================

DELIMITER $$

-- ------------------------------------------------------------
-- fn_calculate_fine
-- Menghitung denda keterlambatan pengembalian
-- Parameter:
--   p_due_date    DATE       -- Tanggal jatuh tempo (due_date dari borrowings)
--   p_return_date DATE       -- Tanggal pengembalian aktual (CURDATE() saat konfirmasi)
--   p_quantity    INT UNSIGNED -- Jumlah unit yang dikembalikan
-- Return:
--   DECIMAL(12,2) -- Nominal denda (0 jika tidak terlambat)
-- ------------------------------------------------------------
DROP FUNCTION IF EXISTS `fn_calculate_fine`$$

CREATE FUNCTION `fn_calculate_fine`(
    p_due_date    DATE,
    p_return_date DATE,
    p_quantity    INT UNSIGNED
)
RETURNS DECIMAL(12,2)
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_days_late   INT;
    DECLARE v_fine        DECIMAL(12,2);
    DECLARE v_rate        DECIMAL(10,2) DEFAULT 5000.00;

    -- Validasi parameter
    IF p_due_date IS NULL OR p_return_date IS NULL OR p_quantity = 0 THEN
        RETURN 0.00;
    END IF;

    -- Hitung hari terlambat (tidak negatif)
    SET v_days_late = DATEDIFF(p_return_date, p_due_date);
    IF v_days_late < 0 THEN
        SET v_days_late = 0;
    END IF;

    -- Hitung denda
    SET v_fine = v_days_late * p_quantity * v_rate;

    RETURN v_fine;
END$$

DELIMITER ;

-- ============================================================
-- Contoh Penggunaan:
-- SELECT fn_calculate_fine('2025-01-10', '2025-01-12', 2);  -- 2 hari * 2 unit * 5000 = 20000
-- SELECT fn_calculate_fine('2025-01-15', '2025-01-10', 1);  -- tidak terlambat = 0
-- SELECT fn_calculate_fine('2025-01-10', '2025-01-10', 3);  -- same day = 0
-- ============================================================
-- End of functions.sql
-- ============================================================