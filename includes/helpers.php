<?php
/**
 * Small helpers shared across pages: escaping, URLs, CSRF, flash messages,
 * formatting, uploads and the activity log.
 */

/** Escape for HTML output. */
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Base URL of the app, e.g. "/booktrack-v2/". Works in any sub-folder. */
function base_url(): string
{
    static $base = null;
    if ($base !== null) return $base;
    if (PHP_SAPI === 'cli') return $base = '/';

    $root   = str_replace('\\', '/', (string)realpath(APP_ROOT));
    $script = str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $rel    = ltrim(substr($script, strlen($root)), '/');
    $name   = $_SERVER['SCRIPT_NAME'] ?? '/';
    $base   = ($rel !== '' && str_ends_with($name, $rel)) ? substr($name, 0, -strlen($rel)) : '/';
    return $base ?: '/';
}

function url(string $path = '', array $query = []): string
{
    $u = base_url() . ltrim($path, '/');
    if ($query) $u .= '?' . http_build_query($query);
    return $u;
}

function asset(string $path): string
{
    $file = APP_ROOT . '/assets/' . $path;
    $v = is_file($file) ? filemtime($file) : 1;
    return url('assets/' . $path) . '?v=' . $v;
}

function redirect(string $path, array $query = []): void
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path, $query)));
    exit;
}

/** Redirect back to the previous page (same site only), or to $fallback. */
function back(string $fallback = 'index.php'): void
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $host = parse_url($ref, PHP_URL_HOST);
    if ($ref && $host && $host === parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
        header('Location: ' . $ref);
        exit;
    }
    redirect($fallback);
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input(string $key, $default = ''): string
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : (string)$default;
}

/**
 * Server-side length check to match the database columns.
 * $rules = ['field' => [maxChars, 'Label']]; returns readable error messages.
 */
function length_errors(array $data, array $rules): array
{
    $errors = [];
    foreach ($rules as $key => [$max, $label]) {
        if (mb_strlen((string)($data[$key] ?? '')) > $max) $errors[] = "$label can be at most $max characters.";
    }
    return $errors;
}

function input_int(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? $_GET[$key] ?? null;
    return is_numeric($v) ? (int)$v : $default;
}

// ---------------------------------------------------------------- CSRF

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

/** Validate the CSRF token on every POST. Accepts the form field or the X-CSRF-Token header. */
function verify_csrf(): void
{
    $sent = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        if (wants_json()) json_out(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 419);
        http_response_code(419);
        flash('error', 'Your session expired. Please try again.');
        back('index.php');
    }
}

// ---------------------------------------------------------------- flash + JSON

/**
 * Queue a message for the next page. $undo (optional) adds an Undo button that
 * posts $undo['fields'] back to $undo['url'].
 */
function flash(string $type, string $message, ?array $undo = null): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message, 'undo' => $undo];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function wants_json(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
}

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------------------------------------------------------------- formatting

function today(): string
{
    return date('Y-m-d');
}

function fmt_date(?string $date, string $format = 'j M Y'): string
{
    if (!$date) return '—';
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : '—';
}

/** Whole days from today to $date (negative when in the past). */
function days_until(string $date): int
{
    $a = new DateTimeImmutable(today());
    $b = new DateTimeImmutable(substr($date, 0, 10));
    return (int)$a->diff($b)->format('%r%a');
}

function due_label(string $due): string
{
    $d = days_until($due);
    if ($d < 0)  return 'Overdue by ' . abs($d) . ' day' . (abs($d) === 1 ? '' : 's');
    if ($d === 0) return 'Due today';
    if ($d === 1) return 'Due tomorrow';
    return 'Due in ' . $d . ' days';
}

function time_ago(?string $datetime): string
{
    if (!$datetime) return '';
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600) . ' h ago';
    if ($diff < 604800) return floor($diff / 86400) . ' d ago';
    return fmt_date($datetime);
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) $out .= mb_strtoupper(mb_substr($p, 0, 1));
    return $out ?: '?';
}

function plural(int $n, string $word, ?string $pluralWord = null): string
{
    return $n . ' ' . ($n === 1 ? $word : ($pluralWord ?? $word . 's'));
}

/**
 * Book cover: the uploaded image, or a generated typographic cover
 * (so books without artwork still look good on the shelf).
 */
