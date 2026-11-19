# Kamus Data — Pinjamin

> Sumber: `database/schema.sql` • ERD: `docs/diagram/00-erd.drawio`  
> Versi: 1.1 — Checkpoint A (reviewed)

---

## Daftar Tabel

| No | Tabel | Deskripsi | Baris (estimasi) |
|----|-------|-----------|------------------|
| 1 | `users` | Akun pengguna & peran (admin, petugas, peminjam) | ~50 |
| 2 | `categories` | Kategori inventaris alat | ~20 |
| 3 | `tools` | Master alat & stok (quantity_available ≤ quantity_total) | ~200 |
| 4 | `borrowings` | Transaksi peminjaman: pending → approved → partially_returned/returned/rejected | ~1.000 |
| 5 | `returns` | Catatan pengembalian parsial + denda: pending → confirmed/rejected | ~1.500 |

---

## 1. users

**Deskripsi:** Akun login sistem dengan tiga peran.

| Atribut | Tipe | NULL | Default | Constraint | Keterangan |
|---------|------|------|---------|------------|------------|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | Identitas unik |
| `name` | VARCHAR(100) | NO | — | — | Nama lengkap |
| `username` | VARCHAR(50) | NO | — | **UQ** | Login username |
| `password` | VARCHAR(255) | NO | — | — | Hash dari `password_hash()` |
| `role` | ENUM('admin','petugas','peminjam') | NO | — | — | Hak akses |
| `is_active` | BOOLEAN | NO | TRUE | — | Akun aktif? |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | Waktu dibuat |

**Indeks:**
- `uk_users_username` (UNIQUE) — pencarian login
- `idx_users_role` — filter per peran
- `idx_users_is_active` — filter akun aktif

**Relasi (FK keluar):**
- `borrowings.user_id` → `users.id` (1:N, **RESTRICT**)
- `borrowings.approved_by` → `users.id` (1:N nullable, **RESTRICT**)
- `returns.requested_by` → `users.id` (1:N, **RESTRICT**)
- `returns.processed_by` → `users.id` (1:N nullable, **RESTRICT**)

> **Catatan:** Semua FK ke `users` menggunakan `ON DELETE RESTRICT`. User yang memiliki transaksi tidak bisa dihapus; gunakan `is_active = FALSE` untuk menonaktifkan.

---

## 2. categories

**Deskripsi:** Kategori alat.

| Atribut | Tipe | NULL | Default | Constraint | Keterangan |
|---------|------|------|---------|------------|------------|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | Identitas unik |
| `name` | VARCHAR(100) | NO | — | **UQ** | Nama kategori |
| `description` | TEXT | YES | NULL | — | Deskripsi opsional |

**Indeks:**
- `uk_categories_name` (UNIQUE) — pencarian nama

**Relasi (FK keluar):**
- `tools.category_id` → `categories.id` (1:N, **RESTRICT**)

---

## 3. tools

**Deskripsi:** Inventaris alat dengan stok total & tersedia.

| Atribut | Tipe | NULL | Default | Constraint | Keterangan |
|---------|------|------|---------|------------|------------|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | Identitas unik |
| `category_id` | INT UNSIGNED | NO | — | **FK** | Kategori alat |
| `code` | VARCHAR(30) | NO | — | **UQ** | Kode unik alat (mis. ALT-001) |
| `name` | VARCHAR(150) | NO | — | — | Nama alat |
| `description` | TEXT | YES | NULL | — | Deskripsi |
| `quantity_total` | INT UNSIGNED | NO | 0 | — | Jumlah unit total |
| `quantity_available` | INT UNSIGNED | NO | 0 | **CHK** | Unit siap pinjam (≤ total) |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | Waktu dibuat |

**Indeks:**
- `uk_tools_code` (UNIQUE) — pencarian kode
- `idx_tools_category` — filter per kategori
- `idx_tools_name` — pencarian nama

**Constraint CHECK:**
- `chk_tools_stock_range`: `quantity_available <= quantity_total`

**Relasi (FK masuk):**
- `categories.id` ← `tools.category_id` (**RESTRICT**)

**Relasi (FK keluar):**
- `borrowings.tool_id` → `tools.id` (1:N, **RESTRICT**)

---

## 4. borrowings

