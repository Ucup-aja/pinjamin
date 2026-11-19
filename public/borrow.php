<?php
// ============================================================
// PINJAMIN — Pengajuan Peminjaman (Peminjam)
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('peminjam');

$flash = get_flash();
$errors = [];
$success = false;

$uid = current_user()['id'];

// Handle POST - submit borrowing request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token CSRF tidak valid';
    } else {
        try {
            $pdo = get_pdo();

            $tool_id = (int)($_POST['tool_id'] ?? 0);
            $quantity = (int)($_POST['quantity'] ?? 0);
            $borrow_date = $_POST['borrow_date'] ?? '';
            $due_date = $_POST['due_date'] ?? '';

            // Validasi input
            if ($tool_id <= 0) {
                $errors[] = 'Alat wajib dipilih';
            }
            if ($quantity <= 0) {
                $errors[] = 'Jumlah pinjam harus bilangan bulat positif';
            }
            if ($borrow_date === '') {
                $errors[] = 'Tanggal peminjaman wajib diisi';
            }
            if ($due_date === '') {
                $errors[] = 'Tanggal jatuh tempo wajib diisi';
            }
            if ($borrow_date !== '' && $due_date !== '' && $due_date < $borrow_date) {
                $errors[] = 'Tanggal jatuh tempo tidak boleh lebih awal dari tanggal peminjaman';
            }

            if (empty($errors)) {
                // Cek alat exists dan stok tersedia
                $stmt = $pdo->prepare("SELECT id, name, code, quantity_available FROM tools WHERE id = ?");
                $stmt->execute([$tool_id]);
                $tool = $stmt->fetch();

                if (!$tool) {
                    $errors[] = 'Alat tidak ditemukan';
                } elseif ($tool['quantity_available'] < $quantity) {
                    $errors[] = "Stok tidak mencukupi. Tersedia: {$tool['quantity_available']}, Diminta: $quantity";
                } else {
                    // Insert borrowing request
                    $stmt = $pdo->prepare("
                        INSERT INTO borrowings (user_id, tool_id, quantity, borrow_date, due_date, status)
                        VALUES (?, ?, ?, ?, ?, 'pending')
                    ");
                    $stmt->execute([$uid, $tool_id, $quantity, $borrow_date, $due_date]);
                    $borrowing_id = $pdo->lastInsertId();

                    log_activity('BORROW_REQUEST', "Pengajuan pinjam: {$tool['code']} - {$tool['name']} x$quantity (ID: $borrowing_id)");
                    $success = true;
                }
            }
        } catch (PDOException $e) {
            error_log("Borrow request error: " . $e->getMessage());
            $errors[] = 'Terjadi kesalahan database';
        }
    }
}