function cover_html(array $book, string $class = 'cover'): string
{
    $title = e($book['title'] ?? '');
    if (!empty($book['cover']) && is_file(dirname(__DIR__) . '/' . ltrim((string) $book['cover'], '/'))) {
        return '<img class="' . e($class) . '" src="' . e(url($book['cover'])) . '" alt="Cover of ' . $title . '" loading="lazy" decoding="async">';
    }
    // one of five jacket layouts, picked from the title so a book always looks the same
    $variant = crc32('v' . ($book['title'] ?? '') . ($book['author'] ?? '')) % 5;
    return '<div class="' . e($class) . ' cover--generated cover--v' . $variant . '" style="--cover-bg:' . cover_color($book) . '" role="img" aria-label="Cover of ' . $title . '">'
        . '<span class="cover__title">' . $title . '</span>'
        . '<span class="cover__author">' . e($book['author'] ?? '') . '</span></div>';
}

/** Stable background colour for books without an uploaded cover. */
function cover_color(array $book): string
{
    $palettes = ['#3E5C8A', '#7A4A35', '#2F6B57', '#8A3B47', '#5B4E8C', '#4F5D73', '#2E6E7E', '#6B5040'];
    return $palettes[crc32((string)($book['title'] ?? '')) % count($palettes)];
}

/** Soft, blurred wash of a book's cover colours, placed behind a large cover. */
function cover_ambient(array $book): string
{
    $file = (string)($book['cover'] ?? '');
    $style = $file !== '' && is_file(dirname(__DIR__) . '/' . ltrim($file, '/'))
        ? "background-image: url('" . e(url($file)) . "')"
        : 'background-color: ' . cover_color($book);
    return '<span class="ambient" style="' . $style . '" aria-hidden="true"></span>';
}

/**
 * Small line illustrations for empty screens. Strokes use the text colour,
 * the accent uses the theme's gold, so they work in both themes.
 */
function empty_art(string $kind): string
{
    $g = 'var(--gold)';
    $art = [
        // three books on a shelf, one tipped over
        'shelf' => '<path d="M14 92h112"/><rect x="30" y="38" width="16" height="54" rx="2"/><rect x="50" y="28" width="14" height="64" rx="2"/>'
            . '<rect x="68" y="44" width="18" height="48" rx="2" fill="' . $g . '" stroke="' . $g . '" fill-opacity=".18"/><path d="M93 92l20-46 12 5-20 46"/><path d="M54 40h6M54 46h6M34 50h8"/>',
        // magnifier over an open book
        'search' => '<path d="M22 84c12-6 26-6 38 0 12-6 26-6 38 0V40c-12-6-26-6-38 0-12-6-26-6-38 0z"/><path d="M60 40v44"/>'
            . '<circle cx="96" cy="38" r="16" fill="' . $g . '" fill-opacity=".14" stroke="' . $g . '"/><path d="M107.5 49.5L122 64" stroke="' . $g . '" stroke-width="5"/>',
        // an open book with a heart rising from it
        'heart' => '<path d="M20 86c13-7 27-7 40 0 13-7 27-7 40 0V50c-13-7-27-7-40 0-13-7-27-7-40 0z"/><path d="M60 50v36"/>'
            . '<path d="M60 40s-14-8-14-18a7 7 0 0 1 14-3 7 7 0 0 1 14 3c0 10-14 18-14 18z" fill="' . $g . '" fill-opacity=".2" stroke="' . $g . '"/>',
        // two speech bubbles
        'chat' => '<path d="M18 26h64v40H42l-14 12V66H18z"/><path d="M30 40h40M30 52h26"/>'
            . '<path d="M90 44h32v30h-8v10l-12-10H90z" fill="' . $g . '" fill-opacity=".16" stroke="' . $g . '"/>',
        // a tick in a circle with sparkles
        'done' => '<circle cx="70" cy="54" r="30"/><path d="M57 55l9 9 18-20" stroke="' . $g . '" stroke-width="4"/>'
            . '<path d="M112 22v10M107 27h10M28 76v8M24 80h8M114 76l5 5" stroke="' . $g . '"/>',
        // stack of loan cards
        'loans' => '<rect x="34" y="20" width="72" height="44" rx="6"/><rect x="26" y="34" width="88" height="50" rx="6" fill="var(--surface)"/>'
            . '<rect x="38" y="46" width="16" height="26" rx="2" fill="' . $g . '" fill-opacity=".2" stroke="' . $g . '"/><path d="M62 52h38M62 62h26"/>',
        // two people
        'members' => '<circle cx="52" cy="40" r="13"/><path d="M28 86a24 24 0 0 1 48 0"/>'
            . '<circle cx="92" cy="46" r="11" fill="' . $g . '" fill-opacity=".16" stroke="' . $g . '"/><path d="M74 86a20 20 0 0 1 38 0" stroke="' . $g . '"/>',
        // a pulse line on a card
        'activity' => '<rect x="20" y="22" width="100" height="64" rx="8"/><path d="M30 60h18l8-20 12 34 10-24h32" stroke="' . $g . '" stroke-width="3"/>',
    ][$kind] ?? '';
    return '<svg class="empty__art" viewBox="0 0 140 100" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $art . '</svg>';
}

