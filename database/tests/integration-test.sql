-- ============================================================
-- PINJAMIN — Integration Test Suite
-- Database: pinjamin_test (TERPISAH dari produksi)
-- MariaDB 13.0.2+
-- ============================================================
-- ATURAN KEAMANAN:
-- 1. HANYA berjalan pada database pinjamin_test
-- 2. TIDAK mengubah database pinjamin (produksi)
-- 3. Menggunakan DROP/CREATE DATABASE di awal
-- 4. Data dummy dibuat di dalam transaksi yang di-commit
-- ============================================================

-- ------------------------------------------------------------
-- 0. SETUP: Buat database test bersih
-- ------------------------------------------------------------
DROP DATABASE IF EXISTS `pinjamin_test`;
CREATE DATABASE `pinjamin_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `pinjamin_test`;

-- ------------------------------------------------------------
-- 1. IMPORT SCHEMA & LOGIC (urutan wajib)
-- ------------------------------------------------------------
SOURCE ../schema.sql;
SOURCE ../functions.sql;
SOURCE ../triggers.sql;
SOURCE ../procedures.sql;

-- ------------------------------------------------------------
-- 2. DATA DUMMY: Admin, Petugas, Peminjam, Kategori, Alat, Peminjaman
-- ------------------------------------------------------------
START TRANSACTION;

-- Users
INSERT INTO `users` (`name`, `username`, `password`, `role`, `is_active`) VALUES
('Admin Utama', 'admin1', '$2y$10$dummyhash', 'admin', TRUE),
('Petugas Satu', 'petugas1', '$2y$10$dummyhash', 'petugas', TRUE),
('Petugas Dua', 'petugas2', '$2y$10$dummyhash', 'petugas', TRUE),
('Peminjam A', 'peminjam_a', '$2y$10$dummyhash', 'peminjam', TRUE),
('Peminjam B', 'peminjam_b', '$2y$10$dummyhash', 'peminjam', TRUE),
('Peminjam Nonaktif', 'peminjam_inactive', '$2y$10$dummyhash', 'peminjam', FALSE);

-- Ambil ID untuk referensi
SET @id_admin = (SELECT id FROM users WHERE username = 'admin1');
SET @id_petugas1 = (SELECT id FROM users WHERE username = 'petugas1');
SET @id_petugas2 = (SELECT id FROM users WHERE username = 'petugas2');
SET @id_peminjam_a = (SELECT id FROM users WHERE username = 'peminjam_a');
SET @id_peminjam_b = (SELECT id FROM users WHERE username = 'peminjam_b');
SET @id_peminjam_inactive = (SELECT id FROM users WHERE username = 'peminjam_inactive');

-- Categories
INSERT INTO `categories` (`name`, `description`) VALUES
('Elektronik', 'Alat elektronik dan aksesoris'),
('Mekanik', 'Alat mekanik dan bengkel'),
('Komputer', 'Perangkat komputer dan jaringan');

SET @cat_elektronik = (SELECT id FROM categories WHERE name = 'Elektronik');
SET @cat_mekanik = (SELECT id FROM categories WHERE name = 'Mekanik');
SET @cat_komputer = (SELECT id FROM categories WHERE name = 'Komputer');

-- Tools (stok bervariasi untuk test)
INSERT INTO `tools` (`category_id`, `code`, `name`, `description`, `quantity_total`, `quantity_available`) VALUES
(@cat_elektronik, 'ELEC-001', 'Multimeter Digital', 'Multimeter 3.5 digit', 5, 5),
(@cat_elektronik, 'ELEC-002', 'Osiloskop Portable', 'Osiloskop 2 channel', 2, 2),
(@cat_mekanik, 'MECH-001', 'Set Kunci Ring', 'Set kunci ring 8-19mm', 3, 3),
(@cat_mekanik, 'MECH-002', 'Torque Wrench', 'Torque wrench 10-50Nm', 1, 1),  -- Stok 1 untuk test insufficient
(@cat_komputer, 'COMP-001', 'Laptop Testing', 'Laptop untuk testing jaringan', 4, 4),
(@cat_komputer, 'COMP-002', 'Kabel UTP Cat6', 'Kabel jaringan 305m', 10, 10);

SET @tool_multimeter = (SELECT id FROM tools WHERE code = 'ELEC-001');
SET @tool_osiloskop = (SELECT id FROM tools WHERE code = 'ELEC-002');
SET @tool_kunci_ring = (SELECT id FROM tools WHERE code = 'MECH-001');
SET @tool_torque = (SELECT id FROM tools WHERE code = 'MECH-002');
SET @tool_laptop = (SELECT id FROM tools WHERE code = 'COMP-001');
SET @tool_kabel = (SELECT id FROM tools WHERE code = 'COMP-002');

-- Borrowings (peminjaman pending untuk test approval)
-- borrowing_1: peminjam_a pinjam multimeter 2 unit, due_date esok
INSERT INTO `borrowings` (`user_id`, `tool_id`, `quantity`, `borrow_date`, `due_date`, `status`) VALUES
(@id_peminjam_a, @tool_multimeter, 2, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'pending');

SET @borrowing_1 = LAST_INSERT_ID();

-- borrowing_2: peminjam_a pinjam osiloskop 1 unit, due_date 3 hari lagi
INSERT INTO `borrowings` (`user_id`, `tool_id`, `quantity`, `borrow_date`, `due_date`, `status`) VALUES
(@id_peminjam_a, @tool_osiloskop, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'pending');

SET @borrowing_2 = LAST_INSERT_ID();

-- borrowing_3: peminjam_b pinjam torque wrench 1 unit (stok hanya 1)
INSERT INTO `borrowings` (`user_id`, `tool_id`, `quantity`, `borrow_date`, `due_date`, `status`) VALUES
(@id_peminjam_b, @tool_torque, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'pending');

SET @borrowing_3 = LAST_INSERT_ID();

-- borrowing_4: peminjam_a pinjam laptop 3 unit (untuk test partial return)
INSERT INTO `borrowings` (`user_id`, `tool_id`, `quantity`, `borrow_date`, `due_date`, `status`) VALUES
(@id_peminjam_a, @tool_laptop, 3, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'pending');

SET @borrowing_4 = LAST_INSERT_ID();

-- borrowing_5: peminjam_b pinjam kunci ring 2 unit, SUDAH APPROVED (untuk test return)
INSERT INTO `borrowings` (`user_id`, `tool_id`, `quantity`, `borrow_date`, `due_date`, `status`, `approved_by`, `approved_at`) VALUES
(@id_peminjam_b, @tool_kunci_ring, 2, DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'approved', @id_petugas1, NOW());

SET @borrowing_5 = LAST_INSERT_ID();
-- Kurangi stok manual untuk borrowing_5 (seolah sudah approved)
UPDATE `tools` SET `quantity_available` = `quantity_available` - 2 WHERE `id` = @tool_kunci_ring;

-- borrowing_6: peminjam_a pinjam kabel 5 unit, sudah approved, due_date kemarin (TERLAMBAT)
INSERT INTO `borrowings` (`user_id`, `tool_id`, `quantity`, `borrow_date`, `due_date`, `status`, `approved_by`, `approved_at`) VALUES
(@id_peminjam_a, @tool_kabel, 5, DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'approved', @id_petugas1, NOW());

SET @borrowing_6 = LAST_INSERT_ID();
UPDATE `tools` SET `quantity_available` = `quantity_available` - 5 WHERE `id` = @tool_kabel;

COMMIT;

-- Verifikasi data awal
SELECT '=== DATA AWAL ===' AS info;
SELECT 'Users:' AS t; SELECT id, username, role, is_active FROM users;
SELECT 'Categories:' AS t; SELECT * FROM categories;
SELECT 'Tools:' AS t; SELECT id, code, name, quantity_total, quantity_available FROM tools;
SELECT 'Borrowings:' AS t; SELECT id, user_id, tool_id, quantity, borrow_date, due_date, status FROM borrowings;

-- ============================================================
-- 3. TEST CASES
-- ============================================================

-- ------------------------------------------------------------
-- TEST 1: Persetujuan peminjaman BERHASIL
-- borrowing_1: peminjam_a pinjam multimeter 2 unit (stok 5)
-- Expected: success=1, stok multimeter jadi 3, status=approved
-- ------------------------------------------------------------
SELECT '=== TEST 1: Approval Berhasil ===' AS test;
CALL sp_approve_borrowing(@borrowing_1, @id_petugas1);
SET @t1_stock = (SELECT quantity_available FROM tools WHERE id = @tool_multimeter);
SET @t1_status = (SELECT status FROM borrowings WHERE id = @borrowing_1);
SET @test1_result = CASE WHEN @t1_stock = 3 AND @t1_status = 'approved' THEN 'PASS' ELSE 'FAIL' END;
SELECT @test1_result AS test1_result, @t1_stock AS stok_multimeter, @t1_status AS status_borrowing;

-- ------------------------------------------------------------
-- TEST 2: Persetujuan GANDA DITOLAK
-- borrowing_1 sudah approved, coba approve lagi
-- Expected: success=0, status tetap approved
-- ------------------------------------------------------------
SELECT '=== TEST 2: Approval Ganda Ditolak ===' AS test;
CALL sp_approve_borrowing(@borrowing_1, @id_petugas1);
SET @t2_status = (SELECT status FROM borrowings WHERE id = @borrowing_1);
SET @test2_result = CASE WHEN @t2_status = 'approved' THEN 'PASS' ELSE 'FAIL' END;
SELECT @test2_result AS test2_result, @t2_status AS status_borrowing;

-- ------------------------------------------------------------
-- TEST 3: Stok TIDAK MENCUKUPI
-- borrowing_2: approve dulu (osiloskop 1 unit, stok 2 -> 1)
-- borrowing_7 (baru): minta 2 unit torque (stok hanya 1) -> harus gagal
-- Expected: borrowing_7 gagal, stok torque tetap 1
-- ------------------------------------------------------------
SELECT '=== TEST 3: Stok Tidak Mencukupi ===' AS test;
-- Approve borrowing_2 dulu (osiloskop)
CALL sp_approve_borrowing(@borrowing_2, @id_petugas1);
SET @t3_osiloskop_stock = (SELECT quantity_available FROM tools WHERE id = @tool_osiloskop);

-- Buat borrowing baru yang melebihi stok torque (stok torque = 1)
INSERT INTO `borrowings` (`user_id`, `tool_id`, `quantity`, `borrow_date`, `due_date`, `status`)
VALUES (@id_peminjam_a, @tool_torque, 2, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'pending');
SET @borrowing_7 = LAST_INSERT_ID();

CALL sp_approve_borrowing(@borrowing_7, @id_petugas1);
SET @t3_torque_stock = (SELECT quantity_available FROM tools WHERE id = @tool_torque);
SET @t3_b7_status = (SELECT status FROM borrowings WHERE id = @borrowing_7);
SET @test3_result = CASE WHEN @t3_torque_stock = 1 AND @t3_b7_status = 'pending' THEN 'PASS' ELSE 'FAIL' END;
SELECT @test3_result AS test3_result, @t3_osiloskop_stock AS stok_osiloskop_setelah_approve_b2, @t3_torque_stock AS stok_torque_harus_tetap_1, @t3_b7_status AS status_borrowing_7_harus_pending;

-- ------------------------------------------------------------
-- TEST 4: Pengembalian PARSIAL
-- borrowing_5: peminjam_b pinjam kunci ring 2 unit (sudah approved, stok sudah -2)
-- Return 1 unit dulu
-- Expected: success=1, stok +1 (dari 1 jadi 2), status=partially_returned
-- ------------------------------------------------------------
SELECT '=== TEST 4: Pengembalian Parsial ===' AS test;
-- Buat return record (pending)
INSERT INTO `returns` (`borrowing_id`, `requested_by`, `quantity`) VALUES
(@borrowing_5, @id_peminjam_b, 1);
SET @return_1 = LAST_INSERT_ID();

SET @t4_stock_before = (SELECT quantity_available FROM tools WHERE id = @tool_kunci_ring);

CALL sp_confirm_return(@return_1, @id_petugas1);
SET @t4_stock_after = (SELECT quantity_available FROM tools WHERE id = @tool_kunci_ring);
SET @t4_return_status = (SELECT status FROM returns WHERE id = @return_1);
SET @t4_borrow_status = (SELECT status FROM borrowings WHERE id = @borrowing_5);
SET @test4_result = CASE
    WHEN @t4_stock_after = @t4_stock_before + 1
     AND @t4_return_status = 'confirmed'
     AND @t4_borrow_status = 'partially_returned' THEN 'PASS'
    ELSE 'FAIL' END;
SELECT @test4_result AS test4_result, @t4_stock_before AS stok_sebelum, @t4_stock_after AS stok_sesudah_harus_plus_1, @t4_return_status AS status_return_harus_confirmed, @t4_borrow_status AS status_borrowing_harus_partially;

-- ------------------------------------------------------------
-- TEST 5: Pengembalian PENUH (sisa pinjaman)
-- borrowing_5 masih sisa 1 unit, return 1 unit lagi
-- Expected: success=1, stok +1 (dari 2 jadi 3 = total), status=returned
-- ------------------------------------------------------------
SELECT '=== TEST 5: Pengembalian Penuh (Sisa) ===' AS test;
INSERT INTO `returns` (`borrowing_id`, `requested_by`, `quantity`) VALUES
(@borrowing_5, @id_peminjam_b, 1);
SET @return_2 = LAST_INSERT_ID();

SET @t5_stock_before = (SELECT quantity_available FROM tools WHERE id = @tool_kunci_ring);

CALL sp_confirm_return(@return_2, @id_petugas1);
SET @t5_stock_after = (SELECT quantity_available FROM tools WHERE id = @tool_kunci_ring);
SET @t5_return_status = (SELECT status FROM returns WHERE id = @return_2);
SET @t5_borrow_status = (SELECT status FROM borrowings WHERE id = @borrowing_5);
SET @test5_result = CASE
    WHEN @t5_stock_after = @t5_stock_before + 1
     AND @t5_return_status = 'confirmed'
     AND @t5_borrow_status = 'returned' THEN 'PASS'
    ELSE 'FAIL' END;
SELECT @test5_result AS test5_result, @t5_stock_before AS stok_sebelum, @t5_stock_after AS stok_sesudah_harus_plus_1_sama_dengan_total, @t5_return_status AS status_return_harus_confirmed, @t5_borrow_status AS status_borrowing_harus_returned;

-- ------------------------------------------------------------
-- TEST 6: Pengembalian MELEBIHI Jumlah Pinjaman
-- borrowing_4: peminjam_a pinjam laptop 3 unit (pending) -> approve
-- Coba return 4 unit (melebihi 3)
-- Expected: success=0, status return=pending, stok tidak berubah
-- ------------------------------------------------------------
SELECT '=== TEST 6: Return Melebihi Pinjaman ===' AS test;
CALL sp_approve_borrowing(@borrowing_4, @id_petugas1);
SET @t6_stock_after_approve = (SELECT quantity_available FROM tools WHERE id = @tool_laptop);

INSERT INTO `returns` (`borrowing_id`, `requested_by`, `quantity`) VALUES
(@borrowing_4, @id_peminjam_a, 4);  -- Melebihi pinjaman (3)
SET @return_3 = LAST_INSERT_ID();

CALL sp_confirm_return(@return_3, @id_petugas1);
SET @t6_stock_after_confirm = (SELECT quantity_available FROM tools WHERE id = @tool_laptop);
SET @t6_return_status = (SELECT status FROM returns WHERE id = @return_3);
SET @test6_result = CASE
    WHEN @t6_stock_after_confirm = @t6_stock_after_approve
     AND @t6_return_status = 'pending' THEN 'PASS'
    ELSE 'FAIL' END;
SELECT @test6_result AS test6_result, @t6_stock_after_approve AS stok_setelah_approve, @t6_stock_after_confirm AS stok_harus_tidak_berubah, @t6_return_status AS status_return_harus_pending;

-- ------------------------------------------------------------
-- TEST 7: Trigger menolak requested_by TIDAK SESUAI
-- borrowing_4 milik peminjam_a, coba insert return dengan requested_by = peminjam_b
-- Expected: ERROR 1644 (SQLSTATE 45000), tidak ada row tersimpan
-- Gunakan stored procedure temporary untuk menangkap SIGNAL dari trigger
-- ------------------------------------------------------------
SELECT '=== TEST 7: Trigger requested_by Mismatch ===' AS test;

-- Reset flag
SET @test7_error_caught = FALSE;
SET @test7_rows_before = (SELECT COUNT(*) FROM `returns` WHERE `borrowing_id` = @borrowing_4);

-- Buat procedure temporary untuk test trigger
DROP PROCEDURE IF EXISTS `test7_trigger_check`;
DELIMITER $$
CREATE PROCEDURE `test7_trigger_check`(
    IN p_borrowing_id INT UNSIGNED,
    IN p_requested_by INT UNSIGNED
)
BEGIN
    DECLARE CONTINUE HANDLER FOR SQLSTATE '45000'
    BEGIN
        SET @test7_error_caught = TRUE;
    END;

    INSERT INTO `returns` (`borrowing_id`, `requested_by`, `quantity`)
    VALUES (p_borrowing_id, p_requested_by, 1);
END$$
DELIMITER ;

-- Jalankan procedure test
CALL `test7_trigger_check`(@borrowing_4, @id_peminjam_b);

-- Hapus procedure temporary
DROP PROCEDURE IF EXISTS `test7_trigger_check`;

-- Verifikasi hasil
SET @test7_rows_after = (SELECT COUNT(*) FROM `returns` WHERE `borrowing_id` = @borrowing_4);
SET @test7_no_insert = (@test7_rows_after = @test7_rows_before);
SET @test7_result = CASE
    WHEN @test7_error_caught = TRUE AND @test7_no_insert = TRUE THEN 'PASS'
    WHEN @test7_error_caught = FALSE THEN 'FAIL: Trigger tidak menolak (tidak ada SIGNAL 45000)'
    WHEN @test7_no_insert = FALSE THEN 'FAIL: Data ilegal tersimpan meskipun trigger error'
    ELSE 'FAIL: Unexpected' END;
SELECT @test7_result AS test7_result, @test7_error_caught AS error_caught, @test7_no_insert AS no_illegal_insert;

-- ------------------------------------------------------------
-- TEST 8: Rollback Transaksi Gagal
-- Coba approve borrowing dengan petugas tidak valid (role admin)
-- Expected: success=0, stok tidak berubah, status=pending
-- ------------------------------------------------------------
SELECT '=== TEST 8: Rollback Petugas Invalid ===' AS test;
-- Buat borrowing baru
INSERT INTO `borrowings` (`user_id`, `tool_id`, `quantity`, `borrow_date`, `due_date`, `status`)
VALUES (@id_peminjam_a, @tool_multimeter, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'pending');
SET @borrowing_8 = LAST_INSERT_ID();

SET @t8_stock_before = (SELECT quantity_available FROM tools WHERE id = @tool_multimeter);

-- Coba approve dengan admin (bukan petugas)
CALL sp_approve_borrowing(@borrowing_8, @id_admin);

SET @t8_stock_after = (SELECT quantity_available FROM tools WHERE id = @tool_multimeter);
SET @t8_borrow_status = (SELECT status FROM borrowings WHERE id = @borrowing_8);
SET @test8_result = CASE
    WHEN @t8_stock_after = @t8_stock_before
     AND @t8_borrow_status = 'pending' THEN 'PASS'
    ELSE 'FAIL' END;
SELECT @test8_result AS test8_result, @t8_stock_before AS stok_sebelum, @t8_stock_after AS stok_harus_tidak_berubah, @t8_borrow_status AS status_borrowing_harus_pending;

-- ------------------------------------------------------------
-- TEST 9: Function Denda
-- borrowing_6: due_date 2 hari lalu, return 3 unit
-- Expected: 2 hari * 3 unit * 5000 = 30000
-- ------------------------------------------------------------
SELECT '=== TEST 9: Function Denda ===' AS test;
SET @t9_d1 = fn_calculate_fine(DATE_SUB(CURDATE(), INTERVAL 2 DAY), CURDATE(), 3);
SET @t9_d2 = fn_calculate_fine(DATE_ADD(CURDATE(), INTERVAL 1 DAY), CURDATE(), 2);
SET @t9_d3 = fn_calculate_fine(CURDATE(), CURDATE(), 5);

-- Test via procedure (borrowing_6 sudah approved, due_date 2 hari lalu)
INSERT INTO `returns` (`borrowing_id`, `requested_by`, `quantity`) VALUES
(@borrowing_6, @id_peminjam_a, 3);
SET @return_4 = LAST_INSERT_ID();

CALL sp_confirm_return(@return_4, @id_petugas1);
SET @t9_fine_proc = (SELECT fine_amount FROM returns WHERE id = @return_4);
SET @test9_result = CASE
    WHEN @t9_d1 = 30000.00 AND @t9_d2 = 0.00 AND @t9_d3 = 0.00 AND @t9_fine_proc = 30000.00 THEN 'PASS'
    ELSE 'FAIL' END;
SELECT @test9_result AS test9_result, @t9_d1 AS denda_2_hari_3_unit_harus_30000, @t9_d2 AS denda_belum_jatuh_tempo_harus_0, @t9_d3 AS denda_hari_sama_harus_0, @t9_fine_proc AS denda_via_procedure_harus_30000;

-- ------------------------------------------------------------
-- TEST 10: Approval Petugas Nonaktif
-- Expected: success=0, status=pending
-- ------------------------------------------------------------
SELECT '=== TEST 10: Petugas Nonaktif ===' AS test;
-- Buat petugas nonaktif
INSERT INTO `users` (`name`, `username`, `password`, `role`, `is_active`) VALUES
('Petugas Nonaktif', 'petugas_inactive', '$2y$10$dummyhash', 'petugas', FALSE);
SET @id_petugas_inactive = LAST_INSERT_ID();

INSERT INTO `borrowings` (`user_id`, `tool_id`, `quantity`, `borrow_date`, `due_date`, `status`)
VALUES (@id_peminjam_b, @tool_multimeter, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'pending');
SET @borrowing_9 = LAST_INSERT_ID();

CALL sp_approve_borrowing(@borrowing_9, @id_petugas_inactive);
SET @t10_status = (SELECT status FROM borrowings WHERE id = @borrowing_9);
SET @test10_result = CASE WHEN @t10_status = 'pending' THEN 'PASS' ELSE 'FAIL' END;
SELECT @test10_result AS test10_result, @t10_status AS status_borrowing_harus_pending;

-- ------------------------------------------------------------
-- TEST 11: Confirm Return oleh Non-Petugas (Admin)
-- Expected: success=0, status return=pending
-- ------------------------------------------------------------
SELECT '=== TEST 11: Confirm oleh Admin (Bukan Petugas) ===' AS test;
INSERT INTO `returns` (`borrowing_id`, `requested_by`, `quantity`) VALUES
(@borrowing_4, @id_peminjam_a, 1);
SET @return_5 = LAST_INSERT_ID();

CALL sp_confirm_return(@return_5, @id_admin);
SET @t11_return_status = (SELECT status FROM returns WHERE id = @return_5);
SET @test11_result = CASE WHEN @t11_return_status = 'pending' THEN 'PASS' ELSE 'FAIL' END;
SELECT @test11_result AS test11_result, @t11_return_status AS status_return_harus_pending;

-- ------------------------------------------------------------
-- TEST 12: Validasi Status Borrowing untuk Return
-- Coba confirm return untuk borrowing yang status rejected
-- Expected: success=0, status return=pending
-- ------------------------------------------------------------
SELECT '=== TEST 12: Return untuk Borrowing Rejected ===' AS test;
INSERT INTO `borrowings` (`user_id`, `tool_id`, `quantity`, `borrow_date`, `due_date`, `status`)
VALUES (@id_peminjam_a, @tool_multimeter, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'rejected');
SET @borrowing_10 = LAST_INSERT_ID();

INSERT INTO `returns` (`borrowing_id`, `requested_by`, `quantity`) VALUES
(@borrowing_10, @id_peminjam_a, 1);
SET @return_6 = LAST_INSERT_ID();

CALL sp_confirm_return(@return_6, @id_petugas1);
SET @t12_return_status = (SELECT status FROM returns WHERE id = @return_6);
SET @test12_result = CASE WHEN @t12_return_status = 'pending' THEN 'PASS' ELSE 'FAIL' END;
SELECT @test12_result AS test12_result, @t12_return_status AS status_return_harus_pending;

-- ============================================================
-- 4. RINGKASAN HASIL OTOMATIS
-- ============================================================
SELECT '=== RINGKASAN TEST ===' AS summary;

-- Tampilkan hasil per test
SELECT '=== HASIL PER TEST ===' AS header;

SELECT 'TEST 1' AS test, @test1_result AS result UNION ALL
SELECT 'TEST 2', @test2_result UNION ALL
SELECT 'TEST 3', @test3_result UNION ALL
SELECT 'TEST 4', @test4_result UNION ALL
SELECT 'TEST 5', @test5_result UNION ALL
SELECT 'TEST 6', @test6_result UNION ALL
SELECT 'TEST 7', @test7_result UNION ALL
SELECT 'TEST 8', @test8_result UNION ALL
SELECT 'TEST 9', @test9_result UNION ALL
SELECT 'TEST 10', @test10_result UNION ALL
SELECT 'TEST 11', @test11_result UNION ALL
SELECT 'TEST 12', @test12_result;

-- Summary counts
SELECT
    SUM(CASE WHEN result = 'PASS' THEN 1 ELSE 0 END) AS total_pass,
    SUM(CASE WHEN result = 'FAIL' THEN 1 ELSE 0 END) AS total_fail,
    SUM(CASE WHEN result LIKE 'FAIL:%' THEN 1 ELSE 0 END) AS total_fail_detail
FROM (
    SELECT @test1_result AS result UNION ALL
    SELECT @test2_result UNION ALL
    SELECT @test3_result UNION ALL
    SELECT @test4_result UNION ALL
    SELECT @test5_result UNION ALL
    SELECT @test6_result UNION ALL
    SELECT @test7_result UNION ALL
    SELECT @test8_result UNION ALL
    SELECT @test9_result UNION ALL
    SELECT @test10_result UNION ALL
    SELECT @test11_result UNION ALL
    SELECT @test12_result
) AS results;

-- Data akhir untuk inspeksi
SELECT '=== DATA AKHIR ===' AS final;
SELECT 'Tools stok akhir:' AS t; SELECT code, name, quantity_total, quantity_available FROM tools;
SELECT 'Borrowings status akhir:' AS t; SELECT id, user_id, tool_id, quantity, status FROM borrowings;
SELECT 'Returns status akhir:' AS t; SELECT id, borrowing_id, requested_by, quantity, status, fine_amount FROM returns;

-- ============================================================
-- End of integration-test.sql
-- ============================================================