**Deskripsi:** Pengajuan peminjaman satu alat oleh satu peminjam. Status berubah mengikuti alur: `pending` → `approved` → `partially_returned` / `returned` / `rejected`.

| Atribut | Tipe | NULL | Default | Constraint | Keterangan |
|---------|------|------|---------|------------|------------|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PK | Identitas unik |
| `user_id` | INT UNSIGNED | NO | — | **FK** | Peminjam (role=peminjam) |
| `tool_id` | INT UNSIGNED | NO | — | **FK** | Alat yang dipinjam |
| `quantity` | INT UNSIGNED | NO | — | **CHK >0** | Jumlah unit diminta |
| `borrow_date` | DATE | NO | — | — | Tanggal mulai pinjam |
| `due_date` | DATE | NO | — | **CHK** | Batas pengembalian (≥ borrow_date) |
| `status` | ENUM('pending','approved','partially_returned','returned','rejected') | NO | 'pending' | — | Status transaksi |
| `approved_by` | INT UNSIGNED | YES | NULL | **FK** | Petugas yang menyetujui |
| `approved_at` | DATETIME | YES | NULL | **CHK** | Waktu persetujuan |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | Waktu pengajuan |

**Indeks:**
- `idx_borrowings_user` — riwayat per peminjam
- `idx_borrowings_tool` — riwayat per alat
- `idx_borrowings_status` — filter status (dashboard petugas)
- `idx_borrowings_dates` — laporan per rentang tanggal
- `idx_borrowings_approved_by` — audit petugas

**Constraint CHECK:**
- `chk_borrowings_qty_positive`: `quantity > 0`
- `chk_borrowings_dates`: `due_date >= borrow_date`
- `chk_borrowings_approved_at`: `approved_at` hanya diisi jika status ∈ {approved, partially_returned, returned}
- `chk_borrowings_approved_by`: `approved_by` hanya diisi jika status ∈ {approved, partially_returned, returned}

**Relasi (FK masuk):**
- `users.id` ← `borrowings.user_id` (**RESTRICT**)
- `tools.id` ← `borrowings.tool_id` (**RESTRICT**)
- `users.id` ← `borrowings.approved_by` (**RESTRICT**, nullable)

**Relasi (FK keluar):**
- `returns.borrowing_id` → `borrowings.id` (1:N, **RESTRICT**)

---

## 5. returns

**Deskripsi:** Pengembalian parsial per peminjaman. Status: `pending` → `confirmed` / `rejected`. Denda dihitung saat dikonfirmasi.

| Atribut | Tipe | NULL | Default | Constraint | Keterangan |
|---------|------|------|---------|------------|------------|
| `id` | INT UNSIGNED NOT NULL AUTO_INCREMENT |
| `borrowing_id` | INT UNSIGNED | NO | — | **FK** | Peminjaman induk |
| `requested_by` | INT UNSIGNED | NO | — | **FK** | Peminjam yang mengajukan |
| `quantity` | INT UNSIGNED | NO | — | **CHK >0** | Jumlah unit dikembalikan |
| `return_date` | DATE | YES | NULL | **CHK** | Tanggal dikonfirmasi diterima |
| `fine_amount` | DECIMAL(12,2) | NO | 0.00 | **CHK ≥0** | Denda keterlambatan |
| `status` | ENUM('pending','confirmed','rejected') | NO | 'pending' | — | Status pengembalian |
| `processed_by` | INT UNSIGNED | YES | NULL | **FK** | Petugas pemroses |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | — | Waktu pengajuan |

**Indeks:**
- `idx_returns_borrowing` — riwayat per peminjaman
- `idx_returns_requested_by` — riwayat per peminjam
- `idx_returns_status` — filter status (dashboard petugas)
- `idx_returns_processed_by` — audit petugas

**Constraint CHECK:**
- `chk_returns_qty_positive`: `quantity > 0`
- `chk_returns_fine_nonneg`: `fine_amount >= 0`
- `chk_returns_return_date`: `return_date` hanya diisi jika status = 'confirmed'
- `chk_returns_processed_by`: `processed_by` hanya diisi jika status ∈ {confirmed, rejected}