/** Inline stroke icon. */
function icon(string $name, string $class = 'icon'): string
{
    static $paths = [
        'compass'  => '<circle cx="12" cy="12" r="9"/><path d="M15.5 8.5l-2 5-5 2 2-5z"/>',
        'book'     => '<path d="M2 5h6a4 4 0 0 1 4 4v11a3 3 0 0 0-3-3H2z"/><path d="M22 5h-6a4 4 0 0 0-4 4v11a3 3 0 0 1 3-3h7z"/>',
        'books'    => '<path d="M4 4h4v16H4zM10 4h4v16h-4z"/><path d="M15.5 5.2l3.4-.9 3.1 15.1-3.4.9z"/>',
        'heart'    => '<path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/>',
        'message'  => '<path d="M4 5h16v11H8l-4 4z"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18.5 20a6.5 6.5 0 0 0-3-5.5"/>',
        'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'swap'     => '<path d="M7 4L3 8l4 4"/><path d="M3 8h13a5 5 0 0 1 0 10h-3"/>',
        'activity' => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'edit'     => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/>',
        'trash'    => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
        'check'    => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'x'        => '<path d="M6 6l12 12M18 6L6 18"/>',
        'menu'     => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'image'    => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/>',
        'bell'     => '<path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15z"/><path d="M10 20.5a2 2 0 0 0 4 0"/>',
        'sun'      => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon'     => '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>',
        'undo'     => '<path d="M9 14L4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/>',
        'share'    => '<circle cx="18" cy="5" r="2.6"/><circle cx="6" cy="12" r="2.6"/><circle cx="18" cy="19" r="2.6"/><path d="M8.3 13.3l7.4 4.4M15.7 6.3l-7.4 4.4"/>',
        'chevron-right' => '<path d="M9 6l6 6-6 6"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'alert'    => '<path d="M12 3l9.5 17h-19z"/><path d="M12 10v4M12 17.5v.01"/>',
        'file'     => '<path d="M14 3H6v18h12V7z"/><path d="M14 3v4h4M9 13h6M9 17h6"/>',
        'refresh'  => '<path d="M20 11a8 8 0 0 0-14.7-4M4 5v4h4"/><path d="M4 13a8 8 0 0 0 14.7 4M20 19v-4h-4"/>',
        'arrow-left' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'send'     => '<path d="M21 3L10 14M21 3l-7 18-4-7-7-4z"/>',
        'shield'   => '<path d="M12 3l8 3v6c0 4.5-3.4 8-8 9-4.6-1-8-4.5-8-9V6z"/>',
        'inbox'    => '<path d="M3 13l3-8h12l3 8v6H3z"/><path d="M3 13h5l1 3h6l1-3h5"/>',
    ];
    return '<svg class="' . e($class) . '" viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

// ---------------------------------------------------------------- pagination

function paginate(int $total, int $perPage = 12): array
{
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = min($pages, max(1, input_int('page', 1)));
    return ['page' => $page, 'pages' => $pages, 'per' => $perPage, 'offset' => ($page - 1) * $perPage, 'total' => $total];
}

function pagination_links(array $p): string
{
    if ($p['pages'] <= 1) return '';
    $q = $_GET;
    $html = '<nav class="pagination" aria-label="Pagination">';
    $q['page'] = $p['page'] - 1;
    $html .= $p['page'] > 1
        ? '<a class="btn btn--ghost btn--sm" href="?' . e(http_build_query($q)) . '">Previous</a>'
        : '<span class="btn btn--ghost btn--sm is-disabled" aria-disabled="true">Previous</span>';
    $html .= '<span class="pagination__info">Page ' . $p['page'] . ' of ' . $p['pages'] . '</span>';
    $q['page'] = $p['page'] + 1;
    $html .= $p['page'] < $p['pages']
        ? '<a class="btn btn--ghost btn--sm" href="?' . e(http_build_query($q)) . '">Next</a>'
        : '<span class="btn btn--ghost btn--sm is-disabled" aria-disabled="true">Next</span>';
    return $html . '</nav>';
}

// ---------------------------------------------------------------- activity log

function log_activity(string $action, string $details = ''): void
{
    $u = current_user();
    q('INSERT INTO activity_log (user_id, actor, action, details, created_at) VALUES (?, ?, ?, ?, ?)', [
        $u['id'] ?? null,
        $u['username'] ?? 'system',
        $action,
        mb_substr($details, 0, 255),
        date('Y-m-d H:i:s'),
    ]);
}

// ---------------------------------------------------------------- uploads

/**
 * Save an uploaded cover image. Validates the real file type, resizes large
 * images (when GD is available) and returns the relative path, or null when
 * no file was sent. Throws RuntimeException with a friendly message on error.
 */
function store_cover(string $field): ?string
{
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('The cover image could not be uploaded.');
    if ($f['size'] > config('uploads.max_cover_mb') * 1024 * 1024) {
        throw new RuntimeException('Cover images must be under ' . config('uploads.max_cover_mb') . ' MB.');
    }

    return store_cover_file($f['tmp_name'], true);
}

/**
 * Validate an image file and save it to uploads/covers, resized to at most
 * 600px wide. $isUpload moves a PHP upload; otherwise the file is copied.
 */
function store_cover_file(string $path, bool $isUpload): string
{
    $info = @getimagesize($path);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) throw new RuntimeException('Covers must be JPG, PNG or WebP images.');

    $dir = APP_ROOT . '/uploads/covers';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(10));

    // Resize to max 600px wide to keep pages fast.
    if (function_exists('imagecreatefromstring') && $info[0] > 600) {
        $src = @imagecreatefromstring((string)file_get_contents($path));
        if ($src) {
            $w = 600;
            $h = (int)round($info[1] * (600 / $info[0]));
            $dst = imagecreatetruecolor($w, $h);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
            imagejpeg($dst, "$dir/$name.jpg", 84);
            return "uploads/covers/$name.jpg";
        }
    }

    $ext = $types[$info[2]];
    $saved = $isUpload ? move_uploaded_file($path, "$dir/$name.$ext") : copy($path, "$dir/$name.$ext");
    if (!$saved) throw new RuntimeException('Could not save the cover image.');
    return "uploads/covers/$name.$ext";
}

