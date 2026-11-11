<?php
/*
 * Bundled copy of gunsnprices/gunsnprices (MIT License: LICENSE in the gunsnprices folder) for the GunsNPrices OpenCart extension.
 * Changed by tools/vendor-parser.php: the namespace GunsNPrices was renamed to GunsNPricesCpr\Vendor\GunsNPrices.
 */
namespace GunsNPricesCpr\Vendor\GunsNPrices\Internal;

/**
 * String helpers that behave like the JavaScript (and Python) originals: Python's html.unescape, JavaScript's
 * toLowerCase(), Python's re.escape and round(x, 4). Strings are UTF-8 throughout.
 *
 * @internal
 */
final class Text
{
    /** @var string|null */
    private static $charref = null;

    /** Null to "", invalid UTF-8 bytes to U+FFFD (what a JavaScript string would hold). */
    public static function clean($s): string
    {
        if ($s === null) {
            return '';
        }
        $s = (string) $s;
        if ($s === '' || preg_match('//u', $s) === 1) {
            return $s;
        }
        $out = preg_replace_callback(
            '/([\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
            . '|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})|./s',
            function ($m) {
                // group 1 is a valid UTF-8 character; without it (the "." branch) the byte is invalid
                return isset($m[1]) ? $m[1] : "\u{FFFD}";
            },
            $s
        );
        return (string) $out;
    }

    /** True if the string has no bytes above 0x7F. */
    public static function isAscii(string $s): bool
    {
        return preg_match('/[\x80-\xFF]/', $s) !== 1;
    }

