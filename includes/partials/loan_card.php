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
  <a href="<?= e(url('book.php', ['id' => $loan['book_id']])) ?>"><?= cover_html(['title' => $loan['title'], 'author' => $loan['author'], 'cover' => $loan['cover']], 'cover-md') ?></a>
  <div class="loan-card__body">
    <?php if ($loan['status'] === 'return_requested'): ?>
      <span class="loan-card__due">Return requested</span>
    <?php else: ?>
      <span class="loan-card__due <?= $tone ?>"><?= e(due_label($loan['due_at'])) ?></span>
    <?php endif; ?>
    <a class="loan-card__title" href="<?= e(url('book.php', ['id' => $loan['book_id']])) ?>"><?= e($loan['title']) ?></a>
    <span class="small muted"><span class="nowrap">Borrowed <?= e(fmt_date($loan['borrowed_at'], 'j M')) ?>,</span> <span class="nowrap">due <?= e(fmt_date($loan['due_at'], 'j M')) ?></span></span>
    <?php if ($loan['status'] === 'borrowed'): ?>
      <div class="progress <?= $tone ?>" aria-hidden="true"><span style="width: <?= $pct ?>%"></span></div>
    <?php endif; ?>
  </div>
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
