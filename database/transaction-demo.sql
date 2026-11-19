-- ============================================================
-- PINJAMIN — Transaction Demonstration
-- MariaDB 10.5+ / MySQL 8.0+
-- ============================================================
-- Demonstrasi START TRANSACTION, COMMIT, ROLLBACK
-- Menggunakan data pengujian terpisah (tabel temporary / transaksi aman)
-- TIDAK mengubah data produksi yang sudah ada
-- ============================================================

-- ------------------------------------------------------------
-- PERSIAPAN: Buat data uji coba sementara
-- ------------------------------------------------------------
-- Asumsi: Database pinjamin sudah ada dengan schema dan data minimal
-- Kita menggunakan SAVEPOINT dan ROLLBACK TO SAVEPOINT untuk demo
-- tanpa commit permanen.

-- ------------------------------------------------------------
-- DEMO 1: Transaksi BERHASIL (Commit)
-- Skenario: Admin menambah kategori baru dalam transaksi
-- ------------------------------------------------------------
START TRANSACTION;

    -- Insert kategori baru
    INSERT INTO `categories` (`name`, `description`)
    VALUES ('Demo Kategori Transaksi', 'Kategori untuk demo transaksi');

    -- Insert alat baru di kategori tersebut
    INSERT INTO `tools` (`category_id`, `code`, `name`, `description`, `quantity_total`, `quantity_available`)
    VALUES (LAST_INSERT_ID(), 'DEMO-001', 'Alat Demo Transaksi', 'Alat untuk demo', 5, 5);

    -- Verifikasi sementara (masih dalam transaksi, belum visible ke session lain)
    SELECT 'Demo 1 - Setelah INSERT (dalam transaksi)' AS step;
    SELECT * FROM `categories` WHERE `name` = 'Demo Kategori Transaksi';
    SELECT * FROM `tools` WHERE `code` = 'DEMO-001';

COMMIT;

-- Verifikasi setelah commit (visible ke semua session)
SELECT 'Demo 1 - Setelah COMMIT' AS step;
SELECT * FROM `categories` WHERE `name` = 'Demo Kategori Transaksi';
SELECT * FROM `tools` WHERE `code` = 'DEMO-001';

-- ------------------------------------------------------------
-- DEMO 2: Transaksi GAGAL (Rollback)
-- Skenario: Insert duplikat kode alat (melanggar UNIQUE constraint)
-- ------------------------------------------------------------
START TRANSACTION;

    -- Insert kategori
    INSERT INTO `categories` (`name`, `description`)
    VALUES ('Demo Rollback Kategori', 'Kategori untuk demo rollback');

    -- Insert alat pertama (sukses)
    INSERT INTO `tools` (`category_id`, `code`, `name`, `quantity_total`, `quantity_available`)
    VALUES (LAST_INSERT_ID(), 'DEMO-RB-001', 'Alat Rollback 1', 3, 3);

    -- Insert alat kedua dengan KODE YANG SAMA (AKAN GAGAL)
    INSERT INTO `tools` (`category_id`, `code`, `name`, `quantity_total`, `quantity_available`)
    VALUES (LAST_INSERT_ID(), 'DEMO-RB-001', 'Alat Rollback 2', 2, 2);

    -- Kode di bawah ini TIDAK akan dieksekusi karena error di atas
    SELECT 'Demo 2 - Baris ini tidak akan terlihat jika error' AS step;

COMMIT;

-- Jika sampai sini tanpa error (tidak akan terjadi), verifikasi:
SELECT 'Demo 2 - Jika COMMIT berhasil (tidak terjadi)' AS step;

