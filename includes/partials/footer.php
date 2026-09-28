<?php if (current_user()): ?>
  </main>
</div>
<?php endif; ?>

<div class="flashes" aria-live="polite">
  <?php foreach (take_flashes() as $f): ?>
    <div class="flash <?= $f['type'] === 'error' ? 'flash--error' : '' ?>" role="status">
      <?= icon($f['type'] === 'error' ? 'alert' : 'check') ?>
      <span><?= e($f['message']) ?></span>
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
