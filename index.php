<?php
require __DIR__ . '/includes/bootstrap.php';

$user = current_user();
redirect($user ? home_for($user) : 'login.php');
