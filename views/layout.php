<?php
/** @var string $content */
use App\Core\Auth;

$user   = Auth::user();
$active = $active ?? '';
$title  = $title ?? 'Herahakatha';
$role   = $user['role'] ?? '';
$canEnter = Auth::can('enter');
$isAdmin  = Auth::can('admin');
$flash  = flash();
$today  = now();

$nav = [];
$nav[] = ['today', 'Today', 'house-door', '/'];
// Everyone can open the readings; viewers get them read-only.
$nav[] = $canEnter ? ['entry', 'Enter', 'pencil-square', '/entry'] : ['entry', 'Readings', 'journal-text', '/entry'];
$nav[] = ['reports', 'Reports', 'bar-chart-line', '/reports'];

$entryLinks = [
    ['Feeder Reading 1', '/entry/feeders/1', 'lightning-charge'],
    ['Feeder Reading 2', '/entry/feeders/2', 'lightning-charge'],
    ['Solar', '/entry/solar', 'sun'],
    ['Daily meters', '/entry/daily', 'speedometer2'],
    ['Genset log', '/entry/genset', 'fuel-pump-diesel'],
];
$adminLinks = [
    ['Meters', '/admin/meters', 'diagram-3'],
    ['Users', '/admin/users', 'people'],
    ['Settings', '/admin/settings', 'sliders'],
    ['Audit log', '/admin/audit', 'clock-history'],
    ['Import history', '/admin/import', 'upload'],
];
$here = (new App\Core\Request())->path();
?><!doctype html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0b1220">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <title><?= e($title) ?> · Herahakatha</title>
  <link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
  <link rel="icon" href="<?= e(asset('icons/icon.svg')) ?>" type="image/svg+xml">
  <link rel="apple-touch-icon" href="<?= e(asset('icons/icon.svg')) ?>">
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
  <script>window.HH = <?= json_encode(['base' => rtrim((string) App\Core\Config::get('APP_BASE', ''), '/'), 'role' => $role, 'user' => $user['name'] ?? '', 'tz' => 'Indian/Maldives', 'now' => $today->format('Y-m-d\TH:i:s')], JSON_UNESCAPED_SLASHES) ?>;</script>
  <script src="<?= e(asset('js/queue.js')) ?>" defer></script>
  <script src="<?= e(asset('js/app.js')) ?>" defer></script>
  <script src="<?= e(asset('vendor/chart.umd.min.js')) ?>" defer></script>
  <script src="<?= e(asset('vendor/alpine.min.js')) ?>" defer></script>