**Relasi (FK masuk):**
- `borrowings.id` ← `returns.borrowing_id` (**RESTRICT**)
- `users.id` ← `returns.requested_by` (**RESTRICT**)
- `users.id` ← `returns.processed_by` (**RESTRICT**, nullable)

---

## Ringkasan Relasi (ERD)

```
categories (1) ──────< (N) tools
users (1) ───────────< (N) borrowings (sebagai peminjam, user_id)
tools (1) ───────────< (N) borrowings (tool_id)
users (1) ───────────< (N) borrowings (sebagai approved_by, nullable)
borrowings (1) ──────< (N) returns
users (1) ───────────< (N) returns (sebagai requested_by)
users (1) ───────────< (N) returns (sebagai processed_by, nullable)
```

**Notasi:**  
- Solid line = FK wajib (NOT NULL)  
- Dashed line = FK nullable  
- Crow's Foot = satu ke banyak (1:N)  
- **Semua FK: ON UPDATE RESTRICT, ON DELETE RESTRICT**  
- **Tidak ada ON DELETE CASCADE** pada data transaksi  
- User/Category/Tool yang punya transaksi tidak bisa dihapus; gunakan soft delete (`is_active = FALSE`)

---

## Aturan Bisnis Tertulis ke Schema

| ID | Aturan | Implementasi |
|----|--------|--------------|
| BR-03 | Jumlah positif | `CHECK (quantity > 0)` di borrowings & returns |
| BR-04 | `0 ≤ available ≤ total` | `CHECK (quantity_available <= quantity_total)` di tools |
| BR-05 | due_date ≥ borrow_date | `CHECK (due_date >= borrow_date)` di borrowings |
| BR-06 | pending tidak kurangi stok | Logika aplikasi (bukan schema) |
| BR-08 | Total kembali ≤ dipinjam | Logika aplikasi + transaksi DB |
| BR-10 | Atomik COMMIT/ROLLBACK | Transaksi PDO di PHP |
| BR-11 | Riwayat tidak dihapus sembarangan | **ON DELETE RESTRICT** di semua FK |

---

## Catatan Implementasi

1. **Password** disimpan sebagai hash `password_hash()` (VARCHAR 255 cukup untuk BCRYPT/ARGON2).
2. **Denda** dihitung di aplikasi: `hari_terlambat × qty × 5000`, disimpan ke `fine_amount`.
3. **Log aktivitas** ke file `.log` (bukan tabel) — sesuai keputusan desain.
4. **ENUM** digunakan untuk status & role karena nilainya tetap dan kecil.
5. **DECIMAL(12,2)** untuk denda mendukung hingga 99.999.999,99.
6. Semua FK menggunakan `ON UPDATE RESTRICT` dan `ON DELETE RESTRICT` (termasuk nullable FK `approved_by`, `processed_by`) untuk menjaga integritas histori.
6. **DROP TABLE** dipindahkan ke `database/reset-dev.sql` (khusus development).

---

## File SQL

| File | Fungsi |
|------|--------|
| `database/schema.sql` | **Produksi** — CREATE TABLE saja, aman untuk import ke DB kosong atau existing (tanpa DROP) |
| `database/reset-dev.sql` | **Development** — DROP + FOREIGN_KEY_CHECKS=0 + SOURCE schema.sql |

---

## Perintah Import Manual

### Produksi / Database bersih
```bash
mysql -u <user> -p <database> < /srv/http/pinjamin/database/schema.sql
```

### Development (reset ulang)
```bash
mysql -u <user> -p <database> < /srv/http/pinjamin/database/reset-dev.sql
```

Atau dari client SQL:
```sql
-- Produksi
SOURCE /srv/http/pinjamin/database/schema.sql;

-- Development reset
SOURCE /srv/http/pinjamin/database/reset-dev.sql;
```

**Validasi pasca-import:**
```sql
SHOW TABLES;
DESCRIBE users;
DESCRIBE categories;
DESCRIBE tools;
DESCRIBE borrowings;
DESCRIBE returns;
SHOW INDEX FROM users;
SHOW INDEX FROM categories;
SHOW INDEX FROM tools;
SHOW INDEX FROM borrowings;
SHOW INDEX FROM returns;
SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, DELETE_RULE, UPDATE_RULE
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL;
```

---

*Dokumen ini dihasilkan pada Checkpoint A. Diperbarui saat schema berubah.*