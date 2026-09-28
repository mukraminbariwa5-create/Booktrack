<?php
/** Toggle a favorite. Returns JSON for fetch() calls, or redirects back for plain forms. */
require __DIR__ . '/../includes/bootstrap.php';
$user = require_role('member');

if (!is_post()) json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
verify_csrf();

try {
    $state = toggle_favorite((int)$user['id'], input_int('book_id'));
} catch (DomainException $ex) {
    if (wants_json()) json_out(['ok' => false, 'error' => $ex->getMessage()], 404);
    flash('error', $ex->getMessage());
    back('favorites.php');
}

if (wants_json()) json_out(['ok' => true, 'favorite' => $state]);
flash('success', $state ? 'Added to your favorites.' : 'Removed from favorites.');
back('favorites.php');
