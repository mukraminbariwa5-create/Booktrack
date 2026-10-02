<?php
/**
 * BookTrack configuration.
 * Adjust the database credentials to match your MySQL / XAMPP setup.
 */
return [
    'app_name' => 'BookTrack',
    'timezone' => 'Asia/Manila',

    'db' => [
        'driver'   => 'mysql',          // 'mysql' (default) or 'sqlite' (used by the test suite)
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'booktrack_v2',
        'user'     => 'root',
        'pass'     => '',
        'sqlite_path' => __DIR__ . '/database/booktrack.sqlite',
    ],

    'auth' => [
        'allow_registration' => true,  // show "Create an account" so people can sign up as members
        'show_demo_logins'   => true,  // one-click demo sign-in links on the sign-in page; turn off for real use
    ],

    'library' => [
        'loan_days'       => 14,   // default loan period
        'max_active_loans' => 3,   // how many books a member can hold at once
        'due_soon_days'   => 3,    // highlight loans due within this many days
    ],

    // Real covers from Open Library: fetched when a book is added or edited
    // without one, and from "Find cover" / "Find missing covers" in Books.
    'covers' => [
        'auto_fetch' => true,
        'api'        => 'https://openlibrary.org',
        'images'     => 'https://covers.openlibrary.org',
    ],

    'uploads' => [
        'max_cover_mb' => 5,
        'max_pdf_mb'   => 30,
    ],

    // Show detailed errors (turn off on a public server)
    'debug' => true,
];
