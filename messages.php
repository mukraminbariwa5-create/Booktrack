<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_role('member');
$uid = (int)$user['id'];

$thread = chat_thread($uid);
chat_mark_read($uid, 'member');

$title = 'Messages';
$active = 'messages';
$bodyClass = 'page-chat';
require __DIR__ . '/includes/partials/header.php';

$mine = 'member';
$memberId = $uid;
$chatHead = '<span class="chat__avatar chat__avatar--library">' . logo_mark() . '</span>'
    . '<div class="chat__who"><strong>The Library</strong><span>Ask about a book, your loans or your account</span></div>';
$emptyHint = 'Say hello! Ask for a book you can\'t find, ask to keep a book longer, or report a problem. A librarian will reply here.';
$quick = $thread ? [] : [
    'Can you get a book for me?',
    'Can I keep my book a little longer?',
    'I found a damaged page in a book.',
];
echo '<h1 class="sr-only">Messages</h1>';
include __DIR__ . '/includes/partials/chat_view.php';

require __DIR__ . '/includes/partials/footer.php';
