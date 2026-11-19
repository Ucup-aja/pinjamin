<?php
// ============================================================
// PINJAMIN — Pengajuan Peminjaman (Peminjam)
// ============================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('peminjam');

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
$pageTitle = 'Ajukan Peminjaman';
$currentPage = 'borrow.php';

if ($success) {
    $successMessage = 'Pengajuan peminjaman berhasil dikirim. Menunggu persetujuan petugas.';
}

include __DIR__ . '/../includes/partials/head.php';
include __DIR__ . '/../includes/partials/sidebar.php';
include __DIR__ . '/../includes/partials/topbar.php';
?>

<main class="main" role="main">
  <div class="page-header">
    <h1>Ajukan Peminjaman</h1>
    <div class="page-actions">
      <a href="<?= base_url('my_borrowings.php') ?>" class="btn btn-secondary">Lihat Riwayat</a>
    </div>
  </div>

  <?php include __DIR__ . '/../includes/partials/flash.php'; ?>

  <div class="card">
    <div class="card-header">
      <h3>Form Pengajuan Peminjaman</h3>
    </div>
    <div class="card-body">
      <form method="POST" id="borrowForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div class="form-group">
          <span class="form-label">Pilih Alat <span class="required">*</span></span>
          <div class="tool-grid" id="toolGrid">
            <?php foreach ($tools as $tool): ?>
              <label class="tool-option card" data-tool-id="<?= $tool['id'] ?>">
                <input type="radio" name="tool_id" value="<?= $tool['id'] ?>" required>
                <span class="tool-info">
                  <span class="tool-name"><?= e($tool['code']) ?> - <?= e($tool['name']) ?></span>
                  <span class="text-muted">Kategori: <?= e($categories[array_search($tool['category_id'], array_column($categories, 'id'))]['name'] ?? '-') ?></span>
                  <span class="tool-stock <?= $tool['quantity_available'] < 3 ? 'tool-stock-low' : '' ?>">
                    Stok tersedia: <?= $tool['quantity_available'] ?>
                  </span>
                </span>
              </label>
            <?php endforeach; ?>
            <?php if (empty($tools)): ?>
              <p class="text-muted">Tidak ada alat dengan stok tersedia</p>
            <?php endif; ?>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="quantity">Jumlah Pinjam <span class="required">*</span></label>
          <input class="form-input" type="number" id="quantity" name="quantity" required min="1" value="1">
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="borrow_date">Tanggal Peminjaman <span class="required">*</span></label>
            <input class="form-input" type="date" id="borrow_date" name="borrow_date" required value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>">
          </div>
          <div class="form-group">
            <label class="form-label" for="due_date">Tanggal Jatuh Tempo <span class="required">*</span></label>
            <input class="form-input" type="date" id="due_date" name="due_date" required min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
          </div>
        </div>

        <div class="alert alert-warning" role="note">
          <div>
            <strong>Catatan:</strong>
            <ul>
              <li>Pengajuan akan status <strong>menunggu persetujuan</strong> dan menunggu persetujuan petugas.</li>
              <li>Stok <strong>belum berkurang</strong> saat pengajuan. Stok hanya berkurang setelah disetujui.</li>
              <li>Tanggal jatuh tempo tidak boleh lebih awal dari tanggal peminjaman.</li>
              <li>Jumlah pinjam tidak boleh melebihi stok tersedia.</li>
            </ul>
          </div>
        </div>

        <div class="page-actions">
          <button type="submit" class="btn btn-primary" id="submitBtn">Kirim Pengajuan</button>
          <a href="<?= base_url('my_borrowings.php') ?>" class="btn btn-secondary">Lihat Riwayat</a>
        </div>
      </form>
    </div>
  </div>
</main>

<script>
  // Highlight selected tool option
  const toolOptions = document.querySelectorAll('.tool-option');
  toolOptions.forEach(opt => {
    opt.addEventListener('click', () => {
      toolOptions.forEach(o => o.classList.remove('selected'));
      opt.classList.add('selected');
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

<?php include __DIR__ . '/../includes/partials/footer.php'; ?>