# Integration Test Suite — Pinjamin

## File Utama
- `integration-test.sql` — Script pengujian lengkap (setup + 12 test case)

---

## Cara Eksekusi Manual

### Prasyarat
- MariaDB/MySQL dengan user yang punya hak `CREATE DATABASE`, `CREATE ROUTINE`, `TRIGGER`
- Database **produksi `pinjamin` TIDAK disentuh**

### Jalankan
```bash
# Dari root project
mysql -u <user> -p < /srv/http/pinjamin/database/tests/integration-test.sql
```

Atau dari client SQL:
```sql
SOURCE /srv/http/pinjamin/database/tests/integration-test.sql;
```

### Apa yang Dilakukan Script
1. `DROP DATABASE IF EXISTS pinjamin_test` → bersihkan test DB lama
2. `CREATE DATABASE pinjamin_test` → DB test baru
3. Import `schema.sql`, `functions.sql`, `triggers.sql`, `procedures.sql`
4. Buat data dummy:
   - 1 Admin, 2 Petugas (1 aktif, 1 nonaktif), 3 Peminjam (1 nonaktif)
   - 3 Kategori, 6 Alat (stok bervariasi)
   - 10 Peminjaman (pending, approved, rejected)
5. Jalankan 12 Test Case otomatis
6. Tampilkan hasil untuk verifikasi manual

---

## Matriks Test Case

| # | Test Case | Expected | Validasi Kunci |
|---|-----------|----------|----------------|
| 1 | Approval berhasil | PASS | `success=1`, stok berkurang, status=approved |
| 2 | Approval ganda ditolak | PASS | `success=0`, status tetap approved |
| 3 | Stok tidak mencukupi | PASS | `success=0`, stok tidak berubah |
| 4 | Pengembalian parsial | PASS | `success=1`, stok +1, status=partially_returned |
| 5 | Pengembalian penuh | PASS | `success=1`, stok +1, status=returned |
| 6 | Return melebihi pinjaman | PASS | `success=0`, status return=pending, stok tidak berubah |
| 7 | Trigger requested_by mismatch | PASS | `ERROR 1644 (45000)` pada INSERT |
| 8 | Rollback petugas invalid | PASS | `success=0`, stok tidak berubah, status=pending |
| 9 | Function denda | PASS | `fn_calculate_fine` hasil benar, prosedur hitung denda benar |
| 10 | Petugas nonaktif | PASS | `success=0`, status=pending |
| 11 | Confirm oleh admin (bukan petugas) | PASS | `success=0`, status return=pending |
| 12 | Return untuk borrowing rejected | PASS | `success=0`, status return=pending |

---

## Cara Verifikasi Hasil

Setelah script jalan, periksa output setiap `SELECT` di blok `=== TEST X ===`:

### Test 1 (Approval Berhasil)
```
+--------+----------------------------------+
| success | info                            |
+--------+----------------------------------+
| 1       | Peminjaman disetujui, stok dikurangi |
+--------+----------------------------------+
Stok multimeter setelah approval: 3  (awal 5 - 2 = 3)
```

### Test 2 (Approval Ganda)
```
+--------+--------------------------------------------------+
| success | info                                            |
+--------+--------------------------------------------------+
| 0       | Peminjaman tidak bisa disetujui (status: approved) |
+--------+--------------------------------------------------+
Status borrowing_1: approved
```

### Test 3 (Stok Tidak Cukup)
```
+--------+------------------------------------------------------+
| success | info                                                |
+--------+------------------------------------------------------+
| 0       | Stok tidak mencukupi. Tersedia: 1, Diminta: 2       |
+--------+------------------------------------------------------+
Stok torque tetap: 1
```

### Test 4 (Return Parsial)
```
+--------+----------------------------------------+
| success | info                                  |
+--------+----------------------------------------+
| 1       | Pengembalian dikonfirmasi. Denda: 0.00 |
+--------+----------------------------------------+
Stok kunci ring: 2 (awal 1 + 1 = 2)
Status borrowing_5: partially_returned
```

### Test 5 (Return Penuh)
```
Stok kunci ring: 3 (2 + 1 = 3, kembali ke total)
Status borrowing_5: returned
```

### Test 6 (Return Melebihi)
```
+--------+------------------------------------------------------------------+
| success | info                                                            |
+--------+------------------------------------------------------------------+
| 0       | Jumlah pengembalian melebihi sisa. Sudah kembali: 0, Sisa: 3... |
+--------+------------------------------------------------------------------+
Status return_3: pending
Stok laptop tidak berubah
```

