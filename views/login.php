<?php /** @var ?string $error */ ?>
<main class="hh-login">
  <div class="hh-login__glow" aria-hidden="true"></div>
  <section class="hh-login__card">
    <div class="hh-login__brand">
      <span class="hh-brand__mark hh-brand__mark--lg"><i class="bi bi-lightning-charge-fill"></i></span>
      <h1>Herahakatha</h1>
      <p>Powerhouse logsheet · Canareef</p>
    </div>

    <?php if (!empty($_GET['out'])): ?>
      <div class="alert alert-success py-2 small" role="status">You have been signed out.</div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-danger py-2 small" role="alert"><i class="bi bi-exclamation-octagon"></i> <?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/login')) ?>" autocomplete="on">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next ?? '') ?>">
      <div class="hh-field">
        <label for="username">Username</label>
        <div class="hh-input-icon"><i class="bi bi-person"></i>
          <input id="username" name="username" class="form-control form-control-lg" value="<?= e($username ?? '') ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
        </div>
      </div>
      <div class="hh-field" x-data="{ show: false }">
        <label for="password">Password</label>
        <div class="hh-input-icon"><i class="bi bi-lock"></i>
          <input id="password" name="password" :type="show ? 'text' : 'password'" class="form-control form-control-lg" autocomplete="current-password" required>
          <button type="button" class="hh-eye" @click="show = !show" :aria-label="show ? 'Hide password' : 'Show password'"><i class="bi" :class="show ? 'bi-eye-slash' : 'bi-eye'"></i></button>
        </div>
      </div>
      <button class="btn btn-primary btn-lg w-100 mt-2" type="submit">Sign in</button>
    </form>
    <p class="hh-login__foot">Forgotten your password? Ask an administrator.</p>
  </section>
</main>
<script src="<?= e(asset('vendor/alpine.min.js')) ?>" defer></script>
