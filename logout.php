<?php
require __DIR__ . '/includes/bootstrap.php';

if (is_post()) {
    verify_csrf();
    logout();
    session_start();
    flash('success', 'You have been signed out.');
}
redirect('login.php');
