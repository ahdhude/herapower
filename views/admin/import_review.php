<?php /** @var array $counts @var array $list */ ?>
<a class="btn btn-link px-0 mb-2" href="<?= e(url('/admin/import')) ?>"><i class="bi bi-arrow-left"></i> Imports</a>
<p class="hh-lead">Everything the importer would not guess. Open items are cells that weren't numbers (nothing was imported for them) and values that look like a missed decimal point (imported, but flagged).</p>
<?php require __DIR__ . '/../partials/import_issues.php'; ?>
