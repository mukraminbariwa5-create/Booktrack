<?php
/**
 * A small QR code generator (ISO/IEC 18004), enough for library card numbers:
 * byte mode, error correction level M, versions 1–6 (up to 106 bytes), with
 * the standard mask selection. No dependencies; renders to inline SVG.
 */

final class QrCode
{
    /** [total data codewords, EC codewords per block, number of blocks] for level M */
    private const M = [1 => [16, 10, 1], 2 => [28, 16, 1], 3 => [44, 26, 1], 4 => [64, 18, 2], 5 => [86, 24, 2], 6 => [108, 16, 4]];
    private const ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34]];

    private int $size;
    private array $m = [];      // [row][col] => bool (dark)
    private array $fn = [];     // [row][col] => true for function patterns
    private static array $exp = [];
    private static array $log = [];

    public function __construct(private string $text)
    {
        $version = 0;
        foreach (self::M as $v => [$data]) {
            if (strlen($text) + 2 <= $data) { $version = $v; break; }   // 4-bit mode + 8-bit count ≈ 2 bytes
        }
        if (!$version) throw new InvalidArgumentException('Text is too long for this QR generator.');
        $this->build($version);
    }

    public function size(): int { return $this->size; }
    public function dark(int $row, int $col): bool { return $this->m[$row][$col]; }

    /** Inline SVG with a 4-module quiet zone, drawn as one path. */
    public function svg(string $class = 'qr', string $label = ''): string
    {
        $q = 4; $n = $this->size + 2 * $q; $d = '';
        for ($r = 0; $r < $this->size; $r++) {
            for ($c = 0; $c < $this->size; $c++) {
                if (!$this->m[$r][$c]) continue;
                $run = 1;
                while ($c + $run < $this->size && $this->m[$r][$c + $run]) $run++;
                $d .= 'M' . ($c + $q) . ' ' . ($r + $q) . "h{$run}v1h-{$run}z";
                $c += $run - 1;
            }
        }
        $aria = $label !== '' ? ' role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '"' : ' aria-hidden="true"';
        return '<svg class="' . htmlspecialchars($class, ENT_QUOTES) . '" viewBox="0 0 ' . $n . ' ' . $n . '" shape-rendering="crispEdges"' . $aria . '>'
            . '<rect width="' . $n . '" height="' . $n . '" fill="#fff"/><path fill="#000" d="' . $d . '"/></svg>';
    }

    // ------------------------------------------------------------ building

    private function build(int $version): void
    {
        $this->size = 17 + 4 * $version;
        $row = array_fill(0, $this->size, false);
        $this->m = array_fill(0, $this->size, $row);
        $this->fn = array_fill(0, $this->size, $row);

        $this->drawFunctionPatterns($version);
        $codewords = $this->addErrorCorrection($this->encodeData($version), $version);
        $this->placeCodewords($codewords);

        // Try all 8 masks and keep the one with the lowest penalty.
        $best = 0; $bestScore = PHP_INT_MAX; $base = $this->m;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->m = $base;
            $this->applyMask($mask);
            $this->drawFormatBits($mask);
            $score = $this->penalty();
            if ($score < $bestScore) { $bestScore = $score; $best = $mask; }
        }
        $this->m = $base;
        $this->applyMask($best);
        $this->drawFormatBits($best);
    }

    private function set(int $r, int $c, bool $dark): void
    {
        $this->m[$r][$c] = $dark;
        $this->fn[$r][$c] = true;
    }

    private function drawFunctionPatterns(int $version): void
    {
        $s = $this->size;
        for ($i = 0; $i < $s; $i++) {           // timing patterns
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [3, $s - 4], [$s - 4, 3]] as [$cr, $cc]) {   // finders + separators
            for ($dr = -4; $dr <= 4; $dr++) {
                for ($dc = -4; $dc <= 4; $dc++) {
                    $r = $cr + $dr; $c = $cc + $dc;
                    if ($r < 0 || $r >= $s || $c < 0 || $c >= $s) continue;
                    $dist = max(abs($dr), abs($dc));
                    $this->set($r, $c, $dist !== 2 && $dist !== 4);
                }
            }
        }
        $pos = self::ALIGN[$version];
        foreach ($pos as $i => $ar) {
            foreach ($pos as $j => $ac) {
                $last = count($pos) - 1;
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) continue; // under finders
                for ($dr = -2; $dr <= 2; $dr++) {
                    for ($dc = -2; $dc <= 2; $dc++) $this->set($ar + $dr, $ac + $dc, max(abs($dr), abs($dc)) !== 1);
                }
            }
        }
        $this->drawFormatBits(0);   // reserve the format areas (real bits are drawn after masking)
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (0b00 << 3) | $mask;            // level M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * 0x537);
        $bits = (($data << 10) | ($rem & 0x3FF)) ^ 0x5412;
        $bit = fn(int $i) => (($bits >> $i) & 1) === 1;
        $s = $this->size;

        for ($i = 0; $i <= 5; $i++) $this->set($i, 8, $bit($i));
        $this->set(7, 8, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(8, 7, $bit(8));
        for ($i = 9; $i < 15; $i++) $this->set(8, 14 - $i, $bit($i));

        for ($i = 0; $i < 8; $i++) $this->set(8, $s - 1 - $i, $bit($i));
        for ($i = 8; $i < 15; $i++) $this->set($s - 15 + $i, 8, $bit($i));
        $this->set($s - 8, 8, true);            // the dark module
    }

    private function encodeData(int $version): array
    {
        $bits = [];
        $push = function (int $value, int $len) use (&$bits) {
            for ($i = $len - 1; $i >= 0; $i--) $bits[] = ($value >> $i) & 1;
        };
        $push(0b0100, 4);                        // byte mode
        $push(strlen($this->text), 8);
        foreach (str_split($this->text) as $ch) $push(ord($ch), 8);

        $capacity = self::M[$version][0] * 8;
        $push(0, min(4, $capacity - count($bits)));          // terminator
        while (count($bits) % 8) $bits[] = 0;
        $bytes = [];
        foreach (array_chunk($bits, 8) as $chunk) $bytes[] = bindec(implode('', $chunk));
        for ($pad = 0xEC; count($bytes) < self::M[$version][0]; $pad ^= 0xEC ^ 0x11) $bytes[] = $pad;
        return $bytes;
    }

    private function addErrorCorrection(array $data, int $version): array
    {
        [$total, $ecLen, $blocks] = self::M[$version];
        $per = intdiv($total, $blocks);
        $dataBlocks = array_chunk($data, $per);
        $ecBlocks = array_map(fn($b) => self::reedSolomon($b, $ecLen), $dataBlocks);
        $out = [];
        for ($i = 0; $i < $per; $i++) foreach ($dataBlocks as $b) $out[] = $b[$i];
        for ($i = 0; $i < $ecLen; $i++) foreach ($ecBlocks as $b) $out[] = $b[$i];
        return $out;
    }

    private function placeCodewords(array $codewords): void
    {
        $s = $this->size; $i = 0; $total = count($codewords) * 8;
        for ($right = $s - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right = 5;
            for ($vert = 0; $vert < $s; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $c = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $r = $upward ? $s - 1 - $vert : $vert;
                    if (!$this->fn[$r][$c] && $i < $total) {
                        $this->m[$r][$c] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($r = 0; $r < $this->size; $r++) {
            for ($c = 0; $c < $this->size; $c++) {
                if ($this->fn[$r][$c]) continue;
                $flip = match ($mask) {
                    0 => ($c + $r) % 2 === 0,
                    1 => $r % 2 === 0,
                    2 => $c % 3 === 0,
                    3 => ($c + $r) % 3 === 0,
                    4 => (intdiv($c, 3) + intdiv($r, 2)) % 2 === 0,
                    5 => ($c * $r) % 2 + ($c * $r) % 3 === 0,
                    6 => (($c * $r) % 2 + ($c * $r) % 3) % 2 === 0,
                    7 => ((($c + $r) % 2) + ($c * $r) % 3) % 2 === 0,
                };
                if ($flip) $this->m[$r][$c] = !$this->m[$r][$c];
            }
        }
    }

    /** The standard penalty score (lower reads more reliably). */
    private function penalty(): int
    {
        $s = $this->size; $score = 0; $dark = 0;
        $lines = [];
        for ($i = 0; $i < $s; $i++) {
            $row = []; $col = [];
            for ($j = 0; $j < $s; $j++) { $row[] = $this->m[$i][$j]; $col[] = $this->m[$j][$i]; }
            $lines[] = $row; $lines[] = $col;
        }
        foreach ($lines as $line) {
            $run = 1;
            for ($j = 1; $j <= $s; $j++) {
                if ($j < $s && $line[$j] === $line[$j - 1]) { $run++; continue; }
                if ($run >= 5) $score += 3 + ($run - 5);
                $run = 1;
            }
            $str = implode('', array_map(fn($b) => $b ? '1' : '0', $line));
            $score += 40 * (substr_count($str, '10111010000') + substr_count($str, '00001011101'));
        }
        for ($r = 0; $r < $s; $r++) {
            for ($c = 0; $c < $s; $c++) {
                if ($this->m[$r][$c]) $dark++;
                if ($r < $s - 1 && $c < $s - 1) {
                    $v = $this->m[$r][$c];
                    if ($this->m[$r][$c + 1] === $v && $this->m[$r + 1][$c] === $v && $this->m[$r + 1][$c + 1] === $v) $score += 3;
                }
            }
        }
        $score += 10 * (int)floor(abs($dark * 100 / ($s * $s) - 50) / 5);
        return $score;
    }

    // ------------------------------------------------------------ Reed–Solomon over GF(256)

    private static function gf(): void
    {
        if (self::$exp) return;
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) $x ^= 0x11D;
        }
    }

    private static function mul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) return 0;
        return self::$exp[(self::$log[$a] + self::$log[$b]) % 255];
    }

    private static function reedSolomon(array $data, int $degree): array
    {
        self::gf();
        $gen = [1];                                         // Π (x − α^i), highest power first
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $coef) {
                $next[$j] ^= $coef;
                $next[$j + 1] ^= self::mul($coef, self::$exp[$i]);
            }
            $gen = $next;
        }
        $res = array_fill(0, $degree, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ $res[0];
            array_shift($res);
            $res[] = 0;
            for ($j = 0; $j < $degree; $j++) $res[$j] ^= self::mul($gen[$j + 1], $factor);
        }
        return $res;
    }
}

/** The QR for a text, as inline SVG. */
function qr_svg(string $text, string $class = 'qr', string $label = ''): string
{
    return (new QrCode($text))->svg($class, $label);
}
