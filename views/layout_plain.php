<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0b1220">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title><?= e($title ?? 'Sign in') ?> · Herahakatha</title>
  <link rel="icon" href="<?= e(asset('icons/icon.svg')) ?>" type="image/svg+xml">
  <script>
    (function () {
      try {
        var t = localStorage.getItem('hh.theme');
        if (!t) t = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        document.documentElement.setAttribute('data-bs-theme', t);
      } catch (e) {}
    })();
  </script>
  <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="hh-body hh-plain">
<?= $content ?>
</body>
</html>
