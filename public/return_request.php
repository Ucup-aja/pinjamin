<?php
// ============================================================
// PINJAMIN — Ajukan Pengembalian (Peminjam)
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('peminjam');

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
$pageTitle = 'Ajukan Pengembalian';
$currentPage = 'return_request.php';

if ($success) {
    $successMessage = 'Pengajuan pengembalian berhasil dikirim. Menunggu konfirmasi petugas.';
}

include __DIR__ . '/../includes/partials/head.php';
include __DIR__ . '/../includes/partials/sidebar.php';
include __DIR__ . '/../includes/partials/topbar.php';
?>

<main class="main" role="main">
  <div class="page-header">
    <h1>Ajukan Pengembalian</h1>
    <div class="page-actions">
      <a href="<?= base_url('my_borrowings.php') ?>" class="btn btn-secondary">Lihat Riwayat</a>
    </div>
  </div>

  <?php include __DIR__ . '/../includes/partials/flash.php'; ?>

  <div class="card mb-6">
    <div class="card-header">
      <h3>Peminjaman Aktif (Dapat Dikembalikan)</h3>
    </div>
    <div class="card-body">
      <?php if (empty($borrowings)): ?>
        <div class="empty-state">
          <h3>Tidak ada peminjaman aktif</h3>
          <p>Semua peminjaman sudah dikembalikan atau ditolak</p>
          <a href="<?= base_url('borrow.php') ?>" class="btn btn-primary">Ajukan Pinjam Baru</a>
        </div>
      <?php else: ?>
        <?php foreach ($borrowings as $b): ?>
          <div class="card mb-6">
            <div class="card-header">
              <h3><?= e($b['code']) ?> - <?= e($b['tool_name']) ?></h3>
              <div class="page-actions">
                <?php if ($b['is_overdue']): ?>
                  <span class="badge badge-danger">Terlambat</span>
                <?php endif; ?>
                <span class="badge <?= $b['status'] === 'approved' ? 'badge-approved' : 'badge-partial' ?>">
                  <?= $b['status'] === 'approved' ? 'Disetujui' : 'Dikembalikan Sebagian' ?>
                </span>
              </div>
            </div>
            <div class="card-body">
              <p class="text-muted">
                Kategori: <?= e($b['category_name']) ?> &middot;
                Pinjam: <?= date('d/m/Y', strtotime($b['borrow_date'])) ?> &middot;
                Jatuh Tempo: <?= date('d/m/Y', strtotime($b['due_date'])) ?> &middot;
                Disetujui: <?= $b['approved_at'] ? date('d/m/Y H:i', strtotime($b['approved_at'])) : '-' ?>
              </p>

              <div class="stats-grid mb-6">
                <div class="stat-card">
                  <div class="stat-label">Dipinjam</div>
                  <div class="stat-value"><?= $b['quantity'] ?></div>
                </div>
                <div class="stat-card">
                  <div class="stat-label">Sudah Kembali</div>
                  <div class="stat-value"><?= $b['returned_qty'] ?></div>
                </div>
                <div class="stat-card">
                  <div class="stat-label">Sisa</div>
                  <div class="stat-value"><?= $b['remaining'] ?></div>
                </div>
              </div>

              <?php if ($b['remaining'] > 0): ?>
                <form method="POST">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="borrowing_id" value="<?= $b['id'] ?>">
                  <div class="form-group">
                    <label class="form-label" for="qty_<?= $b['id'] ?>">Jumlah Kembali <span class="required">*</span></label>
                    <input class="form-input" type="number" id="qty_<?= $b['id'] ?>" name="quantity" required min="1" max="<?= $b['remaining'] ?>" value="1">
                    <span class="form-hint">Maksimal <?= $b['remaining'] ?> unit</span>
                  </div>
                  <button type="submit" class="btn btn-primary">Ajukan Kembali</button>
                </form>
              <?php else: ?>
                <div class="alert alert-success" role="status">Semua unit sudah dikembalikan</div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="alert alert-warning" role="note">
    <div>
      <strong>Catatan:</strong>
      <ul>
        <li>Hanya peminjaman dengan status <strong>Disetujui</strong> atau <strong>Dikembalikan Sebagian</strong> yang dapat diajukan pengembalian.</li>
        <li>Pengajuan pengembalian status <strong>menunggu konfirmasi</strong> dan menunggu konfirmasi petugas.</li>
        <li>Stok <strong>belum bertambah</strong> saat pengajuan. Stok bertambah setelah petugas konfirmasi.</li>
        <li>Dapat mengajual pengembalian parsial (sebagian) atau penuh.</li>
        <li>Jika terlambat, petugas akan menghitung denda saat konfirmasi.</li>
      </ul>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../includes/partials/footer.php'; ?>