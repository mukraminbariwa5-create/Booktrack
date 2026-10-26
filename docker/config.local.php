<?php
/**
 * Settings for the public demo container (docker/README.md). Nothing secret here:
 * the demo uses SQLite inside the container and sends no email.
 */
return [
    'db' => [
        'driver'      => 'sqlite',
        'sqlite_path' => '/var/lib/booktrack/booktrack.sqlite',
    ],
    'demo'  => ['enabled' => true, 'reset_daily' => false],
    'mail'  => ['driver' => 'off'],
    'debug' => false,
];
