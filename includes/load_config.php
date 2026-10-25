<?php
/**
 * Settings: config.php holds the defaults (it's in git), and config.local.php,
 * if it exists, overrides any of them for this server (it's not in git, so
 * passwords and SMTP details never end up in the public repository).
 * Copy config.local.example.php to config.local.php to start one.
 */
function load_config(string $root): array
{
    $config = require $root . '/config.php';
    $local = $root . '/config.local.php';
    if (is_file($local)) {
        $override = require $local;
        if (is_array($override)) $config = array_replace_recursive($config, $override);
    }
    return $config;
}
