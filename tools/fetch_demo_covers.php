<?php
/**
 * Fill in the demo books' cover art from Open Library (https://openlibrary.org).
 * The repository doesn't include publisher artwork, so a fresh demo server fetches
 * the covers itself. Books keep their seed cover path; only missing files are
 * downloaded, and a book with no cover found keeps its generated cover.
 *
 *   php tools/fetch_demo_covers.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../includes/bootstrap.php';

/** Demo books whose title search on Open Library comes back without a cover. */
const FALLBACK_ISBN = ['Nineteen Eighty-Four' => '9780451524935'];

$found = 0;
$missing = [];
foreach (q_all("SELECT id, title, author, isbn, cover FROM books WHERE cover LIKE 'uploads/covers/seed-%'") as $book) {
    $target = APP_ROOT . '/' . $book['cover'];
    if (is_file($target)) continue;
    $url = find_cover_url($book);
    // A title search can miss a famous edition; fall back to a known ISBN for those
    if (!$url && isset(FALLBACK_ISBN[$book['title']])) $url = find_cover_url(['isbn' => FALLBACK_ISBN[$book['title']]]);
    $res = $url ? covers_http_get($url) : null;
    if (!$res || $res[0] !== 200 || !cover_image_ok($res[1])) {
        $missing[] = $book['title'];
        continue;
    }
    $bytes = $res[1];
    // Keep pages fast: at most 600px wide, saved under the seed file name the database expects
    $img = function_exists('imagecreatefromstring') ? @imagecreatefromstring($bytes) : false;
    if ($img && imagesx($img) > 600) {
        $h = (int)round(imagesy($img) * (600 / imagesx($img)));
        $small = imagecreatetruecolor(600, $h);
        imagecopyresampled($small, $img, 0, 0, 0, 0, 600, $h, imagesx($img), imagesy($img));
        $img = $small;
    }
    $ok = $img
        ? (str_ends_with($target, '.png') ? imagepng($img, $target) : imagejpeg($img, $target, 84))
        : file_put_contents($target, $bytes) !== false;
    if ($ok) $found++; else $missing[] = $book['title'];
}
echo "Demo covers: $found downloaded" . ($missing ? '; no cover for ' . implode(', ', $missing) : '') . ".\n";
