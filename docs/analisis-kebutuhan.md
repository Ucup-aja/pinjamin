# Analisis Kebutuhan Aplikasi Pinjamin

**Proyek:** Pinjamin — Aplikasi Peminjaman Alat  
**Kegiatan:** Uji Kompetensi Keahlian (UKK) Rekayasa Perangkat Lunak  
**Metode pengembangan:** Waterfall sederhana  
**Versi dokumen:** 1.0 — rancangan awal sebelum implementasi

## 1. Latar Belakang

Proses peminjaman alat memerlukan pencatatan pengguna, inventaris, pengajuan, persetujuan, dan pengembalian. Jika dilakukan secara manual, petugas dapat kesulitan mengetahui ketersediaan alat dan riwayat transaksi. **Pinjamin** dirancang sebagai aplikasi web sederhana untuk mengelola proses tersebut secara terpusat.

Dokumen ini disusun berdasarkan **soal praktik UKK Paket 1: Pengembangan Aplikasi Peminjaman Alat**, terutama matriks fitur tiga level pengguna dan langkah kerja pengembangan. Detail seperti jumlah tabel, pengembalian parsial, dan tarif denda merupakan **keputusan desain proyek**, bukan seluruhnya aturan yang ditentukan eksplisit dalam soal.

## 2. Tujuan Aplikasi

1. Mempermudah pengelolaan pengguna, kategori, dan alat.
2. Memungkinkan Peminjam mengajukan peminjaman dan pengembalian secara tercatat.
3. Membantu Petugas menyetujui peminjaman, memantau pengembalian, dan mencetak laporan.
4. Membantu Admin mengelola data dan melihat catatan aktivitas.
5. Menjaga jumlah stok dan riwayat transaksi tetap konsisten.

## 3. Ruang Lingkup

### 3.1 Pengguna dan Hak Akses

Aplikasi mempunyai tiga peran (*role*) berikut. Pembagian ini mengikuti matriks fitur soal UKK.

| Fitur | Admin | Petugas | Peminjam |
|---|:---:|:---:|:---:|
| Login dan logout | ✓ | ✓ | ✓ |
| CRUD pengguna | ✓ | — | — |
| CRUD kategori | ✓ | — | — |
| CRUD alat | ✓ | — | — |
| CRUD data peminjaman | ✓ | — | — |
| CRUD data pengembalian | ✓ | — | — |
| Melihat log aktivitas | ✓ | — | — |
| Menyetujui/menolak pengajuan peminjaman | — | ✓ | — |
| Memantau pengembalian | — | ✓ | — |
| Mencetak laporan | — | ✓ | — |
| Melihat daftar alat | — | — | ✓ |
| Mengajukan peminjaman | — | — | ✓ |
| Mengajukan pengembalian | — | — | ✓ |

**Catatan desain:** Konfirmasi atau penolakan pengembalian dilakukan oleh Petugas sebagai bagian dari proses pemantauan, agar stok hanya bertambah setelah alat diterima. Admin memiliki pengelolaan administratif atas data peminjaman dan pengembalian, tetapi setiap perubahan transaksi harus tetap menjaga konsistensi stok dan riwayat.

### 3.2 Batasan Sistem

- Aplikasi berbasis web, berjalan di lingkungan LAMP lokal.
- Inventaris disimpan **berdasarkan jumlah unit**, bukan identitas unik setiap unit.
- Satu pengajuan peminjaman hanya memuat **satu jenis alat**.
- Pengajuan peminjaman memerlukan keputusan Petugas.
- Pengembalian dapat dilakukan secara **parsial**.
- Pembayaran denda secara online, notifikasi email/WhatsApp, dan fitur multi-cabang **tidak termasuk ruang lingkup**.
- Log aktivitas disimpan ke file `.log`, bukan tabel database (keputusan desain).

## 4. Kebutuhan Fungsional

Kode kebutuhan berikut digunakan sebagai acuan implementasi dan pengujian.

