-- ============================================================
-- PINJAMIN — Stored Procedures
-- MariaDB 10.5+ / MySQL 8.0+
-- ============================================================
-- Tugas: sp_approve_borrowing, sp_confirm_return
-- Menggunakan transaksi, row locking (FOR UPDATE), exception handler
-- ============================================================

DELIMITER $$

-- ------------------------------------------------------------
-- sp_approve_borrowing
-- Persetujuan peminjaman oleh petugas
-- Parameter:
--   p_borrowing_id  INT UNSIGNED  -- ID peminjaman
--   p_petugas_id    INT UNSIGNED  -- ID petugas yang menyetujui
-- Output:
--   SELECT 1 AS success, 'message' AS info  -- berhasil
--   SELECT 0 AS success, 'message' AS info  -- gagal
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS `sp_approve_borrowing`$$

CREATE PROCEDURE `sp_approve_borrowing`(
    IN p_borrowing_id INT UNSIGNED,
    IN p_petugas_id   INT UNSIGNED
)
BEGIN
    DECLARE v_status          ENUM('pending','approved','partially_returned','returned','rejected');
    DECLARE v_tool_id         INT UNSIGNED;
    DECLARE v_quantity        INT UNSIGNED;
    DECLARE v_available       INT UNSIGNED;
    DECLARE v_petugas_role    ENUM('admin','petugas','peminjam');
    DECLARE v_petugas_active  BOOLEAN;
    DECLARE v_error_msg       VARCHAR(255);
    DECLARE v_has_error       BOOLEAN DEFAULT FALSE;

    -- Exception handler: rollback on any error
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SELECT 0 AS success, CONCAT('Database error: ', IFNULL(@error_msg, 'Unknown error')) AS info;
    END;

    START TRANSACTION;

    -- 1. Lock borrowing row, ambil data yang diperlukan
    SELECT `status`, `tool_id`, `quantity`
    INTO v_status, v_tool_id, v_quantity
    FROM `borrowings`
    WHERE `id` = p_borrowing_id
    FOR UPDATE;

    IF v_status IS NULL THEN
        SET v_error_msg = 'Peminjaman tidak ditemukan';
        SET v_has_error = TRUE;
    ELSEIF v_status <> 'pending' THEN
        SET v_error_msg = CONCAT('Peminjaman tidak bisa disetujui (status: ', v_status, ')');
        SET v_has_error = TRUE;
    END IF;

    -- 2. Validasi petugas
    SELECT `role`, `is_active`
    INTO v_petugas_role, v_petugas_active
    FROM `users`
    WHERE `id` = p_petugas_id
    FOR UPDATE;

    IF v_petugas_role IS NULL THEN
        SET v_error_msg = 'Petugas tidak ditemukan';
        SET v_has_error = TRUE;
    ELSEIF v_petugas_role <> 'petugas' THEN
        SET v_error_msg = 'Hanya petugas yang dapat menyetujui peminjaman';
        SET v_has_error = TRUE;
    ELSEIF v_petugas_active = FALSE THEN
        SET v_error_msg = 'Akun petugas tidak aktif';
        SET v_has_error = TRUE;
    END IF;

    -- 3. Cek stok tersedia (lock tools row)
    IF NOT v_has_error THEN
        SELECT `quantity_available`
        INTO v_available
        FROM `tools`
        WHERE `id` = v_tool_id
        FOR UPDATE;

        IF v_available < v_quantity THEN
            SET v_error_msg = CONCAT('Stok tidak mencukupi. Tersedia: ', v_available, ', Diminta: ', v_quantity);
            SET v_has_error = TRUE;
        END IF;
    END IF;

    -- 4. Jika error, rollback
    IF v_has_error THEN
        ROLLBACK;
        SELECT 0 AS success, v_error_msg AS info;
    ELSE
        -- 5. Kurangi stok atomik
        UPDATE `tools`
        SET `quantity_available` = `quantity_available` - v_quantity
        WHERE `id` = v_tool_id;

        -- 6. Update borrowing status
        UPDATE `borrowings`
        SET `status`      = 'approved',
            `approved_by` = p_petugas_id,
            `approved_at` = NOW()
        WHERE `id` = p_borrowing_id;

        COMMIT;
        SELECT 1 AS success, 'Peminjaman disetujui, stok dikurangi' AS info;
    END IF;

END$$

-- ------------------------------------------------------------
-- sp_confirm_return
-- Konfirmasi pengembalian oleh petugas
-- Parameter:
--   p_return_id   INT UNSIGNED  -- ID pengembalian
--   p_petugas_id  INT UNSIGNED  -- ID petugas yang memproses
-- Output:
--   SELECT 1 AS success, 'message' AS info  -- berhasil
--   SELECT 0 AS success, 'message' AS info  -- gagal
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS `sp_confirm_return`$$

