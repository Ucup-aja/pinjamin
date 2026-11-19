# Pinjamin — Aplikasi Peminjaman Alat

Aplikasi web sederhana untuk mengelola peminjaman alat dengan tiga role: **Admin**, **Petugas**, **Peminjam**.

## Persyaratan Sistem

- PHP 8.1+
- MariaDB 10.5+ / MySQL 8.0+
- Apache 2.4+
- PDO MySQL extension

## Struktur Direktori

```
/srv/http/pinjamin/
├── config/              # Konfigurasi (diabaikan Git, di luar DocumentRoot)
│   ├── config.php.example   # Template konfigurasi
│   └── config.php           # Konfigurasi lokal (buat dari example)
├── database/            # Schema, logic, seeder (di luar DocumentRoot)
│   ├── schema.sql
│   ├── functions.sql
│   ├── triggers.sql
│   ├── procedures.sql
│   ├── seed.php
│   └── tests/
├── includes/            # Bootstrap & helper (di luar DocumentRoot)
│   └── bootstrap.php
├── public/              # DocumentRoot (akses web via http://localhost/)
│   ├── index.php            # Entry point → redirect login
│   ├── login.php
│   ├── logout.php
│   ├── dashboard_admin.php
│   ├── dashboard_petugas.php
│   ├── dashboard_peminjam.php
│   └── 403.php
├── logs/                # Log aktivitas & error (diabaikan Git, di luar DocumentRoot)
├── docs/                # Dokumentasi
└── .gitignore
```

## Instalasi & Konfigurasi

### 1. Database

Pastikan database `pinjamin` sudah dibuat dan schema/logic sudah diimpor:

```bash
# Import schema (jika belum)
mysql -u root -p pinjamin < database/schema.sql
mysql -u root -p pinjamin < database/functions.sql
mysql -u root -p pinjamin < database/triggers.sql
mysql -u root -p pinjamin < database/procedures.sql
```

### 2. Buat User Database Khusus (Direkomendasikan)

```sql
-- Jalankan sebagai root MariaDB
CREATE USER 'pinjamin_app'@'localhost' IDENTIFIED BY 'password_kuat_anda';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON pinjamin.* TO 'pinjamin_app'@'localhost';
FLUSH PRIVILEGES;
```

### 3. Konfigurasi Aplikasi

```bash
cd /srv/http/pinjamin
cp config/config.php.example config/config.php
# Edit config.php dan sesuaikan kredensial database
```

Edit `config/config.php`:
```php
'db' => [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'pinjamin',
    'user' => 'pinjamin_app',        // User yang dibuat di langkah 2
    'pass' => 'password_kuat_anda',  // Password user di atas
    ...
],
```

### 4. Konfigurasi Apache (Default DocumentRoot)

**Opsi A: Ubah DocumentRoot default Apache (Direkomendasikan untuk development)**

Edit konfigurasi Apache default (biasanya `/etc/httpd/conf/httpd.conf` atau `/etc/apache2/sites-enabled/000-default.conf`):

```apache
# Cari baru DocumentRoot dan ubah ke:
DocumentRoot "/srv/http/pinjamin/public"

# Cari <Directory "/srv/http"> atau <Directory "/var/www/html"> dan ubah ke:
<Directory "/srv/http/pinjamin/public">
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

**Opsi B: Symlink ke DocumentRoot existing (Alternatif)**

Jika tidak ingin mengubah konfigurasi Apache:
```bash
# Hapus/backup DocumentRoot existing (misal /srv/http atau /var/www/html)
sudo rm -rf /srv/http
# Buat symlink
sudo ln -s /srv/http/pinjamin/public /srv/http
```

Reload/restart Apache setelah perubahan:
```bash
# Arch/Manjaro (httpd)
sudo systemctl reload httpd

# Ubuntu/Debian (apache2)
sudo systemctl reload apache2
```

**Proteksi folder sensitif (otomatis via .htaccess di public/):**

Folder `config/`, `database/`, `includes/`, `logs/` berada **di luar DocumentRoot** sehingga tidak bisa diakses langsung dari web. Tidak perlu konfigurasi Apache tambahan.

### 5. Jalankan Seeder (Membuat Akun Demo)

```bash
cd /srv/http/pinjamin
php database/seed.php
```

Output yang diharapkan:
```
=== Pinjamin Database Seeder ===

✓ Koneksi database berhasil
  + User 'admin' (admin) dibuat
  + User 'petugas1' (petugas) dibuat
  + User 'petugas2' (petugas) dibuat
  + User 'peminjam_a' (peminjam) dibuat
  + User 'peminjam_b' (peminjam) dibuat
