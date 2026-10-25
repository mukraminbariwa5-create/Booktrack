<?php
/**
 * Ratings and short reviews.
 *
 * A member can rate a book (1–5) once they've borrowed it, and add a few
 * sentences if they like. One review per member per book: saving again
 * updates it. Averages are joined into every book query (see books_from()).
 */

const REVIEW_MAX_LENGTH = 1000;

function reviews_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->query('SELECT 1 FROM reviews LIMIT 1');
        return;
    } catch (PDOException $e) {
        // missing on older installs: create it
    }
    try {
        if (db_is_mysql()) {
            db()->exec("CREATE TABLE reviews (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id    INT UNSIGNED NOT NULL,
                book_id    INT UNSIGNED NOT NULL,
                rating     TINYINT UNSIGNED NOT NULL,
                body       TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_reviews_user_book (user_id, book_id),
                CONSTRAINT fk_reviews_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_reviews_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
                INDEX idx_reviews_book (book_id, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            db()->exec("CREATE TABLE reviews (id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                book_id INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE,
                rating INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5), body TEXT,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (user_id, book_id))");
        }
    } catch (PDOException $e) {
        // created by another request
    }
}

/** Members can review a book they've borrowed at some point. */
function can_review(int $userId, int $bookId): bool
{
    return (bool)q_val('SELECT COUNT(*) FROM loans WHERE user_id = ? AND book_id = ?', [$userId, $bookId]);
}

function member_review(int $userId, int $bookId): ?array
{
    return q_one('SELECT * FROM reviews WHERE user_id = ? AND book_id = ?', [$userId, $bookId]);
}

/** Create or update the member's review. Returns true when it was new. */
function save_review(int $userId, int $bookId, int $rating, string $body): bool
{
    $body = trim(preg_replace("/\r\n?/", "\n", $body));
    if ($rating < 1 || $rating > 5) throw new DomainException('Choose a rating from 1 to 5 stars.');
    if (mb_strlen($body) > REVIEW_MAX_LENGTH) throw new DomainException('Reviews can be at most ' . REVIEW_MAX_LENGTH . ' characters.');
    $book = q_one('SELECT id, title FROM books WHERE id = ?', [$bookId]);
    if (!$book) throw new DomainException('That book no longer exists.');
    if (!can_review($userId, $bookId)) throw new DomainException('You can review a book once you\'ve borrowed it.');

    $now = date('Y-m-d H:i:s');
    $existing = member_review($userId, $bookId);
    if ($existing) {
        q('UPDATE reviews SET rating = ?, body = ?, updated_at = ? WHERE id = ?', [$rating, $body !== '' ? $body : null, $now, $existing['id']]);
    } else {
        q('INSERT INTO reviews (user_id, book_id, rating, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $bookId, $rating, $body !== '' ? $body : null, $now, $now]);
    }
    $name = q_val('SELECT name FROM users WHERE id = ?', [$userId]);
    log_activity($existing ? 'review.updated' : 'review.created', "$name rated “{$book['title']}” " . plural($rating, 'star'));
    return !$existing;
}

/** Delete a review: members their own, librarians any ($userId = null). */
function delete_review(int $reviewId, ?int $userId): void
{
    $r = q_one('SELECT r.*, b.title, u.name FROM reviews r JOIN books b ON b.id = r.book_id JOIN users u ON u.id = r.user_id WHERE r.id = ?', [$reviewId]);
    if (!$r || ($userId !== null && (int)$r['user_id'] !== $userId)) throw new DomainException('Review not found.');
    q('DELETE FROM reviews WHERE id = ?', [$reviewId]);
    log_activity('review.deleted', ($userId === null ? 'Librarian removed ' . $r['name'] . '\'s' : $r['name'] . ' deleted their') . " review of “{$r['title']}”");
}

/**
 * Average, total and how many ratings each star level got:
 * ['avg' => 4.3, 'total' => 128, 'written' => 40, 'counts' => [5 => 80, 4 => 30, 3 => 10, 2 => 5, 1 => 3]]
 */
function rating_summary(int $bookId): array
{
    $counts = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
    foreach (q_all('SELECT rating, COUNT(*) AS n FROM reviews WHERE book_id = ? GROUP BY rating', [$bookId]) as $r) {
        $counts[(int)$r['rating']] = (int)$r['n'];
    }
    $total = array_sum($counts);
    $sum = 0;
    foreach ($counts as $stars => $n) $sum += $stars * $n;
    return [
        'avg' => $total ? $sum / $total : 0.0,
        'total' => $total,
        'written' => (int)q_val('SELECT COUNT(*) FROM reviews WHERE book_id = ? AND body IS NOT NULL', [$bookId]),
        'counts' => $counts,
    ];
}

const REVIEW_SORTS = [
    'recent' => 'r.updated_at DESC, r.id DESC',
    'high'   => 'r.rating DESC, r.updated_at DESC, r.id DESC',
    'low'    => 'r.rating ASC, r.updated_at DESC, r.id DESC',
];

/**
 * One page of a book's reviews. $stars = 0 for all, or 1–5 to filter.
 * Written reviews come before rating-only ones within each sort.
 */
function book_reviews(int $bookId, int $stars = 0, string $sort = 'recent', int $limit = 10, int $offset = 0, ?int $excludeUser = null): array
{
    $where = 'r.book_id = ?';
    $params = [$bookId];
    if ($stars >= 1 && $stars <= 5) { $where .= ' AND r.rating = ?'; $params[] = $stars; }
    if ($excludeUser) { $where .= ' AND r.user_id <> ?'; $params[] = $excludeUser; }
    $order = '(r.body IS NULL) ASC, ' . (REVIEW_SORTS[$sort] ?? REVIEW_SORTS['recent']);
    return q_all("SELECT r.*, u.name FROM reviews r JOIN users u ON u.id = r.user_id WHERE $where ORDER BY $order LIMIT "
        . max(1, $limit) . ' OFFSET ' . max(0, $offset), $params);
}

function count_reviews(int $bookId, int $stars = 0): int
{
    return $stars >= 1 && $stars <= 5
        ? (int)q_val('SELECT COUNT(*) FROM reviews WHERE book_id = ? AND rating = ?', [$bookId, $stars])
        : (int)q_val('SELECT COUNT(*) FROM reviews WHERE book_id = ?', [$bookId]);
}

/** "Alice S." — reviews show first name and initial. */
function reviewer_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [$name];
    return count($parts) > 1 ? $parts[0] . ' ' . mb_substr(end($parts), 0, 1) . '.' : $parts[0];
}

/** Five stars, filled to the nearest whole star, as an image with a text label. */
function stars_html(float $rating, string $class = 'stars'): string
{
    $full = (int)floor($rating + 0.25);   // 4.5 shows four stars beside the exact "4.5" 
    $out = '<span class="' . e($class) . '" role="img" aria-label="' . e(rtrim(rtrim(number_format($rating, 1), '0'), '.')) . ' out of 5 stars">';
    for ($i = 1; $i <= 5; $i++) {
        $fill = $full >= $i ? 'full' : 'empty';
        $out .= '<svg class="star star--' . $fill . '" viewBox="0 0 20 20" aria-hidden="true"><path d="M10 1.8l2.5 5.3 5.8.7-4.3 4 1.1 5.7L10 14.7l-5.1 2.8 1.1-5.7-4.3-4 5.8-.7z"/></svg>';
    }
    return $out . '</span>';
}

function rating_label(float $avg): string
{
    return rtrim(rtrim(number_format($avg, 1), '0'), '.');
}
