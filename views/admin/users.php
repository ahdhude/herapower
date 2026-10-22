<?php /** @var array $users @var array $roles */
$me = App\Core\Auth::id();
$roleHelp = [
    'operator'   => 'Enters readings for today and yesterday',
    'supervisor' => 'Edits older readings, approves days, overrides counters',
    'viewer'     => 'Reports and exports only',
    'admin'      => 'Everything, including meters and users',
];
?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="hh-list">
      <?php foreach ($users as $u): ?>
        <div class="hh-list__item flex-wrap" x-data="{ edit: false }" style="<?= (int) $u['active'] ? '' : 'opacity:.6' ?>">
          <span class="hh-avatar"><?= e(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?></span>
          <span class="hh-list__text">
            <strong><?= e($u['name']) ?> <?php if ((int) $u['id'] === $me): ?><span class="hh-tag">you</span><?php endif; ?></strong>
            <small>@<?= e($u['username']) ?> · <?= $u['last_login_at'] ? 'last in ' . e(substr($u['last_login_at'], 0, 16)) : 'never signed in' ?></small>
          </span>
          <span class="hh-pill <?= $u['role'] === 'admin' ? 'hh-pill--info' : ($u['role'] === 'supervisor' ? 'hh-pill--warn' : 'hh-pill--muted') ?>"><?= e(ucfirst($u['role'])) ?></span>
          <?php if (!(int) $u['active']): ?><span class="hh-pill hh-pill--bad">Disabled</span><?php endif; ?>
          <button type="button" class="btn btn-sm btn-outline-primary" @click="edit = !edit">Edit</button>

          <form method="post" action="<?= e(url('/admin/users/' . (int) $u['id'])) ?>" x-show="edit" x-cloak class="w-100 mt-2 pt-3 border-top">
            <?= csrf_field() ?>
            <div class="hh-formgrid hh-formgrid--3">
              <div><label class="form-label">Name</label><input class="form-control" name="name" value="<?= e($u['name']) ?>" required maxlength="100"></div>
              <div><label class="form-label">Role</label>
                <select class="form-select" name="role">
                  <?php foreach ($roles as $r): ?><option value="<?= e($r) ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= e(ucfirst($r)) ?></option><?php endforeach; ?>
                </select></div>
              <div><label class="form-label">New password <span class="fw-normal">(optional)</span></label><input class="form-control" type="password" name="password" autocomplete="new-password" minlength="8" placeholder="Leave blank to keep"></div>
            </div>
            <label class="hh-check"><input class="form-check-input mt-0" type="checkbox" name="active" value="1" <?= (int) $u['active'] ? 'checked' : '' ?> <?= (int) $u['id'] === $me ? 'disabled' : '' ?>> Can sign in</label>
            <?php if ((int) $u['id'] === $me): ?><input type="hidden" name="active" value="1"><?php endif; ?>
            <button class="btn btn-primary btn-sm" type="submit">Save</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="col-lg-4">
    <form method="post" action="<?= e(url('/admin/users')) ?>" class="hh-card">
      <?= csrf_field() ?>
      <h3 class="h6 fw-bold mb-3"><i class="bi bi-person-plus"></i> Add a person</h3>
      <div class="mb-2"><label class="form-label" for="u-name">Full name</label><input id="u-name" class="form-control" name="name" required maxlength="100"></div>
      <div class="mb-2"><label class="form-label" for="u-user">Username</label><input id="u-user" class="form-control" name="username" required autocapitalize="none" spellcheck="false" pattern="[A-Za-z0-9._-]{3,50}"></div>
      <div class="mb-2"><label class="form-label" for="u-pass">Password (min 8)</label><input id="u-pass" class="form-control" type="password" name="password" required minlength="8" autocomplete="new-password"></div>
      <div class="mb-3"><label class="form-label" for="u-role">Role</label>
        <select id="u-role" class="form-select" name="role" x-data x-ref="role">
          <?php foreach ($roles as $r): ?><option value="<?= e($r) ?>"><?= e(ucfirst($r)) ?> — <?= e($roleHelp[$r]) ?></option><?php endforeach; ?>
        </select></div>
      <button class="btn btn-primary w-100" type="submit">Add</button>
    </form>
  </div>
</div>
