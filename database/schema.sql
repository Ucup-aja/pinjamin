-- ============================================================
-- PINJAMIN — Database Schema (Produksi)
-- ERD final: docs/diagram/00-erd.drawio
-- MariaDB 10.5+ / MySQL 8.0+
-- Engine: InnoDB, Charset: utf8mb4
-- ============================================================
-- PERINGATAN: File ini HANYA berisi CREATE TABLE.
-- Untuk reset development, gunakan: database/reset-dev.sql
-- ============================================================

-- ------------------------------------------------------------
-- 1. users — Akun pengguna & peran
-- ------------------------------------------------------------
CREATE TABLE `users` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(100) NOT NULL,
    `username`      VARCHAR(50)  NOT NULL,
    `password`      VARCHAR(255) NOT NULL COMMENT 'password_hash() output',
    `role`          ENUM('admin','petugas','peminjam') NOT NULL,
    `is_active`     BOOLEAN NOT NULL DEFAULT TRUE,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_username` (`username`),
    KEY `idx_users_role` (`role`),
    KEY `idx_users_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Pengguna sistem: admin, petugas, peminjam';

-- ------------------------------------------------------------
-- 2. categories — Kategori alat
-- ------------------------------------------------------------
CREATE TABLE `categories` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(100) NOT NULL,
    `description`   TEXT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Kategori inventaris alat';

-- ------------------------------------------------------------
-- 3. tools — Inventaris alat & stok
-- ------------------------------------------------------------
CREATE TABLE `tools` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_id`         INT UNSIGNED NOT NULL,
    `code`                VARCHAR(30) NOT NULL,
    `name`                VARCHAR(150) NOT NULL,
    `description`         TEXT NULL,
    `quantity_total`      INT UNSIGNED NOT NULL DEFAULT 0,
    `quantity_available`  INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tools_code` (`code`),
    KEY `idx_tools_category` (`category_id`),
    KEY `idx_tools_name` (`name`),
    CONSTRAINT `fk_tools_category`
        FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    -- quantity_available tidak boleh melebihi quantity_total
    CONSTRAINT `chk_tools_stock_range`
        CHECK (`quantity_available` <= `quantity_total`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Master alat dan stok (quantity_available <= quantity_total)';

-- ------------------------------------------------------------
-- 4. borrowings — Pengajuan & persetujuan peminjaman
-- ------------------------------------------------------------
CREATE TABLE `borrowings` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         INT UNSIGNED NOT NULL,
    `tool_id`         INT UNSIGNED NOT NULL,
    `quantity`        INT UNSIGNED NOT NULL,
    `borrow_date`     DATE NOT NULL,
    `due_date`        DATE NOT NULL,
    `status`          ENUM('pending','approved','partially_returned','returned','rejected')
                      NOT NULL DEFAULT 'pending',
    `approved_by`     INT UNSIGNED NULL,
    `approved_at`     DATETIME NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_borrowings_user` (`user_id`),
    KEY `idx_borrowings_tool` (`tool_id`),
    KEY `idx_borrowings_status` (`status`),
    KEY `idx_borrowings_dates` (`borrow_date`, `due_date`),
    KEY `idx_borrowings_approved_by` (`approved_by`),
    CONSTRAINT `fk_borrowings_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_borrowings_tool`
        FOREIGN KEY (`tool_id`) REFERENCES `tools` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_borrowings_approved_by`
        FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    -- jumlah peminjaman harus positif
    CONSTRAINT `chk_borrowings_qty_positive`
        CHECK (`quantity` > 0),
    -- due_date tidak boleh lebih awal dari borrow_date
    CONSTRAINT `chk_borrowings_dates`
        CHECK (`due_date` >= `borrow_date`),
    -- approved_at hanya diisi jika status approved/partially_returned/returned
    CONSTRAINT `chk_borrowings_approved_at`
        CHECK (
            (`status` IN ('approved','partially_returned','returned') AND `approved_at` IS NOT NULL)
            OR (`status` IN ('pending','rejected') AND `approved_at` IS NULL)
        ),
    -- approved_by hanya diisi jika status approved/partially_returned/returned
    CONSTRAINT `chk_borrowings_approved_by`
        CHECK (
            (`status` IN ('approved','partially_returned','returned') AND `approved_by` IS NOT NULL)
            OR (`status` IN ('pending','rejected') AND `approved_by` IS NULL)
        )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Transaksi peminjaman: status pending/approved/partially_returned/returned/rejected';

-- ------------------------------------------------------------
-- 5. returns — Pengembalian parsial + denda
-- ------------------------------------------------------------
CREATE TABLE `returns` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `borrowing_id`    INT UNSIGNED NOT NULL,
    `requested_by`    INT UNSIGNED NOT NULL,
    `quantity`        INT UNSIGNED NOT NULL,
    `return_date`     DATE NULL,
    `fine_amount`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `status`          ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
    `processed_by`    INT UNSIGNED NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_returns_borrowing` (`borrowing_id`),
    KEY `idx_returns_requested_by` (`requested_by`),
    KEY `idx_returns_status` (`status`),
    KEY `idx_returns_processed_by` (`processed_by`),
    CONSTRAINT `fk_returns_borrowing`
        FOREIGN KEY (`borrowing_id`) REFERENCES `borrowings` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_returns_requested_by`
        FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_returns_processed_by`
        FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    -- jumlah pengembalian harus positif
    CONSTRAINT `chk_returns_qty_positive`
        CHECK (`quantity` > 0),
    -- fine_amount tidak negatif
    CONSTRAINT `chk_returns_fine_nonneg`
        CHECK (`fine_amount` >= 0),
    -- return_date hanya diisi jika status confirmed
    CONSTRAINT `chk_returns_return_date`
        CHECK (
            (`status` = 'confirmed' AND `return_date` IS NOT NULL)
            OR (`status` IN ('pending','rejected') AND `return_date` IS NULL)
        ),
    -- processed_by hanya diisi jika status confirmed/rejected
    CONSTRAINT `chk_returns_processed_by`
        CHECK (
            (`status` IN ('confirmed','rejected') AND `processed_by` IS NOT NULL)
            OR (`status` = 'pending' AND `processed_by` IS NULL)
        )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Catatan pengembalian parsial: status pending/confirmed/rejected';

-- ============================================================
-- End of schema.sql
-- ============================================================