/** Save an uploaded PDF (stored outside public access, served by read.php). */
function store_pdf(string $field): ?string
{
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('The PDF could not be uploaded.');
    if ($f['size'] > config('uploads.max_pdf_mb') * 1024 * 1024) {
        throw new RuntimeException('PDFs must be under ' . config('uploads.max_pdf_mb') . ' MB.');
    }
    $head = (string)file_get_contents($f['tmp_name'], false, null, 0, 5);
    if ($head !== '%PDF-') throw new RuntimeException('That file is not a valid PDF.');

    $dir = APP_ROOT . '/uploads/pdfs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(10)) . '.pdf';
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) throw new RuntimeException('Could not save the PDF.');
    return "uploads/pdfs/$name";
}

function delete_upload(?string $relPath): void
{
    if (!$relPath || !str_starts_with($relPath, 'uploads/')) return;
    $full = APP_ROOT . '/' . $relPath;
    if (is_file($full) && !str_contains(basename($relPath), 'seed-')) @unlink($full);
}

function activity_label(string $action): string
{
    return [
        'loan.created' => 'Book borrowed',
        'loan.returned' => 'Book returned',
        'loan.return_requested' => 'Return requested',
        'loan.renewed' => 'Loan renewed',
        'loan.deleted' => 'Loan record deleted',
        'book.created' => 'Book added',
        'book.updated' => 'Book updated',
        'book.deleted' => 'Book deleted',
        'book.covers' => 'Covers added',
        'loan.return_cancelled' => 'Return cancelled',
        'member.created' => 'Member added',
        'member.updated' => 'Member updated',
        'member.deleted' => 'Member deleted',
        'member.registered' => 'Member signed up',
        'message.sent' => 'New message',
        'message.replied' => 'Message answered',
        'message.deleted' => 'Message deleted',
        'user.password_changed' => 'Password changed',
    ][$action] ?? ucfirst(str_replace(['.', '_'], ' ', $action));
}

function activity_dot(string $action): string
{
    if (str_contains($action, 'deleted')) return 'dot--red';
    if (str_starts_with($action, 'loan.returned')) return 'dot--green';
    if (str_starts_with($action, 'loan')) return 'dot--blue';
    if (str_starts_with($action, 'message')) return '';
    return 'dot--muted';
}

function format_bytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

/**
 * Attributes for an input that may have a validation error: marks it invalid
 * and links it to the message element (#{id}-error) for screen readers.
 */
function field_error_attrs(string $id, array $errors, string $key): string
{
    return isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="' . e($id) . '-error"' : '';
}

function field_error(string $id, array $errors, string $key): string
{
    return isset($errors[$key]) ? '<p class="field__error" id="' . e($id) . '-error">' . icon('alert') . e($errors[$key]) . '</p>' : '';
}
