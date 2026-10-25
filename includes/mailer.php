<?php
/**
 * Outgoing email.
 *
 * config.php → 'mail' => ['driver' => …]
 *   log   (default) save each email as an .eml file in storage/mail/, so it works on a
 *         fresh XAMPP install with no mail server. Double-click one to open it in Outlook,
 *         Thunderbird or Windows Mail.
 *   mail  send with PHP's mail() (configure sendmail / SMTP in php.ini first)
 *   smtp  send through an SMTP server (Gmail, Brevo, your host's mail server…) using
 *         'mail' => ['smtp' => [...]] in config.local.php. Works where mail() is disabled.
 *   off   don't send anything
 */

function mail_dir(): string
{
    return (string)config('mail.dir', APP_ROOT . '/storage/mail');
}

/** Absolute link for emails (they're read outside the site, so relative URLs won't do). */
function absolute_url(string $path = '', array $query = []): string
{
    $base = rtrim((string)config('app_url', ''), '/');
    if ($base === '' && PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(base_url(), '/');
    }
    if ($base === '') $base = 'http://localhost/' . basename(APP_ROOT);
    return $base . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
}

/** Send a plain-text email. Returns true when it was handed off (or saved). */
function send_mail(string $toEmail, string $toName, string $subject, string $body): bool
{
    $driver = (string)config('mail.driver', 'log');
    if ($driver === 'off' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return false;

    $clean = fn(string $v) => trim(str_replace(["\r", "\n"], ' ', $v));   // no header injection
    $fromEmail = $clean((string)config('mail.from', 'library@booktrack.local'));
    $fromName = $clean((string)config('mail.from_name', config('app_name', 'BookTrack') . ' Library'));
    $encode = fn(string $v) => mb_encode_mimeheader($clean($v), 'UTF-8', 'Q');

    $headers = [
        'From: ' . $encode($fromName) . ' <' . $fromEmail . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: BookTrack',
    ];
    $body = str_replace(["\r\n", "\r"], "\n", $body);

    if ($driver === 'mail') {
        return @mail($encode($toName) . ' <' . $toEmail . '>', $encode($subject), str_replace("\n", "\r\n", $body), implode("\r\n", $headers));
    }

    $domain = substr(strrchr($fromEmail, '@') ?: '@booktrack.local', 1);
    $message = implode("\r\n", array_merge([
        'Date: ' . date('r'),
        'To: ' . $encode($toName) . ' <' . $toEmail . '>',
        'Subject: ' . $encode($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
    ], $headers)) . "\r\n\r\n" . str_replace("\n", "\r\n", $body) . "\r\n";

    if ($driver === 'smtp') return smtp_send($fromEmail, $toEmail, $message);

    // log driver: a complete .eml message on disk
    $dir = mail_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($subject)), '-');
    $file = $dir . '/' . date('Ymd-His') . '-' . substr($slug, 0, 40) . '-' . bin2hex(random_bytes(3)) . '.eml';
    return (bool)@file_put_contents($file, $message);
}

/**
 * Send one message through SMTP. Settings come from config 'mail.smtp':
 *   host, port (587), encryption ('tls' = STARTTLS, 'ssl' = TLS from the start, 'none'),
 *   username, password, timeout (seconds), verify_peer (keep true outside tests).
 * Returns false (and logs why) instead of throwing, so a mail problem never breaks a page.
 */
function smtp_send(string $from, string $to, string $message): bool
{
    $c = (array)config('mail.smtp', []) + ['host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'password' => '', 'timeout' => 15, 'verify_peer' => true];
    $enc = strtolower((string)$c['encryption']);
    $host = (string)$c['host'];
    if ($host === '') { error_log('BookTrack mail: mail.smtp.host is not set'); return false; }

    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => (bool)$c['verify_peer'], 'verify_peer_name' => (bool)$c['verify_peer'],
        'peer_name' => $host, 'SNI_enabled' => true,
    ]]);
    $errno = 0; $errstr = '';
    $sock = @stream_socket_client(($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . (int)$c['port'], $errno, $errstr, (float)$c['timeout'], STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) { error_log("BookTrack mail: can't connect to $host: $errstr"); return false; }
    stream_set_timeout($sock, (int)$c['timeout']);

    // One reply, possibly several lines ("250-..." then "250 ..."). Returns [code, text].
    $read = function () use ($sock): array {
        $text = '';
        while (($line = fgets($sock, 2048)) !== false) {
            $text .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        return [(int)substr($text, 0, 3), trim($text)];
    };
    // $secret: never write the line itself to the error log (it's the username or password)
    $cmd = function (string $line, array $ok, bool $secret = false) use ($sock, $read): string {
        if ($line !== '') fwrite($sock, $line . "\r\n");
        [$code, $text] = $read();
        if (!in_array($code, $ok, true)) throw new RuntimeException(($secret ? '(login)' : ($line ?: '(reply)')) . " → $text");
        return $text;
    };

    try {
        $me = parse_url((string)config('app_url', ''), PHP_URL_HOST) ?: (gethostname() ?: 'localhost');
        $cmd('', [220]);
        $ehlo = $cmd('EHLO ' . $me, [250]);
        if ($enc === 'tls') {
            if (!str_contains(strtoupper($ehlo), 'STARTTLS')) throw new RuntimeException('the server does not offer STARTTLS');
            $cmd('STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('TLS handshake failed');
            }
            $cmd('EHLO ' . $me, [250]);
        }
        if ((string)$c['username'] !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode((string)$c['username']), [334], true);
            $cmd(base64_encode((string)$c['password']), [235], true);
        }
        $cmd('MAIL FROM:<' . $from . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        // a line that starts with "." would end the message early, so it's doubled (RFC 5321 4.5.2)
        $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $message));
        fwrite($sock, str_replace("\n", "\r\n", rtrim($data, "\n")) . "\r\n.\r\n");
        $cmd('', [250]);
        @fwrite($sock, "QUIT\r\n");
        fclose($sock);
        return true;
    } catch (Throwable $e) {
        error_log('BookTrack mail: SMTP failed: ' . $e->getMessage());
        @fclose($sock);
        return false;
    }
}