</head>
<body class="hh-body">
<div class="hh-shell">

  <!-- Sidebar (PC) -->
  <aside class="hh-sidebar d-none d-lg-flex" aria-label="Main">
    <a class="hh-brand" href="<?= e(url('/')) ?>">
      <span class="hh-brand__mark"><i class="bi bi-lightning-charge-fill"></i></span>
      <span class="hh-brand__text"><strong>Herahakatha</strong><small>Canareef powerhouse</small></span>
    </a>

    <nav class="hh-side-nav">
      <?php foreach ($nav as [$key, $label, $ic, $href]): ?>
        <a class="hh-side-link <?= $active === $key ? 'is-active' : '' ?>" href="<?= e(url($href)) ?>">
          <i class="bi bi-<?= e($ic) ?>"></i><span><?= e($label) ?></span>
        </a>
        <?php if ($key === 'entry' && $active === 'entry'): ?>
          <div class="hh-side-sub">
            <?php foreach ($entryLinks as [$l, $h, $i]): ?>
              <a class="<?= str_starts_with($here, $h) ? 'is-active' : '' ?>" href="<?= e(url($h)) ?>"><i class="bi bi-<?= e($i) ?>"></i><?= e($l) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>

      <?php if ($isAdmin): ?>
        <div class="hh-side-heading">Admin</div>
        <?php foreach ($adminLinks as [$l, $h, $i]): ?>
          <a class="hh-side-link hh-side-link--sm <?= str_starts_with($here, $h) ? 'is-active' : '' ?>" href="<?= e(url($h)) ?>">
            <i class="bi bi-<?= e($i) ?>"></i><span><?= e($l) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </nav>

    <div class="hh-side-foot">
      <div class="hh-usercard">
        <span class="hh-avatar"><?= e(mb_strtoupper(mb_substr($user['name'] ?? '?', 0, 1))) ?></span>
        <span class="hh-usercard__text"><strong><?= e($user['name'] ?? '') ?></strong><small><?= e(ucfirst($role)) ?></small></span>
      </div>
      <div class="d-flex gap-2">
        <button type="button" class="hh-icon-btn" data-theme-toggle aria-label="Switch light or dark theme"><i class="bi bi-moon-stars"></i></button>
        <form method="post" action="<?= e(url('/logout')) ?>" class="flex-fill"><?= csrf_field() ?>
          <button class="hh-side-signout" type="submit"><i class="bi bi-box-arrow-right"></i> Sign out</button>
        </form>
      </div>
    </div>
  </aside>

  <!-- Main column -->
  <div class="hh-main">
    <header class="hh-topbar">
      <div class="hh-topbar__title">
        <h1><?= e($title) ?></h1>
        <p><?= e($today->format('l, j F')) ?><span class="d-none d-sm-inline"> · <span data-clock><?= e($today->format('H:i')) ?></span></span></p>
      </div>
      <div class="hh-topbar__actions">
        <div x-data="syncPill" x-cloak>
          <button type="button" class="hh-sync" :class="{'is-offline': !online, 'is-pending': count > 0}" x-show="!online || count > 0" @click="flush()" :title="online ? 'Send now' : 'No connection'">
            <i class="bi" :class="online ? 'bi-arrow-repeat' : 'bi-cloud-slash'"></i>
            <span x-text="!online ? (count ? 'Offline · ' + count + ' waiting' : 'Offline') : count + ' waiting to send'"></span>
          </button>
        </div>
        <button type="button" class="hh-icon-btn d-lg-none" data-theme-toggle aria-label="Switch light or dark theme"><i class="bi bi-moon-stars"></i></button>
      </div>
    </header>

    <main class="hh-content" id="main">
      <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?> hh-flash" role="status">
          <i class="bi bi-<?= $flash['type'] === 'error' ? 'exclamation-octagon' : 'check-circle' ?>"></i> <?= e($flash['message']) ?>
        </div>
      <?php endif; ?>
      <?= $content ?>
    </main>
  </div>

  <!-- Bottom nav (phone) -->
  <nav class="hh-bottomnav d-lg-none" aria-label="Main">
    <?php foreach ($nav as [$key, $label, $ic, $href]): ?>
      <a class="<?= $active === $key ? 'is-active' : '' ?>" href="<?= e(url($href)) ?>" <?= $active === $key ? 'aria-current="page"' : '' ?>>
        <i class="bi bi-<?= e($ic) ?>"></i><span><?= e($label) ?></span>
      </a>
    <?php endforeach; ?>
    <a class="<?= $active === 'more' ? 'is-active' : '' ?>" href="<?= e(url('/more')) ?>"><i class="bi bi-grid"></i><span>More</span></a>
  </nav>
</div>

<!-- Toasts + sign-in prompt (shown when the session expires while offline) -->
<div class="hh-toasts" x-data="toasts" x-cloak aria-live="polite">
  <template x-for="t in items" :key="t.id">
    <div class="hh-toast" :class="'hh-toast--' + t.type" x-text="t.text"></div>
  </template>
</div>
<div x-data="authPrompt" x-cloak x-show="open" class="hh-modal" role="dialog" aria-modal="true">
  <div class="hh-modal__card">
    <h2>Sign in again</h2>
    <p>Your session ended. Your waiting readings are safe on this device and will send once you sign in.</p>
    <a class="btn btn-primary w-100" href="<?= e(url('/login')) ?>?next=<?= e(rawurlencode($here)) ?>" target="_blank" rel="noopener">Open sign-in</a>
    <button type="button" class="btn btn-link w-100 mt-1" @click="retry()">I have signed in — send now</button>
  </div>
</div>
</body>
</html>
