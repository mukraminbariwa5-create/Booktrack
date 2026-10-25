<?php
/**
 * Settings for one server. Copy this file to config.local.php and fill it in.
 * config.local.php is not in git, so passwords stay out of the public repository.
 * Anything set here replaces the same setting in config.php; leave out what you don't change.
 */
return [
    // The database your host created for you (shared hosts show these in their control panel)
    'db' => [
        'host' => 'sqlXXX.example-host.com',
        'name' => 'if0_12345678_booktrack',
        'user' => 'if0_12345678',
        'pass' => 'your-database-password',
    ],

    // The site's real address, for links in emails
    'app_url' => 'https://booktrack.example.com',

    // Never show error details to visitors
    'debug' => false,

    // A public portfolio demo: demo sign-in buttons, protected demo accounts,
    // no uploads, and the demo data is put back every day.
    // For a real library instead, set 'enabled' => false and change the demo passwords.
    'demo' => ['enabled' => true],

    // Email through SMTP (most free hosts turn off PHP's mail()).
    // Gmail: turn on 2-Step Verification for the Google account, create an "App password"
    // (myaccount.google.com/apppasswords) and use it below; 'from' must be that Gmail address.
    // Brevo, Mailgun, Zoho and your host's own mail server work the same way.
    'mail' => [
        'driver'    => 'smtp',
        'from'      => 'your.address@gmail.com',
        'from_name' => 'BookTrack Library',
        'smtp' => [
            'host'       => 'smtp.gmail.com',
            'port'       => 587,
            'encryption' => 'tls',      // 'tls' (port 587) or 'ssl' (port 465)
            'username'   => 'your.address@gmail.com',
            'password'   => 'the 16-letter app password',
        ],
    ],
];
