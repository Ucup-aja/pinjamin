# Database Logic — Pinjamin

> File: `database/procedures.sql`, `database/functions.sql`, `database/triggers.sql`, `database/transaction-demo.sql`  
> Versi: 1.0 — Checkpoint B

---

## 1. Stored Procedure: `sp_approve_borrowing`

**File:** `database/procedures.sql`

### Tujuan
Memproses persetujuan peminjaman oleh petugas dengan transaksi atomik, row locking, dan validasi stok.

### Parameter Input
| Parameter | Tipe | Keterangan |
|-----------|------|------------|
| `p_borrowing_id` | INT UNSIGNED | ID peminjaman yang akan disetujui |
| `p_petugas_id` | INT UNSIGNED | ID petugas yang menyetujui |

### Output
```sql
SELECT 1 AS success, 'Peminjaman disetujui, stok dikurangi' AS info;  -- Berhasil
SELECT 0 AS success, 'Pesan error' AS info;                            -- Gagal
```

### Proses (Langkah-langkah)
1. **START TRANSACTION**
2. **Lock baris peminjaman** (`SELECT ... FOR UPDATE`) → ambil `status`, `tool_id`, `quantity`
3. **Validasi status** = `pending` (tolak jika sudah approved/rejected/dll)
4. **Lock baris petugas** → cek `role = 'petugas'` dan `is_active = TRUE`
5. **Lock baris alat** (`tools`) → cek `quantity_available >= quantity`
6. Jika validasi gagal → **ROLLBACK**, return error
7. **UPDATE tools** → `quantity_available = quantity_available - quantity` (atomik)
8. **UPDATE borrowings** → `status='approved'`, `approved_by=p_petugas_id`, `approved_at=NOW()`
9. **COMMIT**, return success

### Mencegah Persetujuan Ganda
- Row lock pada `borrowings` (`FOR UPDATE`) memastikan hanya satu session yang bisa memproses peminjaman yang sama sekaligus
- Cek `status = 'pending'` di dalam transaksi → setelah di-approved, status berubah, percobaan kedua akan ditolak

### Exception Handler
```sql
DECLARE EXIT HANDLER FOR SQLEXCEPTION
BEGIN
    ROLLBACK;
    SELECT 0 AS success, CONCAT('Database error: ', ...) AS info;
END;
```
Menjamin **ROLLBACK otomatis** pada error SQL apapun (deadlock, constraint violation, dll).

---

## 2. Stored Procedure: `sp_confirm_return`

**File:** `database/procedures.sql`

### Tujuan
Memproses konfirmasi pengembalian oleh petugas: hitung denda, tambah stok, update status peminjaman.

### Parameter Input
| Parameter | Tipe | Keterangan |
|-----------|------|------------|
| `p_return_id` | INT UNSIGNED | ID pengembalian yang akan dikonfirmasi |
| `p_petugas_id` | INT UNSIGNED | ID petugas yang memproses |

### Output
```sql
SELECT 1 AS success, 'Pengembalian dikonfirmasi. Denda: 15000.00' AS info;
SELECT 0 AS success, 'Pesan error' AS info;
```

### Proses
1. **START TRANSACTION**
2. **Lock baris pengembalian** → ambil `status`, `borrowing_id`, `quantity`
3. Validasi `status = 'pending'`
4. **Lock baris petugas** → cek `role='petugas'` dan `is_active=TRUE`
5. **Lock baris peminjaman induk** + hitung `total_returned` (SUM quantity WHERE status='confirmed')
6. Validasi peminjaman status ∈ {`approved`, `partially_returned`}
7. Validasi `total_returned + return_qty <= borrow_qty` (mencegah melebihi)
8. Hitung denda: `fn_calculate_fine(due_date, CURDATE(), return_qty)`
9. Jika error → **ROLLBACK**
10. **UPDATE returns** → `status='confirmed'`, `return_date=CURDATE()`, `fine_amount`, `processed_by`
11. **UPDATE tools** → `quantity_available = quantity_available + return_qty` (atomik)
12. **UPDATE borrowings** → `status='returned'` jika total kembali >= qty, else `partially_returned`
13. **COMMIT**

### Mencegah Konfirmasi Ganda
- Row lock pada `returns` (`FOR UPDATE`) + cek `status='pending'`
- Setelah dikonfirmasi, status jadi `confirmed`, percobaan kedua ditolak

### Pengembalian Parsial Didukung
- `total_returned` dihitung dari semua `returns` dengan `status='confirmed'`
- Status peminjaman otomatis `partially_returned` jika masih ada sisa, `returned` jika habis

---

## 3. SQL Function: `fn_calculate_fine`

**File:** `database/functions.sql`

### Signature
```sql
fn_calculate_fine(p_due_date DATE, p_return_date DATE, p_quantity INT UNSIGNED)
RETURNS DECIMAL(12,2)
```

### Rumus
```
hari_terlambat = MAX(0, DATEDIFF(return_date, due_date))
denda          = hari_terlambat × quantity × 5000
```

### Contoh
```sql
SELECT fn_calculate_fine('2025-01-10', '2025-01-12', 2);  -- 20000.00 (2 hari × 2 unit × 5000)
SELECT fn_calculate_fine('2025-01-15', '2025-01-10', 1);  -- 0.00 (tidak terlambat)
SELECT fn_calculate_fine('2025-01-10', '2025-01-10', 3);  -- 0.00 (hari yang sama)
```

### Validasi Parameter
- NULL due_date / return_date → return 0
- quantity = 0 → return 0
- hari terlambat negatif → dikoreksi ke 0

### Karakteristik
- `DETERMINISTIC` → hasil sama untuk input sama
- `READS SQL DATA` → hanya baca, tidak tulis
- Bisa dipakai di `SELECT`, `WHERE`, `UPDATE`, stored procedure

