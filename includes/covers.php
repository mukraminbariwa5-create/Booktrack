<?php
/**
 * Real book covers from Open Library (https://openlibrary.org), a free public
 * catalogue. Looks a book up by ISBN first, then by title and author, checks
 * that what comes back is a real image (not a 1x1 placeholder) and saves it
 * like an uploaded cover.
 */

/** Base URLs; BOOKTRACK_OPENLIBRARY points both at a stand-in server for tests. */
function covers_api_base(): string
{
    return rtrim(getenv('BOOKTRACK_OPENLIBRARY') ?: (string)config('covers.api', 'https://openlibrary.org'), '/');
}

function covers_image_base(): string
{
    return rtrim(getenv('BOOKTRACK_OPENLIBRARY') ?: (string)config('covers.images', 'https://covers.openlibrary.org'), '/');
}

/**
 * Small HTTP GET. Returns [status, body] or null when the request failed
 * outright (offline, DNS, TLS). Uses cURL when available, otherwise streams.
 */
function covers_http_get(string $url, int $maxBytes = 6_000_000): ?array
{
    $ca = covers_ca_bundle();
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $body = '';
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_USERAGENT      => 'BookTrack/2.0 (library catalogue)',
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body, $maxBytes) {
                $body .= $chunk;
                return strlen($body) > $maxBytes ? 0 : strlen($chunk);   // stop oversized downloads
            },
        ]);
        if ($ca) curl_setopt($ch, CURLOPT_CAINFO, $ca);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($ok === false && $status === 0) return null;
        return [$status, $body];
    }

    $ctx = stream_context_create([
        'http' => ['timeout' => 12, 'user_agent' => 'BookTrack/2.0 (library catalogue)', 'ignore_errors' => true, 'follow_location' => 1],
        'ssl'  => $ca ? ['cafile' => $ca] : [],
    ]);
    $body = @file_get_contents($url, false, $ctx, 0, $maxBytes);
    if ($body === false) return null;
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1];   // last one wins (after redirects)
    }
    return [$status, $body];
}

/**
 * XAMPP on Windows often has no CA bundle configured for PHP, which makes every
 * HTTPS request fail. Use the configured one, or the bundles XAMPP ships with.
 */
function covers_ca_bundle(): ?string
{
    foreach ([ini_get('curl.cainfo'), ini_get('openssl.cafile')] as $set) {
        if ($set && is_file($set)) return null;   // PHP is already set up
    }
    $phpDir = dirname(PHP_BINARY);
    foreach ([
        $phpDir . '/extras/ssl/cacert.pem',
        dirname($phpDir) . '/php/extras/ssl/cacert.pem',
        dirname($phpDir) . '/apache/bin/curl-ca-bundle.crt',
        'C:/xampp/php/extras/ssl/cacert.pem',
        'C:/xampp/apache/bin/curl-ca-bundle.crt',
    ] as $path) {
        if (is_file($path)) return $path;
    }
    return null;
}

/** Candidate cover image URLs for a book, best first. */
function cover_candidates(array $book): array
{
    $urls = [];
    $isbn = strtoupper(preg_replace('/[^0-9Xx]/', '', (string)($book['isbn'] ?? '')));
    if (strlen($isbn) === 10 || strlen($isbn) === 13) {
        $urls[] = covers_image_base() . '/b/isbn/' . $isbn . '-L.jpg?default=false';
    }
    $title = trim((string)($book['title'] ?? ''));
    if ($title !== '') {
        $query = http_build_query(array_filter([
            'title'  => $title,
            'author' => trim((string)($book['author'] ?? '')),
            'limit'  => 5,
            'fields' => 'cover_i',
        ]));
        $res = covers_http_get(covers_api_base() . '/search.json?' . $query, 2_000_000);
        if ($res && $res[0] === 200) {
            $data = json_decode($res[1], true);
            foreach ($data['docs'] ?? [] as $doc) {
                if (!empty($doc['cover_i'])) {
                    $urls[] = covers_image_base() . '/b/id/' . (int)$doc['cover_i'] . '-L.jpg';
                    break;
                }
            }
        }
    }
    return $urls;
}

/** True when $url is an Open Library cover image (so a form can't make us fetch anything else). */
function is_cover_source_url(string $url): bool
{
    $base = covers_image_base() . '/b/';
    return str_starts_with($url, $base) && !preg_match('/\s/', $url);
}

/** Find a cover URL for a book without downloading it (for the "Find cover" preview). */
function find_cover_url(array $book): ?string
{
    foreach (cover_candidates($book) as $url) {
        // ask for the image so a missing ISBN cover (404 with default=false) is skipped
        $res = covers_http_get($url);
        if ($res && $res[0] === 200 && cover_image_ok($res[1])) return $url;
    }
    return null;
}

/** Download a cover from an Open Library URL and store it. Returns the stored path or null. */
function store_cover_from_url(string $url): ?string
{
    if (!is_cover_source_url($url)) return null;
    $res = covers_http_get($url);
    if (!$res || $res[0] !== 200 || !cover_image_ok($res[1])) return null;
    $tmp = tempnam(sys_get_temp_dir(), 'btc');
    file_put_contents($tmp, $res[1]);
    try {
        return store_cover_file($tmp, false);
    } finally {
        @unlink($tmp);
    }
}

/** Look a book up and store its cover. Returns the stored path or null. */
function fetch_cover(array $book): ?string
{
    foreach (cover_candidates($book) as $url) {
        if ($path = store_cover_from_url($url)) return $path;
    }
    return null;
}

/** A real cover: JPEG/PNG/WebP and not Open Library's tiny "no cover" placeholder. */
function cover_image_ok(string $bytes): bool
{
    if (strlen($bytes) < 1000) return false;
    $info = @getimagesizefromstring($bytes);
    return $info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) && $info[0] >= 60 && $info[1] >= 80;
}