| ID | Kebutuhan | Pengguna |
|---|---|---|
| FR-01 | Pengguna dapat login menggunakan username dan password. | Semua |
| FR-02 | Pengguna dapat logout dan mengakhiri sesi. | Semua |
| FR-03 | Mengelola data pengguna beserta perannya. | Admin |
| FR-04 | Mengelola data kategori alat. | Admin |
| FR-05 | Mengelola data alat dan jumlah stok awal/total. | Admin |
| FR-06 | Mengelola data peminjaman secara administratif. | Admin |
| FR-07 | Mengelola data pengembalian secara administratif. | Admin |
| FR-08 | Melihat catatan aktivitas aplikasi. | Admin |
| FR-09 | Menyetujui atau menolak pengajuan peminjaman. | Petugas |
| FR-10 | Memantau pengembalian dan memproses konfirmasi penerimaan alat. | Petugas |
| FR-11 | Melihat dan mencetak laporan peminjaman/pengembalian. | Petugas |
| FR-12 | Melihat daftar alat beserta ketersediaannya. | Peminjam |
| FR-13 | Mengajukan peminjaman alat dan jumlah unit yang diminta. | Peminjam |
| FR-14 | Mengajukan pengembalian sebagian atau seluruh unit yang dipinjam. | Peminjam |
| FR-15 | Sistem menjaga perhitungan stok ketika peminjaman disetujui dan pengembalian dikonfirmasi. | Sistem |
| FR-16 | Sistem menghitung denda keterlambatan pada pengembalian yang dikonfirmasi. | Sistem |

### 4.1 Alur Login

1. Pengguna memasukkan username dan password.
2. Sistem memvalidasi input, mencari akun, memverifikasi password, dan memeriksa status akun aktif.
3. Jika valid, sistem membuat sesi login dan membuka dashboard sesuai role.
4. Jika tidak valid, sistem menampilkan pesan kegagalan tanpa membocorkan detail akun.

### 4.2 Alur Peminjaman

1. Peminjam memilih alat, jumlah unit, tanggal pinjam, dan batas pengembalian.
2. Sistem memvalidasi input dan menampilkan ketersediaan stok saat pengajuan.
3. Pengajuan disimpan dengan status `pending`; stok **belum berkurang**.
4. Petugas memilih **setujui** atau **tolak**.
5. Saat menyetujui, sistem memeriksa ulang stok di dalam transaksi database. Jika cukup, stok dikurangi dan status menjadi `approved`; jika tidak, persetujuan dibatalkan.
6. Jika ditolak, status menjadi `rejected` dan stok tidak berubah.

### 4.3 Alur Pengembalian

1. Peminjam memilih peminjaman aktif dan memasukkan jumlah yang akan dikembalikan.
2. Sistem memeriksa sisa unit yang belum kembali, termasuk jumlah yang masih diajukan untuk dikembalikan.
3. Pengajuan pengembalian disimpan dengan status `pending`; stok **belum bertambah**.
4. Petugas memeriksa pengembalian lalu mengonfirmasi atau menolak.
5. Setelah konfirmasi berhasil, sistem mencatat tanggal kembali, menghitung denda (jika terlambat), dan menambah stok dalam satu transaksi database.
6. Status peminjaman menjadi `partially_returned` jika masih ada unit belum kembali, atau `returned` jika seluruh unit sudah kembali.

## 5. Aturan Bisnis