### Test 7 (Trigger)
```
ERROR 1644 (45000): Pengajuan pengembalian hanya dapat diajukan oleh peminjam...
```
**Jika tidak error → TRIGGER BERMASALAH**

### Test 8 (Rollback Petugas Invalid)
```
+--------+-----------------------------------+
| success | info                             |
+--------+-----------------------------------+
| 0       | Hanya petugas yang dapat menyetujui peminjaman |
+--------+-----------------------------------+
Stok multimeter tidak berubah
Status borrowing_8: pending
```

### Test 9 (Function Denda)
```
fn_calculate_fine(2 hari lalu, hari ini, 3) = 30000.00
fn_calculate_fine(bukan terlambat) = 0.00
fine_amount di return_4 = 30000.00
```

### Test 10-12 (Edge Cases)
Semua return `success=0` dengan pesan error yang jelas.

---

## Catatan Keamanan

| Aturan | Implementasi |
|--------|--------------|
| DB terpisah | `pinjamin_test` di-drop & create ulang |
| Tidak USE pinjamin | Script pakai `USE pinjamin_test` eksplisit |
| Tidak reset-dev | Hanya `integration-test.sql` |
| Data dummy lokal | Semua `INSERT` di dalam script, tidak pakai ID asli |
| Tidak destructive ke produksi | Hanya baca file schema/logic, tulis ke test DB |

---

## Jika MariaDB Tidak Aksesibel

Jika autentikasi gagal (unix_socket), opsi:
1. **User terpisah**: `CREATE USER 'test_user'@'localhost' IDENTIFIED BY 'pass'; GRANT ALL ON pinjamin_test.* TO 'test_user'@'localhost';`
2. **Root dengan password**: `mysql -u root -p < integration-test.sql`
3. **Docker test**: `docker run -d -e MYSQL_ROOT_PASSWORD=test -e MYSQL_DATABASE=pinjamin_test mariadb:13` lalu import

---

## Status Pengujian

| Test | Status | Bukti |
|------|--------|-------|
| 1. Approval berhasil | ⬜ NOT TESTED | Butuh eksekusi manual |
| 2. Approval ganda | ⬜ NOT TESTED | - |
| 3. Stok tidak cukup | ⬜ NOT TESTED | - |
| 4. Return parsial | ⬜ NOT TESTED | - |
| 5. Return penuh | ⬜ NOT TESTED | - |
| 6. Return melebihi | ⬜ NOT TESTED | - |
| 7. Trigger mismatch | ⬜ NOT TESTED | - |
| 8. Rollback gagal | ⬜ NOT TESTED | - |
| 9. Function denda | ⬜ NOT TESTED | User: "Function denda sudah lulus dua pengujian manual" |
| 10. Petugas nonaktif | ⬜ NOT TESTED | - |
| 11. Confirm non-petugas | ⬜ NOT TESTED | - |
| 12. Borrowing rejected | ⬜ NOT TESTED | - |

> **Catatan:** User menyatakan function denda sudah diuji manual (2 hari × 2 unit = 20000, tepat waktu = 0). Test 9 di script akan memvalidasi ulang via procedure.

---

## Troubleshooting

| Error | Penyebab | Solusi |
|-------|----------|--------|
| `ERROR 1044 (42000): Access denied` | User tidak punya hak CREATE DATABASE | Pakai root atau user dengan GRANT ALL |
| `ERROR 1418 (HY000): Function lacks DETERMINISTIC` | `log_bin_trust_function_creators=OFF` | `SET GLOBAL log_bin_trust_function_creators=1;` atau tambah di config |
| `ERROR 1644 (45000)` pada Test 7 | **Ini SUKSES** — trigger bekerja | Lanjutkan, jangan hentikan script |
| `PROCEDURE sp_approve_borrowing does not exist` | Urutan import salah | Import: functions → triggers → procedures |
| Deadlock / Lock wait timeout | Test paralel tidak disengaja | Jalankan script sekali, tidak paralel |

---

## File Terkait

```
/srv/http/pinjamin/database/
├── schema.sql
├── functions.sql
├── triggers.sql
├── procedures.sql
├── transaction-demo.sql
├── reset-dev.sql
└── tests/
    ├── integration-test.sql   (FILE INI)
    └── README.md              (FILE INI)
```

---

*Generated for Checkpoint B Final Verification*