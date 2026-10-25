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
        // Librarians must turn on two-step sign-in (an authenticator app) before using the librarian pages.
        // Turn this on once your own librarian account has it set up.
        'require_2fa_for_librarians' => false,
    ],

    'library' => [
        'loan_days'       => 14,   // default loan period
        'max_active_loans' => 3,   // how many books a member can hold at once
        'due_soon_days'   => 3,    // highlight loans due within this many days
        'max_holds'       => 3,    // how many books a member can be in line for at once
        'hold_pickup_days' => 3,   // how long a returned copy is kept for the next member in line
    ],

    // Late fees. A fine grows each day a book is overdue and is fixed when it's checked in.
    // Librarians mark fines paid at the desk, or waive them. Set 'enabled' => false to turn fines off.
    'fines' => [
        'enabled'      => true,
        'currency'     => '₱',
        'per_day'      => 5,     // charged per late day
        'max_per_book' => 200,   // the most one late book can cost (0 = no cap)
        'grace_days'   => 0,     // late days that are free
        'block_at'     => 100,   // unpaid total that pauses borrowing (0 = never)
    ],

    // Reminder emails (due soon, overdue, hold ready).
    //   'log'  saves each email as an .eml file in storage/mail/ (works on XAMPP with no mail server;
    //          double-click one to open it in Outlook or Windows Mail)
    //   'mail' sends with PHP's mail() once sendmail/SMTP is set up in php.ini
    //   'off'  sends nothing
    'mail' => [
        'driver'             => 'log',
        'from'               => 'library@booktrack.local',
        'from_name'          => 'BookTrack Library',
        'remind_days_before' => 2,     // "due soon" email this many days before the due date
        'daily_auto'         => true,  // send the day's reminders on the first page view each day
    ],
    // Shown on the public front page (index.php)
    'project' => [
        'repo'       => 'https://github.com/mukraminbariwa5-create/booktrack',
        'author'     => 'Mukramin Bariwa Ansao',
        'author_url' => 'https://www.linkedin.com/in/mukramin-a-001833333',
    ],

    // Full address of the site, used for links in emails (detected automatically when empty)
    'app_url' => '',

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

    // Public demo (for a portfolio site anyone can try). Set 'enabled' => true in config.local.php
    // on the demo server: shows the demo sign-in buttons, protects the demo accounts from being
    // changed or deleted, turns off uploads, and (MySQL) puts the demo data back every day.
    'demo' => [
        'enabled'     => false,
        'reset_daily' => true,
        'accounts'    => ['admin', 'alice', 'brian', 'carla', 'diego', 'ella'],
    ],

    // Show detailed errors (turn off on a public server)
    'debug' => true,
];
