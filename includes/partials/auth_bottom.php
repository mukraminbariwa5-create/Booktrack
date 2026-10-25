    </main>
    <?php $repo = (string)config('project.repo', ''); $author = (string)config('project.author', ''); ?>
    <footer class="auth__foot">
      &copy; <?= date('Y') ?> <a class="link" href="<?= e(url('index.php')) ?>"><?= e(config('app_name')) ?></a><?php if ($author): ?>, a library-system portfolio project by <?= e($author) ?><?php endif; ?>.
      <?php if ($repo): ?><a class="link" href="<?= e($repo) ?>" rel="noopener">Source code</a><?php endif; ?>
    </footer>
  </div>

  <aside class="auth__wall" aria-label="About the library">
    <?php if ($wallCols): ?>
      <div class="wall" aria-hidden="true">
        <?php foreach ($wallCols as $c => $col): ?>
          <div class="wall__col wall__col--<?= $c ?>">
            <div class="wall__track">
              <?php foreach (array_merge($col, $col) as $b): ?><?= cover_html($b, 'cover wall__cover') ?><?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="auth__pitch">
      <h2>
        Your next
        <span class="rotator" data-rotator>
          <span class="is-active">page-turner</span>
          <span aria-hidden="true">favorite novel</span>
          <span aria-hidden="true">big idea</span>
          <span aria-hidden="true">late-night read</span>
        </span>
        is already on the shelf.
      </h2>
      <p>Browse the catalogue, borrow in a tap, and never lose track of a due date.</p>
      <?php if ($spotlight): ?>
        <div class="spotlight" data-cycle aria-roledescription="carousel" aria-label="Books on the shelf">
          <ul class="spotlight__list">
            <?php foreach ($spotlight as $i => $b): ?>
              <li class="spotlight__item<?= $i === 0 ? ' is-active' : '' ?>"<?= $i ? ' aria-hidden="true"' : '' ?>>
                <?= cover_html($b, 'cover spotlight__cover') ?>
                <div class="spotlight__text">
                  <span class="spotlight__kicker">On the shelf<?= $b['category'] ? ' in ' . e($b['category']) : '' ?></span>
                  <strong><?= e($b['title']) ?></strong>
                  <span><?= e($b['author']) ?></span>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
          <?php if (count($spotlight) > 1): ?>
            <div class="spotlight__dots">
              <?php foreach ($spotlight as $i => $b): ?>
                <button class="spotlight__dot<?= $i === 0 ? ' is-active' : '' ?>" type="button" aria-label="Show <?= e($b['title']) ?>"></button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </aside>
</div>