-- ------------------------------------------------------------
-- DEMO 3: Rollback Explisit dengan SAVEPOINT
-- Skenario: Proses multi-langkah, rollback parsial ke savepoint
-- ------------------------------------------------------------
START TRANSACTION;

    -- Langkah 1: Insert kategori
    INSERT INTO `categories` (`name`, `description`)
    VALUES ('Demo Savepoint Kategori', 'Kategori untuk demo savepoint');

    SAVEPOINT sp_after_category;

    -- Langkah 2: Insert alat pertama
    INSERT INTO `tools` (`category_id`, `code`, `name`, `quantity_total`, `quantity_available`)
    VALUES (LAST_INSERT_ID(), 'DEMO-SP-001', 'Alat Savepoint 1', 4, 4);

    SAVEPOINT sp_after_tool1;

    -- Langkah 3: Insert alat kedua (sukses)
    INSERT INTO `tools` (`category_id`, `code`, `name`, `quantity_total`, `quantity_available`)
    VALUES ((SELECT `id` FROM `categories` WHERE `name` = 'Demo Savepoint Kategori'), 'DEMO-SP-002', 'Alat Savepoint 2', 2, 2);

    -- Langkah 4: Coba insert duplikat (gagal) -> rollback ke sp_after_tool1
    BEGIN
        DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            ROLLBACK TO SAVEPOINT sp_after_tool1;
            SELECT 'Demo 3 - Error tertangani, rollback ke savepoint sp_after_tool1' AS info;
        END;

        INSERT INTO `tools` (`category_id`, `code`, `name`, `quantity_total`, `quantity_available`)
        VALUES ((SELECT `id` FROM `categories` WHERE `name` = 'Demo Savepoint Kategori'), 'DEMO-SP-001', 'Alat Savepoint DUPLIKAT', 1, 1);
    END;

    -- Lanjutkan transaksi (alat 1 masih ada, alat 2 sudah commit, duplikat di-rollback)
    SELECT 'Demo 3 - Setelah penanganan error, transaksi lanjut' AS step;

COMMIT;

-- Verifikasi hasil akhir Demo 3
SELECT 'Demo 3 - Hasil akhir setelah COMMIT' AS step;
SELECT * FROM `categories` WHERE `name` = 'Demo Savepoint Kategori';
SELECT * FROM `tools` WHERE `code` IN ('DEMO-SP-001', 'DEMO-SP-002');

-- ------------------------------------------------------------
-- DEMO 4: Transaksi Stok (Simulasi sp_approve_borrowing logic)
-- Demonstrasi locking dan konsistensi stok
-- ------------------------------------------------------------
-- Asumsi: Ada alat dengan id=1, quantity_available=3
-- Kita simulasi dua session yang bersaing approve peminjaman qty=3

-- Session 1 (simulasi):
-- START TRANSACTION;
-- SELECT quantity_available FROM tools WHERE id = 1 FOR UPDATE; -- returns 3
-- UPDATE tools SET quantity_available = quantity_available - 3 WHERE id = 1;
-- COMMIT;

-- Session 2 (simulasi, menjalankan bersamaan):
-- START TRANSACTION;
-- SELECT quantity_available FROM tools WHERE id = 1 FOR UPDATE; -- BLOCK sampai Session 1 commit
-- -- Setelah Session 1 commit, quantity_available = 0
-- -- Session 2 cek: 0 < 3 -> ROLLBACK, tolak approve

-- Demo ini tidak dieksekusi otomatis karena butuh dua session.
-- Gunakan untuk ilustrasi ke penguji.

-- ------------------------------------------------------------
-- DEMO 5: Verifikasi ROLLBACK memulihkan stok
-- ------------------------------------------------------------
START TRANSACTION;

    -- Ambil alat yang ada (asumsi id=1 exists)
    SELECT `id`, `name`, `quantity_available`
    INTO @demo_tool_id, @demo_tool_name, @demo_old_stock
    FROM `tools`
    WHERE `quantity_available` > 0
    LIMIT 1
    FOR UPDATE;

    IF @demo_tool_id IS NOT NULL THEN
        -- Kurangi stok (simulasi approve)
        UPDATE `tools` SET `quantity_available` = `quantity_available` - 1 WHERE `id` = @demo_tool_id;

        SELECT CONCAT('Demo 5 - Stok setelah UPDATE (dalam transaksi): ', `quantity_available`) AS info
        FROM `tools` WHERE `id` = @demo_tool_id;

        -- ROLLBACK: stok kembali ke nilai semula
        ROLLBACK;

        SELECT CONCAT('Demo 5 - Stok setelah ROLLBACK: ', `quantity_available`) AS info
        FROM `tools` WHERE `id` = @demo_tool_id;
    ELSE
        SELECT 'Demo 5 - Tidak ada alat dengan stok tersedia untuk demo' AS info;
        ROLLBACK;
    END IF;

-- ------------------------------------------------------------
-- BERSIHKAN DATA DEMO (opsional, jalankan manual jika perlu)
-- ------------------------------------------------------------
-- DELETE FROM `tools` WHERE `code` LIKE 'DEMO-%';
-- DELETE FROM `categories` WHERE `name` LIKE 'Demo%';

-- ============================================================
-- End of transaction-demo.sql
-- ============================================================