// Get available tools for dropdown
try {
    $pdo = get_pdo();
    $stmt = $pdo->query("
        SELECT id, code, name, quantity_available, category_id
        FROM tools
        WHERE quantity_available > 0
        ORDER BY code
    ");
    $tools = $stmt->fetchAll();

    // Get categories for grouping
    $stmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Borrow page tools error: " . $e->getMessage());
    $tools = [];
    $categories = [];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajukan Peminjaman | Pinjamin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; background: #f5f7fa; min-height: 100vh; }
        .layout { display: flex; min-height: 100vh; }
        .sidebar {
            width: 260px; background: #3498db; color: white; padding: 20px 0;
            position: fixed; height: 100vh; overflow-y: auto;
        }
        .sidebar .brand { padding: 0 20px 20px; border-bottom: 1px solid #4aa3db; margin-bottom: 20px; }
        .sidebar .brand h2 { font-size: 18px; font-weight: 600; }
        .sidebar .brand small { color: #a8d0e6; font-size: 12px; }
        .sidebar nav a { display: block; padding: 12px 20px; color: #d6eaf8; text-decoration: none; border-left: 3px solid transparent; transition: all 0.2s; }
        .sidebar nav a:hover, .sidebar nav a.active { background: #2980b9; color: white; border-left-color: #f39c12; }
        .sidebar nav a .icon { margin-right: 10px; }
        .sidebar .user-info { padding: 20px; border-top: 1px solid #4aa3db; margin-top: 20px; font-size: 13px; color: #a8d0e6; }
        .sidebar .user-info strong { color: white; display: block; margin-bottom: 4px; }
        .sidebar .user-info a { color: #f39c12; text-decoration: none; font-size: 12px; }
        .main { flex: 1; margin-left: 260px; padding: 30px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .header h1 { color: #2c3e50; font-size: 24px; }
        .btn { display: inline-block; padding: 10px 18px; border-radius: 6px; font-size: 14px; font-weight: 500; text-decoration: none; border: none; cursor: pointer; transition: all 0.2s; }
        .btn-primary { background: #3498db; color: white; }
        .btn-primary:hover { background: #2980b9; }
        .btn-secondary { background: #27ae60; color: white; }
        .btn-secondary:hover { background: #219a52; }
        .btn-outline { background: transparent; border: 1px solid #3498db; color: #3498db; }
        .btn-outline:hover { background: #3498db; color: white; }
        .card { background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden; }
        .card-header { padding: 20px; border-bottom: 1px solid #eee; }
        .card-header h3 { color: #2c3e50; font-size: 16px; }
        .card-body { padding: 20px; }
        .form-group { margin-bottom: 16px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 600px) { .form-row { grid-template-columns: 1fr; } }
        label { display: block; margin-bottom: 6px; font-weight: 500; color: #333; font-size: 14px; }
        input[type="text"], input[type="number"], input[type="date"], select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
        input:focus, select:focus { outline: none; border-color: #3498db; box-shadow: 0 0 0 3px rgba(52,152,219,0.1); }
        .tool-option { padding: 12px; border: 1px solid #eee; border-radius: 6px; margin-bottom: 8px; cursor: pointer; transition: all 0.2s; }
        .tool-option:hover { border-color: #3498db; background: #f8f9fa; }
        .tool-option.selected { border-color: #3498db; background: #e8f0fe; }
        .tool-option input { margin-right: 10px; }
        .tool-info { display: flex; flex-direction: column; gap: 4px; }
        .tool-name { font-weight: 600; color: #2c3e50; }
        .tool-meta { font-size: 13px; color: #666; }
        .tool-stock { color: #27ae60; font-weight: 600; }
        .tool-stock.low { color: #e74c3c; }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .tool-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
        @media (max-width: 768px) {
            .sidebar { position: static; width: 100%; }
            .main { margin-left: 0; }
            .layout { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="layout">
        <aside class="sidebar">
            <div class="brand">
                <h2>Pinjamin</h2>
                <small>Peminjam Panel</small>
            </div>
            <nav>
                <a href="dashboard_peminjam.php"><span class="icon">📊</span> Dashboard</a>
                <a href="borrow.php" class="active"><span class="icon">📝</span> Ajukan Pinjam</a>
                <a href="my_borrowings.php"><span class="icon">📋</span> Riwayat Pinjam</a>
            </nav>
            <div class="user-info">
                <strong><?= e(current_user()['name']) ?></strong>
                <span class="badge" style="background: #5dade2; color: #3498db;">Peminjam</span>
                <div style="margin-top: 10px;"><a href="logout.php">Logout</a></div>
            </div>
        </aside>

        <main class="main">
            <div class="header">
                <h1>Ajukan Peminjaman</h1>
                <a href="my_borrowings.php" class="btn btn-outline">Lihat Riwayat</a>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'success' ? 'success' : 'warning') ?>">
                    <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <ul style="margin: 0; padding-left: 20px;">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">Pengajuan peminjaman berhasil dikirim. Menunggu persetujuan petugas.</div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h3>Form Pengajuan Peminjaman</h3>
                </div>
                <div class="card-body">
                    <form method="POST" id="borrowForm" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                        <div class="form-group">
                            <label>Pilih Alat *</label>
                            <div class="tool-grid" id="toolGrid">
                                <?php foreach ($tools as $tool): ?>
                                    <label class="tool-option" data-tool-id="<?= $tool['id'] ?>">
                                        <input type="radio" name="tool_id" value="<?= $tool['id'] ?>" required>
                                        <div class="tool-info">
                                            <span class="tool-name"><?= e($tool['code']) ?> - <?= e($tool['name']) ?></span>
                                            <span class="tool-meta">Kategori: <?= e($categories[array_search($tool['category_id'], array_column($categories, 'id'))]['name'] ?? '-') ?></span>
                                            <span class="tool-stock <?= $tool['quantity_available'] < 3 ? 'low' : '' ?>">
                                                Stok tersedia: <?= $tool['quantity_available'] ?>
                                            </span>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                                <?php if (empty($tools)): ?>
                                    <p style="color: #999; grid-column: 1/-1; text-align: center; padding: 20px;">Tidak ada alat dengan stok tersedia</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="quantity">Jumlah Pinjam *</label>
                                <input type="number" id="quantity" name="quantity" required min="1" value="1">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="borrow_date">Tanggal Peminjaman *</label>
                                <input type="date" id="borrow_date" name="borrow_date" required value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="form-group">
                                <label for="due_date">Tanggal Jatuh Tempo *</label>
                                <input type="date" id="due_date" name="due_date" required min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                            </div>
                        </div>

                        <div style="margin-top: 20px; padding: 16px; background: #f8f9fa; border-radius: 6px; font-size: 13px; color: #666;">
                            <strong>Catatan:</strong>
                            <ul style="margin: 8px 0 0 20px; padding: 0;">
                                <li>Pengajuan akan status <strong>pending</strong> dan menunggu persetujuan petugas.</li>
                                <li>Stok <strong>belum berkurang</strong> saat pengajuan. Stok hanya berkurang setelah disetujui.</li>
                                <li>Tanggal jatuh tempo tidak boleh lebih awal dari tanggal peminjaman.</li>
                                <li>Jumlah pinjam tidak boleh melebihi stok tersedia.</li>
                            </ul>
                        </div>

                        <div style="margin-top: 24px; display: flex; gap: 10px;">
                            <button type="submit" class="btn btn-primary" id="submitBtn">Kirim Pengajuan</button>
                            <a href="my_borrowings.php" class="btn btn-outline">Lihat Riwayat</a>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Highlight selected tool option
        const toolOptions = document.querySelectorAll('.tool-option');
        toolOptions.forEach(opt => {
            opt.addEventListener('click', () => {
                toolOptions.forEach(o => o.classList.remove('selected'));
                opt.classList.add('selected');
                opt.querySelector('input').checked = true;
            });
        });

        // Auto-set due_date min based on borrow_date
        const borrowDateInput = document.getElementById('borrow_date');
        const dueDateInput = document.getElementById('due_date');
        borrowDateInput.addEventListener('change', () => {
            const nextDay = new Date(borrowDateInput.value);
            nextDay.setDate(nextDay.getDate() + 1);
            const minDue = nextDay.toISOString().split('T')[0];
            dueDateInput.min = minDue;
            if (dueDateInput.value && dueDateInput.value < minDue) {
                dueDateInput.value = minDue;
            }
        });

        // Initialize due_date min
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        dueDateInput.min = tomorrow.toISOString().split('T')[0];
    </script>
</body>
</html>