<?php
/*
 * Bundled copy of gunsnprices/gunsnprices (MIT License: LICENSE in the gunsnprices folder) for the GunsNPrices OpenCart extension.
 * Changed by tools/vendor-parser.php: the namespace GunsNPrices was renamed to GunsNPricesCpr\Vendor\GunsNPrices.
 */
namespace GunsNPricesCpr\Vendor\GunsNPrices\Internal;

/**
 * Translates JavaScript regular expressions (the dialect the rules are written in) to PCRE with the same meaning.
 *
 * PHP's /u flag turns on Unicode properties, so \d, \w, \s and \b would match more than in JavaScript. They are
 * rewritten to explicit ASCII classes (\s to JavaScript's whitespace set), "." excludes the JavaScript line
 * terminators, "$" matches only at the very end (D flag) and \v is a vertical tab, not a class.
 *
 * On a subject without non-ASCII characters PCRE's own \d, \w, \s and \b mean exactly what they mean in JavaScript,
 * and they are much faster (PCRE can still skip ahead to a possible first character). So every pattern also gets a
 * "native" variant, built on first use, and test(), exec(), all() and replace() pick it for ASCII subjects.
 *
 * @internal
 */
final class Rx
{
    /** JavaScript \s, as a character class body */
    const WS = '\t\n\x0B\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';
    const WORD = 'A-Za-z0-9_';

    /** Throw on PCRE errors instead of treating them as "no match" (tests turn this on). */
    public static $strict = false;

    /** Never use the native variants (tests compare both). */
    public static $exactOnly = false;

    /** @var array<string, array<string, string>> */
    private static $cache = [];

    /** @var array<string, array{0: string, 1: bool, 2: bool}> exact pattern => [JavaScript source, i flag, spaces] */
    private static $sources = [];

    /** @var array<string, string> exact pattern => native pattern for ASCII subjects */
    private static $native = [];

    /**
     * PCRE pattern for a JavaScript regex source.
     *
     * @param string $source JavaScript regex source (what goes between the slashes)
     * @param string $flags "" or "i"
     * @param bool $spaces the subject has no whitespace but " " (already collapsed), so \s can be a plain space
     */
    public static function p(string $source, string $flags = '', bool $spaces = false): string
    {
        $k = $flags . ($spaces ? 's' : '');
        if (isset(self::$cache[$k][$source])) {
            return self::$cache[$k][$source];
        }
        $ci = strpos($flags, 'i') !== false;
        $exact = '/' . self::translate($source, $ci, $spaces, false) . '/uD' . ($ci ? 'i' : '');
        self::$sources[$exact] = [$source, $ci, $spaces];
        return self::$cache[$k][$source] = $exact;
    }

    /** The pattern to run on $s: the native variant when $s is ASCII-only. */
    private static function pick(string $pattern, string $s): string
    {
        if (self::$exactOnly || !isset(self::$sources[$pattern]) || preg_match('/[\x80-\xFF]/', $s) === 1) {
            return $pattern;
        }
        if (!isset(self::$native[$pattern])) {
            $src = self::$sources[$pattern];
            self::$native[$pattern] = '/' . self::translate($src[0], $src[1], $src[2], true) . '/uD' . ($src[1] ? 'i' : '');
        }
        return self::$native[$pattern];
    }

    /** RegExp.prototype.test */
    public static function test(string $pattern, string $s): bool
    {
        $pattern = self::pick($pattern, $s);
        $r = preg_match($pattern, $s);
        if ($r === false) {
            self::fail($pattern);
        }
        return $r === 1;
    }

    /**
     * RegExp.prototype.exec without the g flag: [groups (null when not set), byte offset of the match] or null.
     *
     * @return array{0: array<int, string|null>, 1: int}|null
     */
    public static function exec(string $pattern, string $s, int $offset = 0): ?array
    {
        $pattern = self::pick($pattern, $s);
        $r = preg_match($pattern, $s, $m, PREG_OFFSET_CAPTURE, $offset);
        if ($r === false) {
            self::fail($pattern);
        }
        if ($r !== 1) {
            return null;
        }
        return self::groups($m);
    }

