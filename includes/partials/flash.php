<?php
// ============================================================
// PINJAMIN — Flash Messages & Validation Errors
// Requires: get_flash(), $errors (array), $success (bool)
// If $flash already set (page pre-fetched), use it; else fetch once.
// Optional: $successMessage (string) for custom success text.
// ============================================================
$flash = $flash ?? get_flash();
$errors = $errors ?? [];
$success = $success ?? false;
$successMessage = $successMessage ?? 'Operasi berhasil';
?>
<?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'success' ? 'success' : 'warning') ?>" role="alert">
    <?= e($flash['message']) ?>
  </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
  <div class="alert alert-error" role="alert">
    <ul style="margin: 0; padding-left: 20px;">
      <?php foreach ($errors as $err): ?>
        <li><?= e($err) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($success): ?>
  <div class="alert alert-success" role="alert"><?= e($successMessage) ?></div>
<?php endif; ?>