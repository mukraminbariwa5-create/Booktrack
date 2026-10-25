<?php
/**
 * A loan's due date as a calendar event (.ics), for Outlook, Apple Calendar,
 * Windows Calendar and Android. All-day on the due date, with a reminder the
 * morning before. Members can only download their own loans.
 */
require __DIR__ . '/includes/bootstrap.php';
$user = require_role('member');

$loan = find_loan(input_int('loan'));
if (!$loan || (int)$loan['user_id'] !== (int)$user['id'] || $loan['status'] === 'returned') {
    http_response_code(404);
    exit('Loan not found.');
}

/** Escape text for an iCalendar property value (RFC 5545 §3.3.11). */
function ics_text(string $s): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $s);
}

/** Fold lines longer than 75 octets, as the format requires. */
function ics_fold(string $line): string
{
    $out = '';
    while (strlen($line) > 75) {
        $cut = 75;
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--;   // don't split a UTF-8 character
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
    }
    return $out . $line;
}

$due = str_replace('-', '', $loan['due_at']);
$next = date('Ymd', strtotime($loan['due_at'] . ' +1 day'));
$app = config('app_name', 'BookTrack');
$lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//' . $app . '//Loans//EN',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'BEGIN:VEVENT',
    'UID:loan-' . (int)$loan['id'] . '-' . $due . '@' . (preg_replace('/[^a-z0-9.-]/i', '', explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]) ?: 'booktrack'),
    'DTSTAMP:' . gmdate('Ymd\THis\Z'),
    'DTSTART;VALUE=DATE:' . $due,
    'DTEND;VALUE=DATE:' . $next,
    'SUMMARY:' . ics_text('Return “' . $loan['title'] . '” to the library'),
    'DESCRIPTION:' . ics_text('“' . $loan['title'] . '” by ' . $loan['author'] . ' is due back today. '
        . 'If you renew it, the due date moves, so add the new date too. Manage your loans: ' . absolute_url('loans.php')),
    'URL:' . absolute_url('loans.php'),
    'TRANSP:TRANSPARENT',
    'BEGIN:VALARM',
    'ACTION:DISPLAY',
    'DESCRIPTION:' . ics_text('“' . $loan['title'] . '” is due back tomorrow'),
    'TRIGGER:-PT15H',
    'END:VALARM',
    'END:VEVENT',
    'END:VCALENDAR',
];

$name = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $loan['title']), '-') ?: 'book';
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="due-' . $name . '.ics"');
header('Cache-Control: private, no-store');
echo implode("\r\n", array_map('ics_fold', $lines)) . "\r\n";