✓ User demo: 5 baru dibuat
  + Kategori 'Elektronik' dibuat
  + Kategori 'Mekanik' dibuat
  + Kategori 'Komputer' dibuat
✓ Kategori: 3 baru dibuat
  + Alat 'ELEC-001' (Multimeter Digital) dibuat, stok: 5
  + Alat 'ELEC-002' (Osiloskop Portable) dibuat, stok: 2
  + Alat 'MECH-001' (Set Kunci Ring) dibuat, stok: 3
  + Alat 'MECH-002' (Torque Wrench) dibuat, stok: 1
  + Alat 'COMP-001' (Laptop Testing) dibuat, stok: 4
  + Alat 'COMP-002' (Kabel UTP Cat6) dibuat, stok: 10
✓ Alat: 6 baru dibuat

=== Seeder Selesai ===
Akun demo (password: password123):
  admin      - Admin Utama
  petugas1   - Petugas Satu
  petugas2   - Petugas Dua
  peminjam_a - Peminjam A
  peminjam_b - Peminjam B
```

Seeder aman dijalankan ulang — tidak akan menghapus atau menduplikasi data existing.

## Akses Aplikasi

Buka browser ke: `http://localhost/`

Halaman login akan ditampilkan (redirect otomatis dari `/` ke `/login.php`).

## Akun Demo

| Role | Username | Password |
|------|----------|----------|
| Admin | `admin` | `password123` |
| Petugas | `petugas1` / `petugas2` | `password123` |
| Peminjam | `peminjam_a` / `peminjam_b` | `password123` |

## Fitur Checkpoint C1–C6 (SELESAI SEMUA)

### ✅ Checkpoint A: Database Schema
- [x] 5 tabel: users, categories, tools, borrowings, returns
- [x] PK, FK, UNIQUE, CHECK constraints, indexes
- [x] Engine InnoDB, charset utf8mb4

### ✅ Checkpoint B: Database Logic
- [x] `sp_approve_borrowing` — transaksi, locking, validasi stok
- [x] `sp_confirm_return` — transaksi, locking, denda via function, update status
- [x] `fn_calculate_fine` — Rp5.000/hari/unit
- [x] `trg_returns_validate_requested_by` — validasi pemilik pengembalian
- [x] 12/12 integration test PASS

### ✅ Checkpoint C1–C2: Foundation & Auth
- [x] PDO koneksi, prepared statements, config terpisah
- [x] Login password_hash/verify, session regeneration
- [x] Role-based auth (Admin, Petugas, Peminjam)
- [x] Guard `require_login()` / `require_role()`
- [x] CSRF protection, flash message, HTML escaping
- [x] Log aktivitas ke file
- [x] 3 dashboard role-based, logout benar, 403 page
- [x] Database seeder idempotent (5 user demo)

### ✅ Checkpoint C3: Admin CRUD
- [x] `users.php` — CRUD user (add/edit/delete, password hash, role, active)
- [x] `categories.php` — CRUD kategori
- [x] `tools.php` — CRUD alat + stok (quantity_total, quantity_available)

### ✅ Checkpoint C4: Peminjaman & Persetujuan
- [x] `borrow.php` — Peminjam ajukan pinjam (validasi stok, tanggal, qty)
- [x] `my_borrowings.php` — Riwayat pinjam filter status (5 status)
- [x] `approvals.php` — Petugas setujui via `sp_approve_borrowing`, tolak atomic

### ✅ Checkpoint C5: Pengembalian & Denda
- [x] `return_request.php` — Peminjam ajukan kembali parsial/penuh (status pending)
- [x] `my_returns.php` — Riwayat kembali filter status (3 status)
- [x] `returns.php` — Petugas konfirmasi via `sp_confirm_return`, tolak atomic
- [x] Stok update otomatis via SP, denda via `fn_calculate_fine`

### ✅ Checkpoint C6: Final Core Features
- [x] `reports.php` — Petugas laporan: Peminjaman, Pengembalian, Denda (filter tanggal/status, pagination, print CSS)
- [x] `activity_log.php` — Admin log viewer (parse activity.log, pagination, badge aksi/role)
- [x] `admin_borrowings.php` — Admin CRUD pinjam semua user (batasan bisnis: edit/hapus pending/rejected only)
- [x] `admin_returns.php` — Admin CRUD kembali semua user (batasan bisnis: trigger enforce, hapus pending/rejected only)
- [x] Navigasi dashboard Admin & Petugas diperbarui ke halaman baru

## Pengujian Manual

### PHP Lint
```bash
# Cek sintaks semua file PHP
find /srv/http/pinjamin -name "*.php" -exec php -l {} \;
```

