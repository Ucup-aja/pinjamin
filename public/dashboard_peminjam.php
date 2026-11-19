<?php
// ============================================================
// PINJAMIN — Dashboard Peminjam
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('peminjam');

$user = current_user();
$flash = get_flash();

$uid = $user['id'];

// Fetch dashboard stats
try {
    $pdo = get_pdo();
    
    // Alat yang sedang dipinjam (approved + partially_returned)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM borrowings WHERE user_id = ? AND status IN ('approved','partially_returned')");
    $stmt->execute([$uid]);
    $stat_borrowed = $stmt->fetchColumn();
    
    // Menunggu persetujuan
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrowings WHERE user_id = ? AND status = 'pending'");
    $stmt->execute([$uid]);
    $stat_pending = $stmt->fetchColumn();
    
    // Menunggu konfirmasi pengembalian (status 'pending' di tabel returns)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM returns WHERE requested_by = ? AND status = 'pending'");
    $stmt->execute([$uid]);
    $stat_return_pending = $stmt->fetchColumn();
    
    // Total denda (confirmed returns)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(fine_amount), 0) FROM returns WHERE requested_by = ? AND status = 'confirmed'");
    $stmt->execute([$uid]);
    $stat_total_fine = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Dashboard peminjam stats error: " . $e->getMessage());
    $stat_borrowed = $stat_pending = $stat_return_pending = $stat_total_fine = 0;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Peminjam | Pinjamin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; background: #f5f7fa; min-height: 100vh; }
        .header {
            background: #3498db; color: white; padding: 16px 24px;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 20px; font-weight: 600; }
        .user-info { display: flex; align-items: center; gap: 16px; font-size: 14px; }
        .badge { background: #5dade2; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .container { max-width: 1000px; margin: 0 auto; padding: 30px 20px; }
        .welcome { margin-bottom: 30px; }
        .welcome h2 { color: #2c3e50; margin-bottom: 8px; }
        .welcome p { color: #666; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        .card {
            background: white; border-radius: 8px; padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border-left: 4px solid #3498db;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,0.12); }
        .card h3 { color: #2c3e50; margin-bottom: 12px; font-size: 16px; display: flex; align-items: center; gap: 8px; }
        .card p { color: #666; font-size: 14px; line-height: 1.6; margin-bottom: 16px; }
        .btn {
            display: inline-block; padding: 10px 18px; background: #3498db; color: white;
            text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 500;
            border: none; cursor: pointer; transition: background 0.2s;
        }
        .btn:hover { background: #2980b9; }
        .btn-secondary { background: #27ae60; }
        .btn-secondary:hover { background: #219a52; }
        .btn-outline { background: transparent; border: 1px solid #3498db; color: #3498db; }
        .btn-outline:hover { background: #3498db; color: white; }
        .nav-links { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px; }
        .flash { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; }
        .flash-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .flash-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .flash-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; margin-right: 4px; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-approved { background: #d4edda; color: #155724; }
        .badge-partial { background: #d6eaf8; color: #3498db; }
        .badge-returned { background: #d5f5e3; color: #27ae60; }
        .badge-rejected { background: #f8d7da; color: #721c24; }
        .badge-confirmed { background: #d5f5e3; color: #27ae60; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .stat-card .label { color: #666; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-card .value { color: #2c3e50; font-size: 28px; font-weight: 700; margin-top: 4px; }
        .tool-card { border: 1px solid #eee; border-radius: 8px; padding: 16px; margin-bottom: 16px; background: white; }
        .tool-card:last-child { margin-bottom: 0; }
        .tool-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; }
        .tool-name { font-weight: 600; color: #2c3e50; font-size: 15px; }
        .tool-code { color: #666; font-size: 13px; background: #f0f0f0; padding: 2px 8px; border-radius: 4px; }
        .tool-desc { color: #666; font-size: 13px; margin-bottom: 12px; }
        .tool-stock { display: flex; gap: 16px; font-size: 13px; }
        .stock-item { display: flex; align-items: center; gap: 6px; }
        .stock-label { color: #666; }
        .stock-value { font-weight: 600; color: #2c3e50; }
        .stock-available { color: #27ae60; }
        .stock-low { color: #e74c3c; }
        .tool-actions { margin-top: 12px; padding-top: 12px; border-top: 1px solid #eee; }
    </style>
</head>
<body>
    <header class="header">
        <h1>Pinjamin</h1>
        <div class="user-info">
            <span>Halo, <strong><?= e($user['name']) ?></strong></span>
            <span class="badge">Peminjam</span>
            <a href="<?= base_url('logout.php') ?>" class="btn btn-outline" style="padding: 8px 14px; font-size: 13px;">Logout</a>
        </div>
    </header>

    <div class="container">
        <?php if ($flash): ?>
            <div class="flash flash-<?= $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'success' ? 'success' : 'warning') ?>">
                <?= e($flash['message']) ?>
            </div>
        <?php endif; ?>

        <div class="welcome">
            <h2>Dashboard Peminjam</h2>
            <p>Lihat alat tersedia, ajukan peminjaman, dan kelola pengembalian.</p>
        </div>

        <div class="stats">
            <div class="stat-card">
                <div class="label">Alat Dipinjam</div>
                <div class="value"><?= $stat_borrowed ?></div>
                <div style="font-size: 11px; color: #999; margin-top: 4px;">(approved + sebagian kembali)</div>
            </div>
            <div class="stat-card">
                <div class="label">Menunggu Persetujuan</div>
                <div class="value"><?= $stat_pending ?></div>
                <div style="font-size: 11px; color: #999; margin-top: 4px;">(status pending)</div>
            </div>
            <div class="stat-card">
                <div class="label">Menunggu Konfirmasi</div>
                <div class="value"><?= $stat_return_pending ?></div>
                <div style="font-size: 11px; color: #999; margin-top: 4px;">(return status pending)</div>
            </div>
            <div class="stat-card">
                <div class="label">Total Denda</div>
                <div class="value">Rp <?= number_format($stat_total_fine, 0, ',', '.') ?></div>
                <div style="font-size: 11px; color: #999; margin-top: 4px;">(dari return confirmed)</div>
            </div>
        </div>

        <div class="grid">
            <div class="card">
                <h3>🔧 Daftar Alat Tersedia</h3>
                <p>Lihat alat yang dapat dipinjam beserta stoknya.</p>
                <div class="nav-links">
                    <a href="borrow.php" class="btn">Ajukan Peminjaman</a>
                </div>
            </div>

            <div class="card">
                <h3>📋 Riwayat Peminjaman Saya</h3>
                <p>Status: <span class="badge badge-pending">pending</span> <span class="badge badge-approved">approved</span> <span class="badge badge-partial">partially_returned</span> <span class="badge badge-returned">returned</span> <span class="badge badge-rejected">rejected</span></p>
                <div class="nav-links">
                    <a href="my_borrowings.php" class="btn">Lihat Riwayat</a>
                </div>
            </div>

            <div class="card">
                <h3>🔄 Ajukan Pengembalian</h3>
                <p>Kembalikan alat parsial/penuh dari peminjaman <strong>approved</strong> atau <strong>partially_returned</strong>. Status return: <strong>pending</strong>.</p>
                <div class="nav-links">
                    <a href="return_request.php" class="btn btn-secondary">Ajukan Pengembalian</a>
                </div>
            </div>

            <div class="card">
                <h3>📄 Riwayat Pengembalian Saya</h3>
                <p>Status return: <span class="badge badge-pending">pending</span> <span class="badge badge-confirmed">confirmed</span> <span class="badge badge-rejected">rejected</span> — detail denda & tanggal kembali.</p>
                <div class="nav-links">
                    <a href="my_returns.php" class="btn">Lihat Riwayat</a>
                </div>
            </div>
        </div>
    </div>

    </body>
</html>