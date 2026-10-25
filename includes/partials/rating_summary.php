<?php
/**
 * Average rating with a 5→1 breakdown, like a store listing.
 * Expects $summary (from rating_summary()). Optional $starUrl: fn(int $stars): string,
 * which turns each breakdown row into a filter link; $activeStars marks the current filter.
 */
$starUrl = $starUrl ?? null;
$activeStars = $activeStars ?? 0;
$rsTotal = (int)$summary['total'];          // prefixed: this partial is included into pages with their own $total
$rsMax = max(1, max($summary['counts']));
?>
<div class="rsum">
  <div class="rsum__score">
    <span class="rsum__avg"><?= $rsTotal ? e(number_format((float)$summary['avg'], 1)) : '–' ?></span>
    <?= stars_html((float)$summary['avg']) ?>
    <span class="rsum__count"><?= $rsTotal ? plural($rsTotal, 'rating') : 'No ratings yet' ?></span>
  </div>
  <ul class="rsum__bars" aria-label="Ratings by number of stars">
    <?php foreach ($summary['counts'] as $level => $n):
            $label = plural($level, 'star') . ': ' . plural($n, 'rating');
      $row = '<span class="rsum__level" aria-hidden="true">' . $level . '</span>'
           . '<span class="rsum__track" aria-hidden="true"><span style="width:' . ($n ? max(2, round($n / $rsMax * 100)) : 0) . '%"></span></span>'
           . '<span class="rsum__n" aria-hidden="true">' . ($n >= 1000 ? round($n / 1000, 1) . 'k' : $n) . '</span>'
           . '<span class="sr-only">' . e($label) . '</span>';
    ?>
      <li>
        <?php if ($starUrl && $n): ?>
          <a class="rsum__row <?= $activeStars === $level ? 'is-active' : '' ?>" href="<?= e($starUrl($level)) ?>" <?= $activeStars === $level ? 'aria-current="true"' : '' ?>><?= $row ?></a>
        <?php else: ?>
          <span class="rsum__row"><?= $row ?></span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
