<?php
/** Find a cover on Open Library for the book being edited. Returns its image URL to preview. */
require __DIR__ . '/../includes/bootstrap.php';
require_role('admin');

if (!is_post()) json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
verify_csrf();

$book = ['title' => input('title'), 'author' => input('author'), 'isbn' => input('isbn')];
if ($book['title'] === '' && $book['isbn'] === '') {
    json_out(['ok' => false, 'error' => 'Enter the title or ISBN first.'], 422);
}
$url = find_cover_url($book);
$url
    ? json_out(['ok' => true, 'url' => $url])
    : json_out(['ok' => false, 'error' => 'No cover found on Open Library. Check the title, or add the ISBN.']);
