<div class="hh-empty hh-empty--page">
  <div class="hh-empty__icon"><i class="bi bi-<?= ($code ?? 500) === 403 ? 'shield-lock' : (($code ?? 500) === 404 ? 'compass' : 'exclamation-triangle') ?>"></i></div>
  <h2><?= e($code ?? 500) ?></h2>
  <p><?= e($message ?? 'Something went wrong.') ?></p>
  <a class="btn btn-primary" href="<?= e(url('/')) ?>">Back to Today</a>
</div>
