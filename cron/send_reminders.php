<?php
/**
 * Send due-date and overdue reminder emails from the command line.
 *
 * BookTrack already sends them on the first page view of each day, so this is
 * only needed if you want them at a fixed time even when nobody visits.
 * Windows Task Scheduler → Create Basic Task → Daily, 8:00 AM → Start a program:
 *   Program:   C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\booktrack-v2\cron\send_reminders.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/bootstrap.php';

$sent = send_due_reminders();
@file_put_contents(APP_ROOT . '/storage/reminders-last-run.txt', today());
echo 'Reminders sent: ' . $sent['due_soon'] . ' due soon, ' . $sent['overdue'] . " overdue\n";
