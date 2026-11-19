<?php
// ============================================================
// PINJAMIN — Ajukan Pengembalian (Peminjam)
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('peminjam');

$flash = get_flash();
$errors = [];
$success = false;

$uid = current_user()['id'];

// Handle POST - submit return request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token CSRF tidak valid';
    } else {
        try {
            $pdo = get_pdo();

            $borrowing_id = (int)($_POST['borrowing_id'] ?? 0);
            $quantity = (int)($_POST['quantity'] ?? 0);

            if ($borrowing_id <= 0) {
                $errors[] = 'ID peminjaman tidak valid';
            }
            if ($quantity <= 0) {
                $errors[] = 'Jumlah kembali harus bilangan bulat positif';
            }

            if (empty($errors)) {
                // Verify borrowing belongs to user and is in returnable status
                $stmt = $pdo->prepare("
                    SELECT b.id, b.quantity, b.status, b.due_date,
                           t.code, t.name as tool_name,
                           COALESCE(SUM(r.quantity), 0) as returned_qty
                    FROM borrowings b
                    JOIN tools t ON t.id = b.tool_id
                    LEFT JOIN returns r ON r.borrowing_id = b.id AND r.status = 'confirmed'
                    WHERE b.id = ? AND b.user_id = ?
                    GROUP BY b.id
                ");
                $stmt->execute([$borrowing_id, $uid]);
                $borrowing = $stmt->fetch();

                if (!$borrowing) {
                    $errors[] = 'Peminjaman tidak ditemukan atau bukan milik Anda';
                } elseif (!in_array($borrowing['status'], ['approved', 'partially_returned'], true)) {
                    $errors[] = 'Peminjaman tidak dapat dikembalikan (status: ' . $borrowing['status'] . ')';
                } else {
                    $max_returnable = $borrowing['quantity'] - $borrowing['returned_qty'];
                    if ($quantity > $max_returnable) {
                        $errors[] = "Jumlah melebihi yang dapat dikembalikan. Maksimum: $max_returnable";
                    } else {
                        // Insert return request (status = 'pending' per schema)
                        $stmt = $pdo->prepare("
                            INSERT INTO returns (borrowing_id, requested_by, quantity, status)
                            VALUES (?, ?, ?, 'pending')
                        ");
                        $stmt->execute([$borrowing_id, $uid, $quantity]);
                        $return_id = $pdo->lastInsertId();

                        log_activity('RETURN_REQUEST', "Ajukan pengembalian: {$borrowing['code']} - {$borrowing['tool_name']} x$quantity (Return ID: $return_id, Borrowing ID: $borrowing_id)");
                        $success = true;
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("Return request error: " . $e->getMessage());
            $errors[] = 'Terjadi kesalahan database';
        }
    }
}

// Get active borrowings (approved or partially_returned) for this user
try {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("
        SELECT b.id, b.quantity, b.borrow_date, b.due_date, b.status, b.approved_at,
               t.code, t.name as tool_name, c.name as category_name,
               COALESCE(SUM(r.quantity), 0) as returned_qty
        FROM borrowings b
        JOIN tools t ON t.id = b.tool_id
        JOIN categories c ON c.id = t.category_id
        LEFT JOIN returns r ON r.borrowing_id = b.id AND r.status = 'confirmed'
        WHERE b.user_id = ? AND b.status IN ('approved', 'partially_returned')
        GROUP BY b.id
        ORDER BY b.due_date ASC
    ");
    $stmt->execute([$uid]);
    $borrowings = $stmt->fetchAll();

    // Add computed field: remaining to return
    foreach ($borrowings as &$b) {
        $b['remaining'] = $b['quantity'] - $b['returned_qty'];
        $b['is_overdue'] = $b['status'] !== 'returned' && date('Y-m-d') > $b['due_date'];
    }
} catch (PDOException $e) {
    error_log("Return request page error: " . $e->getMessage());
    $borrowings = [];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajukan Pengembalian | Pinjamin</title>
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
        .btn-sm { padding: 6px 12px; font-size: 13px; }
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
        .borrowing-card { border: 1px solid #eee; border-radius: 8px; padding: 16px; margin-bottom: 16px; background: white; transition: all 0.2s; }
        .borrowing-card:hover { border-color: #3498db; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .borrowing-card.overdue { border-left: 4px solid #e74c3c; }
        .borrowing-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; flex-wrap: wrap; gap: 8px; }
        .borrowing-title { font-weight: 600; color: #2c3e50; font-size: 15px; }
        .borrowing-meta { font-size: 13px; color: #666; display: flex; gap: 16px; flex-wrap: wrap; margin-top: 8px; }
        .borrowing-meta span { background: #f8f9fa; padding: 4px 10px; border-radius: 4px; }
        .borrowing-meta .overdue { background: #fdeaea; color: #e74c3c; font-weight: 600; }
        .qty-display { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin: 12px 0; }
        .qty-box { display: flex; align-items: center; gap: 8px; background: #f8f9fa; padding: 8px 12px; border-radius: 6px; font-size: 14px; }
        .qty-label { color: #666; font-size: 13px; }
        .qty-value { font-weight: 600; color: #2c3e50; }
        .qty-value.pending { color: #f39c12; }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .empty-state { text-align: center; padding: 60px 20px; color: #999; }
        .empty-state h3 { margin-bottom: 8px; color: #666; }
        .note-box { background: #f8f9fa; border: 1px solid #eee; border-radius: 6px; padding: 16px; margin-top: 16px; font-size: 13px; color: #666; }
        .note-box strong { color: #333; }
        .note-box ul { margin: 8px 0 0 20px; padding: 0; }
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
                <a href="borrow.php"><span class="icon">📝</span> Ajukan Pinjam</a>
                <a href="my_borrowings.php"><span class="icon">📋</span> Riwayat Pinjam</a>
                <a href="return_request.php" class="active"><span class="icon">🔄</span> Ajukan Kembali</a>
            </nav>
            <div class="user-info">
                <strong><?= e(current_user()['name']) ?></strong>
                <span class="badge" style="background: #5dade2; color: #3498db;">Peminjam</span>
                <div style="margin-top: 10px;"><a href="logout.php">Logout</a></div>
            </div>
        </aside>

        <main class="main">
            <div class="header">
                <h1>Ajukan Pengembalian</h1>
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
                <div class="alert alert-success">Pengajuan pengembalian berhasil dikirim. Menunggu konfirmasi petugas.</div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h3>Peminjaman Aktif (Dapat Dikembalikan)</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($borrowings)): ?>
                        <div class="empty-state">
                            <h3>Tidak ada peminjaman aktif</h3>
                            <p>Semua peminjaman sudah dikembalikan atau ditolak</p>
                            <a href="borrow.php" class="btn btn-primary" style="margin-top: 16px;">Ajukan Pinjam Baru</a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($borrowings as $b): ?>
                            <div class="borrowing-card<?= $b['is_overdue'] ? ' overdue' : '' ?>">
                                <div class="borrowing-header">
                                    <span class="borrowing-title"><?= e($b['code']) ?> - <?= e($b['tool_name']) ?></span>
                                    <span class="badge badge-<?= $b['status'] === 'approved' ? 'approved' : 'partial' ?>">
                                        <?= $b['status'] === 'approved' ? 'Disetujui' : 'Sebagian Kembali' ?>
                                    </span>
                                </div>
                                <div class="borrowing-meta">
                                    <span>Kategori: <?= e($b['category_name']) ?></span>
                                    <span>Pinjam: <?= date('d/m/Y', strtotime($b['borrow_date'])) ?></span>
                                    <span>Jatuh Tempo: <?= date('d/m/Y', strtotime($b['due_date'])) ?>
                                        <?php if ($b['is_overdue']): ?>
                                            <span class="overdue">TERLAMBAT</span>
                                        <?php endif; ?>
                                    </span>
                                    <span>Disetujui: <?= $b['approved_at'] ? date('d/m/Y H:i', strtotime($b['approved_at'])) : '-' ?></span>
                                </div>
                                <div class="qty-display">
                                    <div class="qty-box">
                                        <span class="qty-label">Dipinjam:</span>
                                        <span class="qty-value"><?= $b['quantity'] ?></span>
                                    </div>
                                    <div class="qty-box">
                                        <span class="qty-label">Sudah Kembali:</span>
                                        <span class="qty-value"><?= $b['returned_qty'] ?></span>
                                    </div>
                                    <div class="qty-box">
                                        <span class="qty-label">Sisa:</span>
                                        <span class="qty-value"><?= $b['remaining'] ?></span>
                                    </div>
                                </div>
                                <?php if ($b['remaining'] > 0): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="borrowing_id" value="<?= $b['id'] ?>">
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label for="qty_<?= $b['id'] ?>">Jumlah Kembali *</label>
                                                <input type="number" id="qty_<?= $b['id'] ?>" name="quantity" required min="1" max="<?= $b['remaining'] ?>" value="1" style="width: 100%;">
                                            </div>
                                            <div class="form-group" style="display: flex; align-items: flex-end;">
                                                <button type="submit" class="btn btn-secondary" style="width: 100%;">Ajukan Kembali</button>
                                            </div>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <div style="color: #27ae60; font-weight: 600; padding: 8px; background: #d5f5e3; border-radius: 6px; text-align: center;">
                                        ✓ Semua unit sudah dikembalikan
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="note-box">
                <strong>Catatan:</strong>
                <ul>
                    <li>Hanya peminjaman dengan status <strong>Disetujui</strong> atau <strong>Sebagian Kembali</strong> yang dapat diajukan pengembalian.</li>
                    <li>Pengajuan pengembalian status <strong>pending</strong> dan menunggu konfirmasi petugas.</li>
                    <li>Stok <strong>belum bertambah</strong> saat pengajuan. Stok bertambah setelah petugas konfirmasi.</li>
                    <li>Dapat mengajukan pengembalian parsial (sebagian) atau penuh.</li>
                    <li>Jika terlambat, petugas akan menghitung denda saat konfirmasi.</li>
                </ul>
            </div>
        </main>
    </div>
</body>
</html>