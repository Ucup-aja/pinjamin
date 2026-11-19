<?php
// ============================================================
// PINJAMIN — Dashboard Petugas
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('petugas');

$user = current_user();
$flash = get_flash();

// Fetch dashboard stats
try {
    $pdo = get_pdo();
    
    $stat_pending = $pdo->query("SELECT COUNT(*) FROM borrowings WHERE status = 'pending'")->fetchColumn();
    $stat_return_pending = $pdo->query("SELECT COUNT(*) FROM returns WHERE status = 'pending'")->fetchColumn();
    $stat_approved_today = $pdo->query("SELECT COUNT(*) FROM borrowings WHERE status IN ('approved','partially_returned','returned') AND DATE(approved_at) = CURDATE()")->fetchColumn();
    $stat_confirmed_today = $pdo->query("SELECT COUNT(*) FROM returns WHERE status = 'confirmed' AND DATE(return_date) = CURDATE()")->fetchColumn();
    $stat_rejected_today = $pdo->query("SELECT COUNT(*) FROM returns WHERE status = 'rejected' AND DATE(return_date) = CURDATE()")->fetchColumn();
} catch (PDOException $e) {
    error_log("Dashboard petugas stats error: " . $e->getMessage());
    $stat_pending = $stat_return_pending = $stat_approved_today = $stat_confirmed_today = 0;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Petugas | Pinjamin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; background: #f5f7fa; min-height: 100vh; }
        .header {
            background: #27ae60; color: white; padding: 16px 24px;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 20px; font-weight: 600; }
        .user-info { display: flex; align-items: center; gap: 16px; font-size: 14px; }
        .badge { background: #2ecc71; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .container { max-width: 1000px; margin: 0 auto; padding: 30px 20px; }
        .welcome { margin-bottom: 30px; }
        .welcome h2 { color: #2c3e50; margin-bottom: 8px; }
        .welcome p { color: #666; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        .card {
            background: white; border-radius: 8px; padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border-left: 4px solid #27ae60;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,0.12); }
        .card h3 { color: #2c3e50; margin-bottom: 12px; font-size: 16px; display: flex; align-items: center; gap: 8px; }
        .card p { color: #666; font-size: 14px; line-height: 1.6; margin-bottom: 16px; }
        .card .status-badge {
            display: inline-block; padding: 4px 10px; border-radius: 20px;
            font-size: 12px; font-weight: 600; margin-right: 8px; margin-bottom: 8px;
        }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-approved { background: #d4edda; color: #155724; }
        .btn {
            display: inline-block; padding: 10px 18px; background: #27ae60; color: white;
            text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 500;
            border: none; cursor: pointer; transition: background 0.2s;
        }
        .btn:hover { background: #219a52; }
        .btn-secondary { background: #3498db; }
        .btn-secondary:hover { background: #2980b9; }
        .btn-outline { background: transparent; border: 1px solid #27ae60; color: #27ae60; }
        .btn-outline:hover { background: #27ae60; color: white; }
        .nav-links { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px; }
        .flash { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; }
        .flash-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .flash-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .flash-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; margin-right: 4px; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-approved { background: #d4edda; color: #155724; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .stat-card .label { color: #666; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-card .value { color: #2c3e50; font-size: 28px; font-weight: 700; margin-top: 4px; }
        .action-list { list-style: none; }
        .action-list li { padding: 10px 0; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
        .action-list li:last-child { border-bottom: none; }
        .action-info { display: flex; flex-direction: column; gap: 4px; }
        .action-tool { font-weight: 500; color: #2c3e50; }
        .action-user { font-size: 13px; color: #666; }
        .action-qty { background: #e8f5e9; color: #27ae60; padding: 4px 10px; border-radius: 20px; font-size: 13px; font-weight: 600; }
    </style>
</head>
<body>
    <header class="header">
        <h1>Pinjamin</h1>
        <div class="user-info">
            <span>Halo, <strong><?= e($user['name']) ?></strong></span>
            <span class="badge">Petugas</span>
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
            <h2>Dashboard Petugas</h2>
            <p>Setujui peminjaman, konfirmasi pengembalian, dan cetak laporan.</p>
        </div>

        <div class="stats">
            <div class="stat-card">
                <div class="label">Peminjaman Pending</div>
                <div class="value"><?= $stat_pending ?></div>
                <div style="font-size: 11px; color: #999; margin-top: 4px;">(butuh persetujuan)</div>
            </div>
            <div class="stat-card">
                <div class="label">Pengembalian Pending</div>
                <div class="value"><?= $stat_return_pending ?></div>
                <div style="font-size: 11px; color: #999; margin-top: 4px;">(butuh konfirmasi)</div>
            </div>
            <div class="stat-card">
                <div class="label">Disetujui Hari Ini</div>
                <div class="value"><?= $stat_approved_today ?></div>
                <div style="font-size: 11px; color: #999; margin-top: 4px;">(borrow approved)</div>
            </div>
            <div class="stat-card">
                <div class="label">Dikonfirmasi Hari Ini</div>
                <div class="value"><?= $stat_confirmed_today ?></div>
                <div style="font-size: 11px; color: #999; margin-top: 4px;">(return confirmed)</div>
            </div>
            <div class="stat-card">
                <div class="label">Ditolak Hari Ini</div>
                <div class="value"><?= $stat_rejected_today ?></div>
                <div style="font-size: 11px; color: #999; margin-top: 4px;">(return rejected)</div>
            </div>
        </div>

        <div class="grid">
            <div class="card">
                <h3>✅ Setujui Peminjaman</h3>
                <p>Status borrowing: <span class="badge status-pending">pending</span> → <span class="badge status-approved">approved</span> (stok berkurang otomatis via SP).</p>
                <div class="nav-links">
                    <a href="approvals.php" class="btn">Lihat Pengajuan</a>
                </div>
            </div>

            <div class="card">
                <h3>🔄 Konfirmasi Pengembalian</h3>
                <p>Status return: <span class="badge status-pending">pending</span> → <span class="badge status-approved">confirmed</span> (stok bertambah, denda via fn_calculate_fine).</p>
                <div class="nav-links">
                    <a href="returns.php" class="btn">Lihat Pengembalian</a>
                </div>
            </div>

            <div class="card">
                <h3>📊 Cetak Laporan</h3>
                <p>Laporan peminjaman, pengembalian, dan rekap denda dengan filter tanggal & status.</p>
                <div class="nav-links">
                    <a href="reports.php" class="btn btn-secondary">Lihat Laporan</a>
                </div>
            </div>
        </div>
    </div>

    </body>
</html>