| ID | Aturan |
|---|---|
| BR-01 | Setiap pengguna mempunyai satu role: `admin`, `petugas`, atau `peminjam`. |
| BR-02 | Hanya pengguna aktif yang dapat login. |
| BR-03 | Jumlah alat dan jumlah peminjaman/pengembalian harus berupa bilangan bulat positif, kecuali stok yang boleh bernilai nol. |
| BR-04 | `0 ≤ stok_tersedia ≤ stok_total`. |
| BR-05 | Tanggal jatuh tempo tidak boleh lebih awal dari tanggal mulai peminjaman. |
| BR-06 | Pengajuan `pending` tidak mengurangi stok; hanya persetujuan yang berhasil mengurangi stok. |
| BR-07 | Pemeriksaan stok saat persetujuan harus dilakukan ulang secara aman terhadap proses bersamaan. |
| BR-08 | Total unit yang sudah dikonfirmasi kembali tidak boleh melebihi jumlah dipinjam. Permintaan pengembalian `pending` juga diperhitungkan agar tidak terjadi pengajuan berlebihan. |
| BR-09 | Pengembalian `pending` atau `rejected` tidak menambah stok. |
| BR-10 | Perubahan stok dan status transaksi harus disimpan secara atomik menggunakan COMMIT/ROLLBACK. |
| BR-11 | Data transaksi yang sudah memengaruhi stok tidak boleh dihapus atau diubah sembarangan; aksi CRUD administratif harus mempertahankan integritas dan histori. |
| BR-12 | Hak akses diperiksa pada server, bukan hanya dengan menyembunyikan tombol/menu. |

**Aturan denda yang dipilih untuk proyek:** Rp5.000 per hari keterlambatan per unit yang dikembalikan terlambat.

```
hari_terlambat = max(0, tanggal_konfirmasi - tanggal_jatuh_tempo dalam hari)
denda = hari_terlambat × jumlah_unit_dikonfirmasi × Rp5.000
```

Nominal Rp5.000 merupakan **asumsi/keputusan desain Pinjamin** dan dapat diletakkan dalam satu konfigurasi agar mudah diubah. Soal UKK meminta alur perhitungan denda pada diagram, tetapi tidak menetapkan tarif tersebut.

## 6. Kebutuhan Data dan Rancangan Awal

Direncanakan lima tabel utama. Atribut detail, tipe data, primary key, foreign key, dan kardinalitas akan ditetapkan dalam **ERD final** dan menjadi acuan `schema.sql`.

| Tabel | Data utama | Hubungan |
|---|---|---|
| `users` | ID, nama, username, password hash, role, status aktif | Satu pengguna dapat memiliki banyak peminjaman. |
| `categories` | ID, nama, deskripsi | Satu kategori memiliki banyak alat. |
| `tools` | ID, kategori, kode, nama, deskripsi, stok total, stok tersedia | Satu alat dapat dipinjam berkali-kali. |
| `borrowings` | ID, peminjam, alat, jumlah, tanggal, tenggat, status, petugas persetujuan | Satu peminjaman dapat memiliki banyak catatan pengembalian. |
| `returns` | ID, peminjaman, pengaju, jumlah, tanggal kembali, denda, status, petugas pemroses | Setiap catatan pengembalian terkait satu peminjaman. |

**Data access:** PHP mengakses MariaDB melalui PDO dengan prepared statements. Operasi baca, tambah, ubah, dan hapus mengikuti otorisasi role. Validasi menggunakan percabangan (`if`/`else`), dan pengulangan digunakan seperlunya saat menampilkan hasil query. Data besar ditampilkan dengan `LIMIT`/pagination.

## 7. Kebutuhan Nonfungsional

| Aspek | Kebutuhan |
|---|---|
| Keamanan | Password disimpan menggunakan `password_hash()` dan diperiksa menggunakan `password_verify()`; sesi login dan role diverifikasi pada server; input tervalidasi; output HTML di-escape; aksi perubahan data dilindungi CSRF. |
| Integritas | Foreign key, constraint, dan transaksi database digunakan untuk mencegah data yang tidak konsisten. |
| Performa | Query menggunakan indeks yang relevan, pembatasan hasil (`LIMIT`) pada daftar besar, dan tidak ada pengulangan yang tidak diperlukan. |
| Kegunaan | Navigasi jelas, formulir sederhana, pesan berhasil/gagal mudah dipahami, tampilan dapat dipakai di laptop maupun ponsel. |
| Pemeliharaan | Struktur folder sederhana; konfigurasi dan log dipisah dari berkas yang dapat diakses publik. |
| Audit | Aktivitas penting seperti login, perubahan data, persetujuan, dan konfirmasi pengembalian dicatat ke file `.log` tanpa menyimpan password. |

