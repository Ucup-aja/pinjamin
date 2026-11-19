-- ============================================================
-- PINJAMIN — Triggers
-- MariaDB 10.5+ / MySQL 8.0+
-- ============================================================
-- Tugas: Trigger validasi returns.requested_by = borrowings.user_id
-- Trigger ini memastikan pengajuan pengembalian diajukan oleh peminjam yang benar.
-- Admin tetap bisa membuat data pengembalian atas nama peminjam melalui INSERT
-- yang menyertakan requested_by = borrowings.user_id (bukan ID admin).
-- ============================================================

DELIMITER $$

-- ------------------------------------------------------------
-- trg_returns_validate_requested_by
-- BEFORE INSERT pada tabel returns
-- Memastikan requested_by cocok dengan user_id di borrowings
-- ------------------------------------------------------------
DROP TRIGGER IF EXISTS `trg_returns_validate_requested_by`$$

CREATE TRIGGER `trg_returns_validate_requested_by`
BEFORE INSERT ON `returns`
FOR EACH ROW
BEGIN
    DECLARE v_borrowing_user_id INT UNSIGNED;

    -- Ambil user_id dari borrowing induk
    SELECT `user_id`
    INTO v_borrowing_user_id
    FROM `borrowings`
    WHERE `id` = NEW.`borrowing_id`;

    -- Jika borrowing tidak ditemukan, biarkan FK constraint yang handle
    IF v_borrowing_user_id IS NOT NULL THEN
        -- Validasi: requested_by harus sama dengan peminjam asli
        IF NEW.`requested_by` <> v_borrowing_user_id THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Pengajuan pengembalian hanya dapat diajukan oleh peminjam yang bersangkutan (requested_by harus sama dengan borrowings.user_id)';
        END IF;
    END IF;
END$$

-- ------------------------------------------------------------
-- Catatan untuk Admin (CRUD administratif):
-- Admin yang ingin membuat data pengembalian atas nama peminjam
-- harus menyetel `requested_by` = `borrowings.user_id` (ID peminjam),
-- bukan ID admin sendiri. Kolom `processed_by` diisi saat konfirmasi
-- oleh petugas (bukan saat INSERT).
-- Trigger ini HANYA memvalidasi requested_by, tidak menyentuh stok.
-- ============================================================

DELIMITER ;

-- ============================================================
-- End of triggers.sql
-- ============================================================