    /**
     * String.prototype.matchAll: every match as [groups, byte offset].
     *
     * @return array<int, array{0: array<int, string|null>, 1: int}>
     */
    public static function all(string $pattern, string $s): array
    {
        $pattern = self::pick($pattern, $s);
        $r = preg_match_all($pattern, $s, $ms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($r === false) {
            self::fail($pattern);
        }
        $out = [];
        if ($r) {
            foreach ($ms as $m) {
                $out[] = self::groups($m);
            }
        }
        return $out;
    }

    /** String.prototype.replace with a global regex and a JavaScript replacement string ($1, $&, $$). */
    public static function replace(string $pattern, string $to, string $s): string
    {
        if (strpos($to, '$') === false) {
            $php = str_replace('\\', '\\\\', $to);
        } else {
            $php = self::replacement($to);
        }
        // the replacement text is ASCII in every rule, so an ASCII subject stays ASCII
        $r = preg_replace(self::pick($pattern, $s), $php, $s);
        if ($r === null) {
            self::fail($pattern);
            return $s;
        }
        return $r;
    }

    /**
     * @param array $m preg match with PREG_OFFSET_CAPTURE
     * @return array{0: array<int, string|null>, 1: int}
     */
    private static function groups(array $m): array
    {
        $g = [];
        foreach ($m as $i => $v) {
            if (is_int($i)) {
                $g[$i] = $v[1] < 0 ? null : $v[0];
            }
        }
        return [$g, $m[0][1]];
    }

    // ext-ctype is not always installed
    private static function digits(string $c): bool
    {
        return $c !== '' && strspn($c, '0123456789') === strlen($c);
    }

    private static function letter(string $c): bool
    {
        return $c !== '' && strspn($c, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ') === strlen($c);
    }

    private static function hex(string $c): bool
    {
        return $c !== '' && strspn($c, '0123456789abcdefABCDEF') === strlen($c);
    }

    private static function fail(string $pattern)
    {
        if (self::$strict) {
            throw new \RuntimeException('GunsNPrices: PCRE error ' . preg_last_error() . ' in ' . substr($pattern, 0, 200));
        }
    }

    /**
     * JavaScript replacement string to a preg_replace one: $n and $nn are groups, $& the match, $$ a dollar.
     * Unlike JavaScript, $nn is always read as a two-digit group number (the data only uses $1 and $2).
     */
    private static function replacement(string $to): string
    {
        $out = '';
        $n = strlen($to);
        for ($i = 0; $i < $n; $i++) {
            $c = $to[$i];
            if ($c === '$' && $i + 1 < $n) {
                $e = $to[$i + 1];
                if ($e === '$') {
                    $out .= '\\$';
                    $i++;
                    continue;
                }
                if ($e === '&') {
                    $out .= '${0}';
                    $i++;
                    continue;
                }
                if (self::digits($e) && $e !== '0') {
                    $num = $e;
                    $i++;
                    if ($i + 1 < $n && self::digits($to[$i + 1])) {
                        $num .= $to[$i + 1];
                        $i++;
                    }
                    $out .= '${' . $num . '}';
                    continue;
                }
                if ($e === '`' || $e === "'") {
                    throw new \LogicException('GunsNPrices: unsupported replacement ' . $to);
                }
            }
            if ($c === '\\') {
                $out .= '\\\\';
            } elseif ($c === '$') {
                // a lone dollar: keep it literal even if a digit or { follows
                $out .= '\\$';
            } else {
                $out .= $c;
            }
        }
        return $out;
    }

    /**
     * Under /iu PCRE matches "s" with U+017F (long s) and "k" with U+212A (Kelvin sign); a JavaScript regex without
     * the u flag does not (no other non-ASCII character folds to ASCII in PCRE). A literal s or k becomes an ASCII-only
     * class, and a character class that would take or drop one of the two is corrected.
     */
    private static function literal(string $c, bool $ci): string
    {
        if ($ci && ($c === 's' || $c === 'S' || $c === 'k' || $c === 'K')) {
            $lo = strtolower($c);
            return '(?-i:[' . $lo . strtoupper($lo) . '])';
        }
        return $c;
    }

    private static function fixClass(string $class): string
    {
        $add = '';
        $drop = '';
        foreach (["\u{17F}" => '\x{17F}', "\u{212A}" => '\x{212A}'] as $ch => $esc) {
            $js = preg_match('/' . $class . '/u', $ch) === 1;
            $pcre = preg_match('/' . $class . '/iu', $ch) === 1;
            if ($js && !$pcre) {
                $add .= $esc;
            } elseif ($pcre && !$js) {
                $drop .= $esc;
            }
        }
        if ($add === '' && $drop === '') {
            return $class;
        }
        // the guards are case-sensitive: under /i, [\x{17F}] would match "s" as well
        $body = $drop === '' ? $class : '(?-i:(?![' . $drop . ']))' . $class;
        return '(?:' . ($add === '' ? '' : '(?-i:[' . $add . '])|') . $body . ')';
    }

    /**
     * @param bool $native the subject is ASCII-only: keep PCRE's own \d, \w, \s and \b (same meaning there, faster)
     */
    private static function translate(string $s, bool $ci, bool $spaces, bool $native): string
    {
        $ws = $spaces ? ' ' : self::WS;
        $w = '[' . self::WORD . ']';
        $nw = '[^' . self::WORD . ']';
        $b = '(?<=' . $w . ')(?!' . $w . ')|(?<!' . $w . ')(?=' . $w . ')';
        $nb = '(?<=' . $w . ')(?=' . $w . ')|(?<!' . $w . ')(?!' . $w . ')';
        // \w and \b are ASCII-only in JavaScript, also with the i flag
        $ascii = $ci ? '(?-i:%s)' : '(?:%s)';
        $out = '';
        $n = strlen($s);
        $i = 0;
        $class = false;
        $classStart = 0;
        while ($i < $n) {
            $c = $s[$i];
            if ($c === '\\') {
                if ($i + 1 >= $n) {
                    $out .= '\\\\';
                    $i++;
                    continue;
                }
                $e = $s[$i + 1];
                $i += 2;
                if ($native && strpos('dDwWsSbB', $e) !== false && !($class && ($e === 'b' || $e === 'B'))) {
                    if ($spaces && ($e === 's' || $e === 'S')) {
                        $out .= $e === 's' ? ' ' : ($class ? '' : '[^ ]');
                        if ($class && $e === 'S') {
                            throw new \LogicException('GunsNPrices: \\S inside a character class is not supported');
                        }
                    } else {
                        $out .= '\\' . $e;
                    }
                    continue;
                }
                switch ($e) {
                    case 'd':
                        $out .= $class ? '0-9' : '[0-9]';
                        break;
                    case 'w':
                        $out .= $class ? self::WORD : sprintf($ascii, $w);
                        break;
                    case 's':
                        $out .= $class ? $ws : '[' . $ws . ']';
                        break;
                    case 'D':
                    case 'W':
                    case 'S':
                        if ($class) {
                            throw new \LogicException('GunsNPrices: \\' . $e . ' inside a character class is not supported');
                        }
                        $out .= $e === 'D' ? '[^0-9]' : ($e === 'W' ? sprintf($ascii, $nw) : '[^' . $ws . ']');
                        break;
                    case 'b':
                        $out .= $class ? '\x08' : sprintf($ascii, $b);
                        break;
                    case 'B':
                        $out .= $class ? 'B' : sprintf($ascii, $nb);
                        break;
                    case 'v':
                        $out .= '\x0B';
                        break;
                    case 'f':
                    case 'n':
                    case 'r':
                    case 't':
                        $out .= '\\' . $e;
                        break;
                    case 'c':
                        if ($i < $n && self::letter($s[$i])) {
                            $out .= '\x{' . dechex(ord($s[$i]) % 32) . '}';
                            $i++;
                        } else {
                            $out .= '\\\\c';
                        }
                        break;
                    case 'x':
                        if ($i + 1 < $n && self::hex($s[$i]) && self::hex($s[$i + 1])) {
                            $out .= '\x{' . substr($s, $i, 2) . '}';
                            $i += 2;
                        } else {
                            $out .= 'x';
                        }
                        break;
                    case 'u':
                        if ($i + 3 < $n && self::hex(substr($s, $i, 4))) {
                            $out .= '\x{' . substr($s, $i, 4) . '}';
                            $i += 4;
                        } else {
                            $out .= 'u';
                        }
                        break;
                    case '0':
                        if ($i < $n && self::digits($s[$i])) {
                            throw new \LogicException('GunsNPrices: octal escapes are not supported');
                        }
                        $out .= '\x{0}';
                        break;
                    default:
                        if (self::digits($e)) {
                            if ($class) {
                                throw new \LogicException('GunsNPrices: octal escapes are not supported');
                            }
                            $num = $e;
                            while ($i < $n && self::digits($s[$i])) {
                                $num .= $s[$i++];
                            }
                            $out .= '\g{' . $num . '}';
                        } elseif (self::letter($e)) {
                            // identity escape of a letter (\k, \h, ...): the letter itself in JavaScript
                            $out .= $class || $native ? $e : self::literal($e, $ci);
                        } elseif (ord($e) >= 0x80) {
                            // escaped non-ASCII character: copy the whole UTF-8 sequence, it is a literal
                            $len = ord($e) >= 0xF0 ? 4 : (ord($e) >= 0xE0 ? 3 : 2);
                            $out .= substr($s, $i - 1, $len);
                            $i += $len - 1;
                        } else {
                            $out .= '\\' . $e;
                        }
                }
                continue;
            }
            if ($class) {
                if ($c === ']') {
                    $class = false;
                    $out .= ']';
                    if ($ci && !$native) {
                        $out = substr($out, 0, $classStart) . self::fixClass(substr($out, $classStart));
                    }
                } elseif ($c === '[') {
                    $out .= '\[';
                } elseif ($c === '/') {
                    $out .= '\/';
                } else {
                    $out .= $c;
                }
                $i++;
                continue;
            }
            switch ($c) {
                case '[':
                    if (substr($s, $i, 2) === '[]') {
                        $out .= '(?!)';
                        $i += 2;
                        continue 2;
                    }
                    if (substr($s, $i, 3) === '[^]') {
                        $out .= '[\x{0}-\x{10FFFF}]';
                        $i += 3;
                        continue 2;
                    }
                    $class = true;
                    $classStart = strlen($out);
                    $out .= '[';
                    $i++;
                    if ($i < $n && $s[$i] === '^') {
                        $out .= '^';
                        $i++;
                    }
                    continue 2;
                case '.':
                    $out .= '[^\n\r\x{2028}\x{2029}]';
                    break;
                case '/':
                    $out .= '\/';
                    break;
                case '{':
                    if (preg_match('/\G\{\d+(?:,\d*)?\}/', $s, $m, 0, $i)) {
                        $out .= $m[0];
                        $i += strlen($m[0]);
                        continue 2;
                    }
                    $out .= '\{';
                    break;
                case '}':
                    $out .= '\}';
                    break;
                default:
                    $out .= $native ? $c : self::literal($c, $ci);
            }
            $i++;
        }
        return $out;
    }
}