### Test Login & Authorization

| Skenario | Expected |
|----------|----------|
| Login `admin` / `password123` | Redirect ke dashboard_admin.php |
| Login `petugas1` / `password123` | Redirect ke dashboard_petugas.php |
| Login `peminjam_a` / `password123` | Redirect ke dashboard_peminjam.php |
| Password salah | Error "Username atau password salah" |
| User nonaktif (buat via DB) | Error "Akun tidak aktif" |
| Akses dashboard_admin sebagai petugas | 403 Forbidden |
| Akses dashboard_petugas sebagai peminjam | 403 Forbidden |
| Akses dashboard tanpa login | Redirect ke login.php |
| Logout | Session dihancurkan, redirect ke login |

### Test Fitur C3–C6 (Manual Browser)

| Role | Halaman | Test Case | Expected |
|------|---------|-----------|----------|
| Admin | `users.php` | Tambah/edit/hapus user | CRUD works, password hash, self-deactivate blocked |
| Admin | `categories.php` | Tambah/edit/hapus kategori | CRUD works |
| Admin | `tools.php` | Tambah/edit/hapus alat, set stok | CRUD works, stock validation |
| Admin | `admin_borrowings.php` | Tambah pinjam untuk user lain | Validasi user/tool/stok/tanggal, status=pending |
| Admin | `admin_borrowings.php` | Edit pinjam pending | Update works, cek stok |
| Admin | `admin_borrowings.php` | Hapus pinjam pending/rejected | Delete works, cek return confirmed |
| Admin | `admin_returns.php` | Tambah kembali untuk user | Trigger enforce requested_by, cek sisa qty |
| Admin | `admin_returns.php` | Hapus kembali pending/rejected | Delete works |
| Admin | `activity_log.php` | Baca log, pagination, badge | Parse OK, 50/halaman |
| Petugas | `approvals.php` | Setujui via SP, tolak atomic | Stok berkurang, status approved/rejected |
| Petugas | `returns.php` | Konfirmasi via SP, tolak atomic | Stok bertambah, denda, status confirmed/rejected |
| Petugas | `reports.php` | 3 tab filter tanggal/status | Query benar, pagination, print CSS |
| Peminjam | `borrow.php` | Ajukan pinjam, cek stok | Insert pending, stok tdk berubah |
| Peminjam | `my_borrowings.php` | Filter 5 status | Data benar |
| Peminjam | `return_request.php` | Ajukan kembali parsial/penuh | Insert pending, cek sisa |
| Peminjam | `my_returns.php` | Filter 3 status | Data benar, denda tampil |

### Test Keamanan
- [ ] CSRF token valid di form login
- [ ] CSRF token invalid → form ditolak
- [ ] Password tidak tersimpan plaintext di DB
- [ ] Kredensial DB tidak di-commit ke Git (cek .gitignore)
- [ ] Error database tidak bocor ke browser (production mode)
- [ ] Session cookie: HttpOnly, Secure (HTTPS), SameSite=Lax

## Log Aktivitas

Log tersimpan di `logs/activity.log`:
```
[2025-01-15 10:30:45] User: admin (ID: 1, Role: admin) | Action: LOGIN | User admin login sebagai admin
[2025-01-15 10:31:02] User: admin (ID: 1, Role: admin) | Action: LOGOUT | User admin logout
```

## Troubleshooting

| Masalah | Solusi |
|---------|--------|
| "Connection refused" | Cek MariaDB running, host/port di config.php |
| "Access denied for user" | Cek user/password di config.php, grant privileges |
| "Table doesn't exist" | Jalankan import schema.sql, functions.sql, triggers.sql, procedures.sql |
| "CSRF token invalid" | Pastikan session berfungsi, cookie tidak diblokir browser |
| 403 Forbidden di semua halaman | Cek Apache allowOverride, DocumentRoot ke /srv/http/pinjamin/public |
| White screen / error 500 | Cek `logs/php_errors.log` atau `journalctl -u apache2` |

## Keamanan Production

Sebelum deploy ke production:
1. Ubah `config.php` - gunakan password database yang kuat
2. Set `display_errors = Off` di php.ini
3. Gunakan HTTPS (SSL certificate)
4. Set `session.cookie_secure = 1` (di bootstrap.php sudah otomatis jika HTTPS)
5. Folder sensitif (config/, database/, includes/, logs/) sudah di luar DocumentRoot — tidak perlu config Apache tambahan
6. Rotasi log berkala (logrotate)

## Lisensi

Proyek UKK Rekayasa Perangkat Lunak — Pinjamin