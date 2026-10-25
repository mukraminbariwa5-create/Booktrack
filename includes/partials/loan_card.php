<?php
/** A member's active loan. Expects $loan (joined with book title/author/cover). */
$state = loan_state($loan);
$days = days_until($loan['due_at']);
$soon = $days >= 0 && $days <= (int)config('library.due_soon_days');
$tone = $state === 'overdue' ? 'is-late' : ($soon ? 'is-soon' : '');
$total = max(1, (strtotime($loan['due_at']) - strtotime($loan['borrowed_at'])) / 86400);
$elapsed = (strtotime(today()) - strtotime($loan['borrowed_at'])) / 86400;
$pct = (int)max(4, min(100, round($elapsed / $total * 100)));
?>
<article class="loan-card" data-book="<?= (int)$loan['book_id'] ?>">
  <a href="<?= e(url('book.php', ['id' => $loan['book_id']])) ?>" tabindex="-1" aria-hidden="true"><?= cover_html(['title' => $loan['title'], 'author' => $loan['author'], 'cover' => $loan['cover']], 'cover-md') ?></a>
  <div class="loan-card__body">
    <?php if ($loan['status'] === 'return_requested'): ?>
      <?php /* the "Waiting for check-in" section heading already says it */ ?>
    <?php else: ?>
      <span class="loan-card__due <?= $tone ?>"><?= e(due_label($loan['due_at'])) ?></span>
    <?php endif; ?>
    <a class="loan-card__title" href="<?= e(url('book.php', ['id' => $loan['book_id']])) ?>"><?= e($loan['title']) ?></a>
    <span class="small muted"><span class="nowrap">Borrowed <?= e(fmt_date($loan['borrowed_at'], 'j M')) ?>,</span> <span class="nowrap">due <?= e(fmt_date($loan['due_at'], 'j M')) ?></span></span>
    <?php if ($state === 'overdue' || ($loan['status'] === 'return_requested' && $loan['due_at'] < today())): $fee = accruing_fine($loan); if ($fee > 0): ?>
      <span class="small is-late">Late fee so far: <strong><?= e(money($fee)) ?></strong></span>
    <?php endif; endif; ?>
    <?php if ($loan['status'] === 'borrowed'): ?>
      <div class="progress <?= $tone ?>" aria-hidden="true"><span style="width: <?= $pct ?>%"></span></div>
      <?php if (!empty($showActions) && $state !== 'overdue'): ?>
        <details class="cal-menu">
          <summary><?= icon('calendar', 'icon icon--xs') ?> Add to calendar</summary>
          <div class="cal-menu__list">
            <a href="<?= e(google_calendar_url($loan)) ?>" target="_blank" rel="noopener">Google Calendar</a>
            <a href="<?= e(url('calendar.php', ['loan' => $loan['id']])) ?>" download>Outlook, Apple or other (.ics)</a>
          </div>
        </details>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php if (!empty($showActions) && $loan['status'] === 'return_requested' && $state !== 'overdue' && $loan['due_at'] >= today()): ?>
    <div class="loan-card__actions">
      <form method="post" action="<?= e(url('loans.php')) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="cancel_return"><input type="hidden" name="loan_id" value="<?= (int)$loan['id'] ?>">
        <button class="btn btn--ghost btn--sm" type="submit" style="width:100%" title="Cancel the return and keep reading">Keep it</button>
      </form>
    </div>
  <?php endif; ?>
  <?php if (!empty($showActions) && $loan['status'] === 'borrowed'): ?>
    <div class="loan-card__actions">
      <form method="post" action="<?= e(url('loans.php')) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="return"><input type="hidden" name="loan_id" value="<?= (int)$loan['id'] ?>">
        <button class="btn btn--outline btn--sm" type="submit" style="width:100%">Return</button>
      </form>
      <?php if ($state !== 'overdue' && (int)$loan['renewals'] < 1): ?>
        <form method="post" action="<?= e(url('loans.php')) ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="renew"><input type="hidden" name="loan_id" value="<?= (int)$loan['id'] ?>">
          <button class="btn btn--ghost btn--sm" type="submit" style="width:100%" title="Extend by 7 days (once)">Renew</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</article>
