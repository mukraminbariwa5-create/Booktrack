<?php if (current_user()): ?>
    </main>
  </div>
</div>

<div class="reminders" id="reminders" popover aria-labelledby="reminders-h">
  <div class="reminders__head">
    <h2 id="reminders-h">Reminders</h2>
    <button class="btn btn--ghost btn--icon btn--sm" type="button" popovertarget="reminders" popovertargetaction="hide" aria-label="Close">&times;</button>
  </div>
  <?php if ($reminders): ?>
    <ul class="reminders__list">
      <?php foreach ($reminders as $r): ?>
        <li><a class="reminder reminder--<?= e($r['tone']) ?>" href="<?= e($r['url']) ?>">
          <span class="reminder__icon"><?= icon($r['icon']) ?></span>
          <span class="reminder__body"><strong><?= e($r['title']) ?></strong><span><?= e($r['text']) ?></span></span>
          <?= icon('chevron-right', 'icon reminder__go') ?>
        </a></li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <div class="reminders__empty"><?= empty_art('done') ?><strong>You're all caught up</strong><span>Due dates and new replies show up here.</span></div>
  <?php endif; ?>
</div>

<dialog class="palette" id="palette" aria-label="Search">
  <div class="palette__field">
    <?= icon('search') ?>
    <input type="search" data-palette-input autocomplete="off" spellcheck="false" enterkeyhint="search" role="combobox" aria-expanded="true" aria-controls="palette-list" aria-autocomplete="list"
      placeholder="<?= current_user()['role'] === 'admin' ? 'Search books or members' : 'Search title, author or category' ?>">
    <button class="palette__cancel" type="button" data-palette-close>Cancel</button>
  </div>
  <ul class="palette__list" id="palette-list" role="listbox" aria-label="Results" data-palette-list></ul>
</dialog>
<?php endif; ?>

<div class="flashes" aria-live="polite">
  <?php foreach (take_flashes() as $f): ?>
    <div class="flash <?= $f['type'] === 'error' ? 'flash--error' : '' ?><?= !empty($f['undo']) ? ' flash--undo' : '' ?>" role="status">
      <?= icon($f['type'] === 'error' ? 'alert' : 'check') ?>
      <span><?= e($f['message']) ?></span>
      <?php if (!empty($f['undo'])): ?>
        <form class="flash__undo" method="post" action="<?= e($f['undo']['url']) ?>">
          <?= csrf_field() ?>
          <?php foreach ($f['undo']['fields'] as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>"><?php endforeach; ?>
          <button type="submit"><?= icon('undo') ?> Undo</button>
        </form>
      <?php endif; ?>
      <button class="flash__close" type="button" aria-label="Dismiss">&times;</button>
    </div>
  <?php endforeach; ?>
</div>

<dialog class="modal modal--sm" id="confirm-dialog">
  <form method="dialog">
    <div class="modal__head"><h2 data-confirm-title>Are you sure?</h2></div>
    <div class="modal__body"><p class="muted" data-confirm-text style="margin:0"></p></div>
    <div class="modal__foot">
      <button class="btn btn--ghost" value="cancel">Cancel</button>
      <button class="btn btn--danger" value="ok" data-confirm-ok>Confirm</button>
    </div>
  </form>
</dialog>
</body>
</html>