## 8. Teknologi dan Lingkungan

| Komponen | Teknologi |
|---|---|
| Sistem operasi pengembangan | Arch Linux |
| Web server | Apache HTTP Server |
| Bahasa sisi server | PHP native |
| Database | MariaDB/MySQL |
| Akses database | PHP PDO |
| Antarmuka | HTML, CSS, JavaScript minimal |
| Diagram | draw.io / diagrams.net |
| Dokumentasi | Markdown |

Aplikasi dikembangkan dan diuji secara lokal. Tidak memerlukan framework PHP, ORM, atau layanan pihak ketiga.

## 9. Metode Pengembangan: Waterfall Sederhana

1. **Analisis kebutuhan:** menentukan pengguna, fitur, data, aturan bisnis, serta kebutuhan nonfungsional (dokumen ini).
2. **Desain:** membuat ERD serta flowchart login, peminjaman, dan pengembalian beserta denda menggunakan draw.io.
3. **Implementasi:** membuat schema SQL, fitur database (relasi, stored procedure, SQL function, trigger, transaksi), dan kode aplikasi PHP.
4. **Pengujian:** menyiapkan dan menjalankan skenario login, tambah alat, peminjaman, pengembalian, serta pemeriksaan hak akses.
5. **Dokumentasi:** menjelaskan modul (input–proses–output), fungsi/prosedur, debugging, hasil pengujian, dan evaluasi singkat.

Tahapan di atas dapat diperbaiki secara terbatas jika pengujian menemukan kesalahan desain atau implementasi.

## 10. Kriteria Penerimaan Minimum

Proyek dinyatakan memenuhi kebutuhan utama apabila dapat didemonstrasikan bahwa:

- Tiga role dapat login dan logout serta hanya mengakses fungsi yang diizinkan.
- Admin dapat mengelola pengguna, kategori, alat, peminjaman, pengembalian, dan melihat log aktivitas.
- Peminjam dapat melihat alat, mengajukan pinjam, dan mengajukan pengembalian.
- Petugas dapat menyetujui peminjaman, memantau pengembalian, dan mencetak laporan.
- Stok berubah dengan benar hanya setelah persetujuan/konfirmasi; pengembalian parsial dan denda ditangani dengan benar.
- Database dapat dibuat dari file `.sql`, dengan relasi, stored procedure, SQL function, trigger, dan contoh COMMIT/ROLLBACK yang dapat ditunjukkan.
- ERD, tiga flowchart, dokumentasi modul, dan bukti pengujian tersedia.

## 11. Artefak yang Akan Disiapkan

| Artefak | Lokasi rencana |
|---|---|
| Analisis kebutuhan | `docs/analisis-kebutuhan.md` |
| ERD | `docs/diagrams/01-erd.drawio` |
| Flowchart login | `docs/diagrams/02-flowchart-login.drawio` |
| Flowchart peminjaman | `docs/diagrams/03-flowchart-peminjaman.drawio` |
| Flowchart pengembalian dan denda | `docs/diagrams/04-flowchart-pengembalian.drawio` |
| Schema database | `database/schema.sql` |
| Rutin, fungsi, trigger SQL | `database/routines.sql` (atau berkas SQL terpisah) |
| Dokumentasi modul/fungsi | `docs/modul.md` |
| Bukti pengujian dan evaluasi | `docs/pengujian.md`, `docs/evaluasi.md` |

Nama dan lokasi berkas di atas adalah **rencana**, bukan pernyataan bahwa semuanya sudah dibuat.

---

**Acuan utama:** Soal Praktik Kejuruan UKK Paket 1, *Pengembangan Aplikasi Peminjaman Alat*, bagian **Tabel Fitur Aplikasi Peminjaman Alat** (halaman 2) dan **Langkah Kerja** (halaman 3–4).  
**Catatan:** Rancangan final database dan alur implementasi harus konsisten dengan ERD dan flowchart yang disetujui sebelum penulisan `schema.sql`.