    /** JavaScript String.prototype.toLowerCase(). */
    public static function lower(string $s): string
    {
        $s = strtr($s, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
        if (self::isAscii($s)) {
            return $s;
        }
        return strtr($s, Data::get('lowercase'));
    }

    /** ASCII-only toUpperCase (used on regex groups that can only hold ASCII). */
    public static function upperAscii(string $s): string
    {
        return strtr($s, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
    }

    /** JavaScript String.prototype.trim(). */
    public static function trim(string $s): string
    {
        return (string) preg_replace('/^[' . Rx::WS . ']+|[' . Rx::WS . ']+$/uD', '', $s);
    }

    /** Decode HTML character references exactly like Python's html.unescape. */
    public static function unescapeHtml(string $s): string
    {
        if ($s === '' || strpos($s, '&') === false) {
            return $s;
        }
        if (self::$charref === null) {
            self::$charref = Rx::p('&(#[0-9]+;?|#[xX][0-9a-fA-F]+;?|[^\t\n\f <&#;]{1,32};?)');
        }
        return (string) preg_replace_callback(self::$charref, function ($m) {
            return Text::charref($m[1]);
        }, $s);
    }

    /** @internal one reference (the part after "&") */
    public static function charref(string $ref): string
    {
        $ent = Data::get('entities');
        if ($ref[0] === '#') {
            $hex = $ref[1] === 'x' || $ref[1] === 'X';
            $digits = ltrim(rtrim(substr($ref, $hex ? 2 : 1), ';'), '0');
            if (strlen($digits) > 8) {
                return "\u{FFFD}";
            }
            $num = $digits === '' ? 0 : ($hex ? (int) hexdec($digits) : (int) $digits);
            if (isset($ent['invalid_charrefs'][$num])) {
                return $ent['invalid_charrefs'][$num];
            }
            if (($num >= 0xD800 && $num <= 0xDFFF) || $num > 0x10FFFF) {
                return "\u{FFFD}";
            }
            static $invalid = null;
            if ($invalid === null) {
                $invalid = array_flip($ent['invalid_codepoints']);
            }
            if (isset($invalid[$num])) {
                return '';
            }
            return self::chr($num);
        }
        if (isset($ent['html5'][$ref])) {
            return $ent['html5'][$ref];
        }
        for ($x = strlen($ref) - 1; $x > 1; $x--) {
            $head = substr($ref, 0, $x);
            if (isset($ent['html5'][$head])) {
                return $ent['html5'][$head] . substr($ref, $x);
            }
        }
        return '&' . $ref;
    }

    /** UTF-8 encoding of a code point. */
    public static function chr(int $cp): string
    {
        if ($cp < 0x80) {
            return \chr($cp);
        }
        if ($cp < 0x800) {
            return \chr(0xC0 | ($cp >> 6)) . \chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return \chr(0xE0 | ($cp >> 12)) . \chr(0x80 | (($cp >> 6) & 0x3F)) . \chr(0x80 | ($cp & 0x3F));
        }
        return \chr(0xF0 | ($cp >> 18)) . \chr(0x80 | (($cp >> 12) & 0x3F)) . \chr(0x80 | (($cp >> 6) & 0x3F))
            . \chr(0x80 | ($cp & 0x3F));
    }

    /** Escape a string for a regex the way Python's re.escape does (the same characters get a backslash). */
    public static function reEscape(string $s): string
    {
        // every special character is ASCII, and UTF-8 multibyte sequences never contain ASCII bytes
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (str_split("()[]{}?*+-|^\$\\.&~# \t\n\r\x0B\f") as $ch) {
                $map[$ch] = '\\' . $ch;
            }
        }
        return strtr($s, $map);
    }

    /** Python round(x, 4): correctly rounded, exact ties (0.53125) go to the even digit. */
    public static function round4(float $x): float
    {
        $s = sprintf('%.20F', $x);
        $dot = strpos($s, '.');
        if ($dot !== false && isset($s[$dot + 5]) && $s[$dot + 5] === '5' && trim(substr($s, $dot + 6), '0') === '') {
            $f = floor($x * 10000);
            return (fmod($f, 2.0) == 0.0 ? $f : $f + 1) / 10000;
        }
        return (float) sprintf('%.4F', $x);
    }

    /** Length in UTF-16 code units (JavaScript string length). */
    public static function units(string $s): int
    {
        if (self::isAscii($s)) {
            return strlen($s);
        }
        return strlen($s) - (int) preg_match_all('/[\x80-\xBF]/', $s) + (int) preg_match_all('/[\xF0-\xF7]/', $s);
    }

    /**
     * JavaScript s.slice(Math.max(0, i - n), i) where i is the code-unit index of byte offset $end:
     * the last $n UTF-16 code units before $end.
     */
    public static function tail(string $s, int $end, int $n): string
    {
        $prefix = substr($s, 0, $end);
        if (self::isAscii($prefix)) {
            return strlen($prefix) <= $n ? $prefix : substr($prefix, -$n);
        }
        $pos = strlen($prefix);
        $u = 0;
        while ($pos > 0 && $u < $n) {
            $p = $pos - 1;
            while ($p > 0 && (ord($prefix[$p]) & 0xC0) === 0x80) {
                $p--;
            }
            $w = ord($prefix[$p]) >= 0xF0 ? 2 : 1;
            if ($u + $w > $n) {
                // JavaScript would cut a surrogate pair here and keep a lone surrogate
                return "\u{FFFD}" . substr($prefix, $pos);
            }
            $u += $w;
            $pos = $p;
        }
        return (string) substr($prefix, $pos);
    }

    /**
     * Stable sort (PHP 7 usort is not stable; JavaScript's Array.prototype.sort is).
     *
     * @param array $items
     * @param callable $cmp
     * @return array
     */
    public static function stableSort(array $items, callable $cmp): array
    {
        $dec = [];
        $i = 0;
        foreach ($items as $it) {
            $dec[] = [$it, $i++];
        }
        usort($dec, function ($a, $b) use ($cmp) {
            $c = $cmp($a[0], $b[0]);
            return $c !== 0 ? $c : $a[1] <=> $b[1];
        });
        $out = [];
        foreach ($dec as $d) {
            $out[] = $d[0];
        }
        return $out;
    }
}
