<?php
// ============================================================
// PINJAMIN — Database Seeder
// Membuat akun demo (admin, petugas, peminjam) + kategori + alat contoh
// Aman dijalankan ulang (idempotent) — tidak menghapus data existing
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';

echo "=== Pinjamin Database Seeder ===\n\n";

try {
    $pdo = get_pdo();
    echo "✓ Koneksi database berhasil\n";

    // ------------------------------------------------------------
    // 1. Buat akun demo (password: password123)
    // ------------------------------------------------------------
    $password_hash = password_hash('password123', PASSWORD_DEFAULT);

    $users = [
        ['admin', 'Admin Utama', 'admin', $password_hash, true],
        ['petugas1', 'Petugas Satu', 'petugas', $password_hash, true],
        ['petugas2', 'Petugas Dua', 'petugas', $password_hash, true],
        ['peminjam_a', 'Peminjam A', 'peminjam', $password_hash, true],
        ['peminjam_b', 'Peminjam B', 'peminjam', $password_hash, true],
    ];

    $created_users = 0;
    foreach ($users as [$username, $name, $role, $hash, $active]) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            echo "  - User '$username' sudah ada, dilewati\n";
            continue;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO users (username, name, password, role, is_active) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$username, $name, $hash, $role, $active]);
        $created_users++;
        echo "  + User '$username' ($role) dibuat\n";
    }
    echo "✓ User demo: $created_users baru dibuat\n";

    // ------------------------------------------------------------
    // 2. Buat kategori (jika belum ada)
    // ------------------------------------------------------------
    $categories = [
        ['Elektronik', 'Alat elektronik dan aksesoris'],
        ['Mekanik', 'Alat mekanik dan bengkel'],
        ['Komputer', 'Perangkat komputer dan jaringan'],
    ];

    $created_cats = 0;
    foreach ($categories as [$name, $desc]) {
        $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
        $stmt->execute([$name]);
        if ($stmt->fetch()) {
            echo "  - Kategori '$name' sudah ada, dilewati\n";
            continue;
        }
        $stmt = $pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
        $stmt->execute([$name, $desc]);
        $created_cats++;
        echo "  + Kategori '$name' dibuat\n";
    }
    echo "✓ Kategori: $created_cats baru dibuat\n";

    // ------------------------------------------------------------
    // 3. Buat alat contoh (jika belum ada)
    // ------------------------------------------------------------
    // Ambil ID kategori
    $cat_map = [];
    $stmt = $pdo->query("SELECT id, name FROM categories");
    while ($row = $stmt->fetch()) {
        $cat_map[$row['name']] = $row['id'];
    }

    $tools = [
        ['ELEC-001', 'Multimeter Digital', 'Multimeter 3.5 digit', 'Elektronik', 5],
        ['ELEC-002', 'Osiloskop Portable', 'Osiloskop 2 channel', 'Elektronik', 2],
        ['MECH-001', 'Set Kunci Ring', 'Set kunci ring 8-19mm', 'Mekanik', 3],
        ['MECH-002', 'Torque Wrench', 'Torque wrench 10-50Nm', 'Mekanik', 1],
        ['COMP-001', 'Laptop Testing', 'Laptop untuk testing jaringan', 'Komputer', 4],
        ['COMP-002', 'Kabel UTP Cat6', 'Kabel jaringan 305m', 'Komputer', 10],
    ];

    $created_tools = 0;
    foreach ($tools as [$code, $name, $desc, $cat_name, $qty]) {
        $stmt = $pdo->prepare("SELECT id FROM tools WHERE code = ?");
        $stmt->execute([$code]);
        if ($stmt->fetch()) {
            echo "  - Alat '$code' sudah ada, dilewati\n";
            continue;
        }
        $cat_id = $cat_map[$cat_name] ?? 1;
        $stmt = $pdo->prepare(
            "INSERT INTO tools (category_id, code, name, description, quantity_total, quantity_available) VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$cat_id, $code, $name, $desc, $qty, $qty]);
        $created_tools++;
        echo "  + Alat '$code' ($name) dibuat, stok: $qty\n";
    }
    echo "✓ Alat: $created_tools baru dibuat\n";

    echo "\n=== Seeder Selesai ===\n";
    echo "Akun demo (password: password123):\n";
    echo "  admin      - Admin Utama\n";
    echo "  petugas1   - Petugas Satu\n";
    echo "  petugas2   - Petugas Dua\n";
    echo "  peminjam_a - Peminjam A\n";
    echo "  peminjam_b - Peminjam B\n";

} catch (PDOException $e) {
    echo "✗ Error database: " . $e->getMessage() . "\n";
    exit(1);
} catch (Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}