CREATE PROCEDURE `sp_confirm_return`(
    IN p_return_id  INT UNSIGNED,
    IN p_petugas_id INT UNSIGNED
)
BEGIN
    DECLARE v_return_status   ENUM('pending','confirmed','rejected');
    DECLARE v_borrowing_id    INT UNSIGNED;
    DECLARE v_return_qty      INT UNSIGNED;
    DECLARE v_borrow_status   ENUM('pending','approved','partially_returned','returned','rejected');
    DECLARE v_borrow_qty      INT UNSIGNED;
    DECLARE v_tool_id         INT UNSIGNED;
    DECLARE v_total_returned  INT UNSIGNED DEFAULT 0;
    DECLARE v_due_date        DATE;
    DECLARE v_fine_amount     DECIMAL(12,2);
    DECLARE v_petugas_role    ENUM('admin','petugas','peminjam');
    DECLARE v_petugas_active  BOOLEAN;
    DECLARE v_error_msg       VARCHAR(255);
    DECLARE v_has_error       BOOLEAN DEFAULT FALSE;

    -- Exception handler
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SELECT 0 AS success, CONCAT('Database error: ', IFNULL(@error_msg, 'Unknown error')) AS info;
    END;

    START TRANSACTION;

    -- 1. Lock return row, ambil data
    SELECT `status`, `borrowing_id`, `quantity`
    INTO v_return_status, v_borrowing_id, v_return_qty
    FROM `returns`
    WHERE `id` = p_return_id
    FOR UPDATE;

    IF v_return_status IS NULL THEN
        SET v_error_msg = 'Pengembalian tidak ditemukan';
        SET v_has_error = TRUE;
    ELSEIF v_return_status <> 'pending' THEN
        SET v_error_msg = CONCAT('Pengembalian tidak bisa dikonfirmasi (status: ', v_return_status, ')');
        SET v_has_error = TRUE;
    END IF;

    -- 2. Validasi petugas
    IF NOT v_has_error THEN
        SELECT `role`, `is_active`
        INTO v_petugas_role, v_petugas_active
        FROM `users`
        WHERE `id` = p_petugas_id
        FOR UPDATE;

        IF v_petugas_role IS NULL THEN
            SET v_error_msg = 'Petugas tidak ditemukan';
            SET v_has_error = TRUE;
        ELSEIF v_petugas_role <> 'petugas' THEN
            SET v_error_msg = 'Hanya petugas yang dapat memproses pengembalian';
            SET v_has_error = TRUE;
        ELSEIF v_petugas_active = FALSE THEN
            SET v_error_msg = 'Akun petugas tidak aktif';
            SET v_has_error = TRUE;
        END IF;
    END IF;

    -- 3. Lock borrowing row, hitung total sudah dikembalikan (confirmed only)
    IF NOT v_has_error THEN
        SELECT b.`status`, b.`quantity`, b.`tool_id`, b.`due_date`,
               COALESCE(SUM(CASE WHEN r.`status` = 'confirmed' THEN r.`quantity` ELSE 0 END), 0)
        INTO v_borrow_status, v_borrow_qty, v_tool_id, v_due_date, v_total_returned
        FROM `borrowings` b
        LEFT JOIN `returns` r ON r.`borrowing_id` = b.`id`
        WHERE b.`id` = v_borrowing_id
        FOR UPDATE;

        IF v_borrow_status IS NULL THEN
            SET v_error_msg = 'Peminjaman induk tidak ditemukan';
            SET v_has_error = TRUE;
        ELSEIF v_borrow_status NOT IN ('approved','partially_returned') THEN
            SET v_error_msg = CONCAT('Peminjaman tidak dalam status yang valid untuk pengembalian (status: ', v_borrow_status, ')');
            SET v_has_error = TRUE;
        ELSE
            -- Cek apakah return qty melebihi sisa
            IF (v_total_returned + v_return_qty) > v_borrow_qty THEN
                SET v_error_msg = CONCAT('Jumlah pengembalian melebihi sisa. Sudah kembali: ', v_total_returned, ', Sisa: ', (v_borrow_qty - v_total_returned), ', Diajuakan: ', v_return_qty);
                SET v_has_error = TRUE;
            END IF;
        END IF;
    END IF;

    -- 4. Hitung denda via function
    IF NOT v_has_error THEN
        SET v_fine_amount = `fn_calculate_fine`(v_due_date, CURDATE(), v_return_qty);
    END IF;

    -- 5. Jika error, rollback
    IF v_has_error THEN
        ROLLBACK;
        SELECT 0 AS success, v_error_msg AS info;
    ELSE
        -- 6. Update return record
        UPDATE `returns`
        SET `status`        = 'confirmed',
            `return_date`   = CURDATE(),
            `fine_amount`   = v_fine_amount,
            `processed_by`  = p_petugas_id
        WHERE `id` = p_return_id;

        -- 7. Tambah stok atomik
        UPDATE `tools`
        SET `quantity_available` = `quantity_available` + v_return_qty
        WHERE `id` = v_tool_id;

        -- 8. Update borrowing status
        IF (v_total_returned + v_return_qty) >= v_borrow_qty THEN
            UPDATE `borrowings`
            SET `status` = 'returned'
            WHERE `id` = v_borrowing_id;
        ELSE
            UPDATE `borrowings`
            SET `status` = 'partially_returned'
            WHERE `id` = v_borrowing_id;
        END IF;

        COMMIT;
        SELECT 1 AS success, CONCAT('Pengembalian dikonfirmasi. Denda: ', v_fine_amount) AS info;
    END IF;

END$$

DELIMITER ;

-- ============================================================
-- End of procedures.sql
-- ============================================================