---

## 4. Trigger: `trg_returns_validate_requested_by`

**File:** `database/triggers.sql`

### Event
`BEFORE INSERT` pada tabel `returns`

### Validasi
```sql
IF NEW.requested_by <> (SELECT user_id FROM borrowings WHERE id = NEW.borrowing_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '...';
END IF;
```

### Aturan
- `returns.requested_by` **harus sama** dengan `borrowings.user_id` (peminjam asli)
- Mencegah user A mengajukan pengembalian untuk pinjaman user B

### Admin CRUD Administratif
Admin **boleh** membuat data pengembalian atas nama peminjam **dengan syarat**:
```sql
INSERT INTO returns (borrowing_id, requested_by, quantity, ...)
VALUES (123, 456, 2, ...);  -- requested_by = borrowings.user_id (ID peminjam), BUKAN ID admin
```
- `processed_by` diisi **saat konfirmasi** oleh petugas (via `sp_confirm_return`), bukan saat INSERT
- Trigger **tidak** mengurangi/menambah stok → tugas stored procedure

---

## 5. Transaksi & Konsistensi Stok

### Mengapa Transaksi Penting?
Tanpa transaksi, skenario race condition:
```
Session A: SELECT quantity_available = 3
Session B: SELECT quantity_available = 3
Session A: UPDATE quantity_available = 0 (approve 3 unit)
Session B: UPDATE quantity_available = 0 (approve 3 unit)  -- STOK NEGATIF LOGIS!
```

### Solusi di Stored Procedure
| Teknik | Implementasi |
|--------|--------------|
| **Row Locking** | `SELECT ... FOR UPDATE` pada `borrowings`, `tools`, `users`, `returns` |
| **Urutan Lock Konsisten** | Selalu: borrowing → petugas → tools (hindari deadlock) |
| **Atomic Update** | `UPDATE tools SET qty_avail = qty_avail -/+ qty WHERE id = ...` (single statement) |
| **Exception Handler** | `DECLARE EXIT HANDLER FOR SQLEXCEPTION ROLLBACK` |
| **Validasi di dalam Transaksi** | Cek stok SETELAH lock, sebelum update |

### Commit vs Rollback
| Situasi | Aksi |
|---------|------|
| Semua validasi lolos, update sukses | `COMMIT` → perubahan permanen |
| Validasi gagal (stok kurang, status salah, dll) | `ROLLBACK` → stok & status tidak berubah |
| Error SQL (deadlock, constraint, dll) | Handler otomatis `ROLLBACK` |

---

## 6. Cara Verifikasi di MariaDB

### Import File (urutan wajib: function → trigger → procedure)
```bash
mysql -u user -p pinjamin < database/functions.sql
mysql -u user -p pinjamin < database/triggers.sql
mysql -u user -p pinjamin < database/procedures.sql
```

### Cek Objek Terbuat
```sql
SHOW PROCEDURE STATUS WHERE Db = 'pinjamin';
SHOW FUNCTION STATUS WHERE Db = 'pinjamin';
SHOW TRIGGERS FROM pinjamin;
```

### Uji Function
```sql
SELECT fn_calculate_fine('2025-01-10', '2025-01-12', 2) AS denda;  -- 20000.00
SELECT fn_calculate_fine('2025-01-15', '2025-01-10', 1) AS denda;  -- 0.00
```

### Uji Trigger
```sql
-- Asumsi: borrowing id=1 milik user_id=5
INSERT INTO returns (borrowing_id, requested_by, quantity) VALUES (1, 5, 1);  -- OK
INSERT INTO returns (borrowing_id, requested_by, quantity) VALUES (1, 99, 1); -- ERROR: requested_by mismatch
```

### Uji Procedure Approval (butuh data test)
```sql
-- Siapkan: user petugas id=2, peminjam id=3, alat id=1 (stok 5), borrowing id=1 pending qty=3
CALL sp_approve_borrowing(1, 2);  -- Sukses: stok jadi 2
CALL sp_approve_borrowing(1, 2);  -- Gagal: status bukan pending
```

### Uji Procedure Return
```sql
-- Siapkan: return id=1 pending, borrowing id=1 approved qty=3, due_date=2025-01-10
CALL sp_confirm_return(1, 2);  -- Konfirmasi 1 unit, stok +1, status partially_returned
```

### Uji Transaksi Demo
```bash
mysql -u user -p pinjamin < database/transaction-demo.sql
```

---

## 7. Ringkasan File

| File | Isi | Urutan Import |
|------|-----|---------------|
| `functions.sql` | `fn_calculate_fine` | 1 (dipakai procedure) |
| `triggers.sql` | `trg_returns_validate_requested_by` | 2 |
| `procedures.sql` | `sp_approve_borrowing`, `sp_confirm_return` | 3 |
| `transaction-demo.sql` | Demo START/COMMIT/ROLLBACK/SAVEPOINT | Manual (read-only safe) |

---

## 8. Masalah & Risiko

| Item | Status | Catatan |
|------|--------|---------|
| Deadlock potential | ⚠️ Rendah | Urutan lock konsisten (borrowing → user → tools) |
| Function di WHERE clause | ✅ OK | `DETERMINISTIC` + `READS SQL DATA` |
| Trigger blocks admin insert | ✅ Terkelola | Admin set `requested_by` = borrower ID |
| Test di DB produksi | ❌ NOT TESTED | Butuh data test terpisah / staging |
| Concurrency test | ❌ NOT TESTED | Butuh dua session paralel |

---

*Dokumen ini untuk Checkpoint B. Dapat dijelaskan ke penguji UKK.*