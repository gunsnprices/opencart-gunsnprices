<?php
/*
 * Bundled copy of gunsnprices/gunsnprices (MIT License: LICENSE in the gunsnprices folder) for the GunsNPrices OpenCart extension.
 * Changed by tools/vendor-parser.php: the namespace GunsNPrices was renamed to GunsNPricesCpr\Vendor\GunsNPrices.
 */
namespace GunsNPricesCpr\Vendor\GunsNPrices\Internal;

/**
 * Numbers from product titles: magazine capacity, barrel length, rounds per box and cost per round.
 * Port of gunsnprices/specs.py (via the JavaScript port, src/specs.js).
 *
 * @internal
 */
final class Specs
{
    const NUM = '(\d{1,3}(?:,\d{3})+|\d{1,5})';
    const INCH = '(?:"|in\b(?!\s*(?:1\b|stock\b|one\b))\.?|inch(?:es)?\b|-inch\b|-in\b)';
    const LEN = '(\d{1,2}(?:\.\d{1,3})?)(?:[\s-]+(\d{1,2})\/(\d{1,2}))?';
    const BBL = '(?:bbl|brl|barrel)\b';
    const B = '(?<![a-z0-9.#$\/])';

    const CALIBER_SLASH = ['30/30', '45/70', '30/06', '22/250', '25/06', '38/55', '44/40', '32/20', '38/40', '25/20', '32/40'];

    /** @var array<string, string>|null */
    private static $re = null;

    /** @var array{0: string, 1: string}|null */
    private static $last = null;

    private static function re(): array
    {
        if (self::$re === null) {
            $NUM = self::NUM;
            $INCH = self::INCH;
            $LEN = self::LEN;
            $BBL = self::BBL;
            $B = self::B;
            // the subject of every pattern here went through prep(): its only whitespace is " "
            $p = function (string $src): string {
                return Rx::p($src, '', true);
            };
            self::$re = [
                'ammo' => $p(
                    '\b(?:ammo|ammunition|shotshells?|shot ?shells?|cartridges?|bullets?|primers?|slugs?|buckshot|pellets?|bbs?|grains?|\d+\s*gr|'
                    . 'per box|box of|rounds? box|rds?\/box)\b|\/\s*(?:box|bx|pk|pack|case|cs|tin)\b'
                ),
                'cap_plus' => $p('(?<![\d.:\/x])(\d{1,3})\s*\+\s*1(?!\d)'),
                'cap_rd' => $p('(?<![\d.:\/x])(\d{1,3})\s*-?\s*(?:rd|rds|rnd|rnds|round|rounds|shot|shots)\b(?!\s*\/?\s*(?:per\b|box|bx|pack|pk|case))'),
                'cap_pair' => $p('(?<![\d.:\/x])(\d{1,3})\s*-?\s*(?:rd|rds|rnd|rnds|rounds?)?\s*\/\s*(?:1?-)?(\d{1,3})\s*-?\s*(?:rd|rds|rnd|rnds|rounds?)\b'),
                'cap_mag' => $p('\b(?:cap|capacity)\s*[:=]?\s*(\d{1,3})\b(?!\s*(?:"|in\b|gr\b))'),
                'bar_tagged0' => $p('(?<![\d.:\/x#$-])' . $LEN . '\s*-?\s*' . $INCH . '?\s*' . $BBL),
                'bar_tagged1' => $p('\b(?:bbl|brl|barrel)\s*(?:length)?\s*[:=]?\s*' . $LEN . '\s*' . $INCH),
                'bar_inch' => $p('(?<![\d.:\/x#$-])' . $LEN . '\s*-?\s*' . $INCH),
                // sticky (/y) in JavaScript: \G anchors it at the offset
                'not_after' => '/\G' . substr($p('\s*-?\s*(?:chamber|chbr|chmbr|shells?|m-?lok|mlok|handguard|hg\b|rail|keymod|quad|lop\b|length of pull|oal\b|twist|rate)'), 1),
                'not_before' => $p('(?:lop|oal|overall|length of pull|pull|twist|1:|chamber|m-?lok|mlok|handguard|rail|sight radius)\s*[:=]?\s*$'),
                'shotgun' => $p('\b(?:\d{1,2}|410)\s*(?:ga|gauge|g)\b|\.410|shotgun|o\/u|over\/under|sxs|side by side|\b(?:12|16|20|28|410)\/\d\d\b'),
                // "O/U 12/28": gauge / barrel length (10 is left out: "10/22" is a Ruger rifle)
                'gauge_slash' => $p('(?<![a-z0-9.\/$#-])(?:12|16|20|28|410)\/(1[89](?:\.5)?|2\d|3[0-4])(?![\d\/.])(?!\s*(?:ga|gauge|rd|round))'),
                'rpb0' => $p($B . $NUM . '\s*-?\s*(?:rd|rds|round|rounds|rnd|rnds|ct|count|pcs|pc|cartridges|shells|shotshells|bullets)\b(?!\s*(?:mag|magazine|capacity|cap)\b)'),
                'rpb1' => $p($B . $NUM . '\s*(?:\/\s*|per\s+)(?:box|bx|pack|pk|case|cs|tin|can|bag|ct)\b'),
                'rpb2' => $p('(?:box|pack|case|tin|bx|bag|can) of\s*' . $NUM),
                'rpb3' => $p($B . $NUM . '\s*-?\s*(?:pack|pk|packs)\b'),
                // distributor notation "50/10", "20/25": rounds per box / boxes per case
                'box_case' => $p('(?<![\d.\-\/])(5|10|15|18|20|25|30|40|50|100|200|250|500|1000)\s*\/\s*(\d{1,3})(?![\d\/."])'),
            ];
        }
        return self::$re;
    }

    public static function prep(string $text): string
    {
        if (self::$last !== null && self::$last[0] === $text) {
            return self::$last[1];
        }
        $s = Text::lower(Text::unescapeHtml(Text::unescapeHtml($text)));
        $s = str_replace(['”', '″', '“', "''", '’’'], '"', $s);
        $s = str_replace(['®', '™'], '', $s);
        $s = (string) preg_replace('/[' . Rx::WS . ']+/u', ' ', $s);
        self::$last = [$text, $s];
        return $s;
    }

    public static function looksLikeAmmo(string $title): bool
    {
        return Rx::test(self::re()['ammo'], self::prep($title));
    }

    public static function magazineCapacity(string $title): ?int
    {
        $re = self::re();
        $s = self::prep($title);
        $p = Rx::exec($re['cap_pair'], $s);
        if ($p !== null) {
            $n = max((int) $p[0][1], (int) $p[0][2]);
            if ($n >= 1 && $n <= 110) {
                return $n;
            }
        }
        foreach (['cap_plus', 'cap_rd', 'cap_mag'] as $k) {
            foreach (Rx::all($re[$k], $s) as $m) {
                $n = (int) $m[0][1];
                if ($n >= 1 && $n <= 110) {
                    return $n;
                }
            }
        }
        return null;
    }

    /** @param array<int, string|null> $g */
    private static function length(array $g): float
    {
        $v = (float) $g[1];
        $a = isset($g[2]) ? $g[2] : null;
        $b = isset($g[3]) ? $g[3] : null;
        if ($a !== null && $a !== '' && $b !== null && $b !== '') {
            $num = (int) $a;
            $den = (int) $b;
            if (in_array($den, [2, 4, 8, 16], true) && $num < $den) {
                $v += $num / $den;
            }
        }
        return $v;
    }

    public static function barrelLength(string $title): ?float
    {
        $re = self::re();
        $s = self::prep($title);
        foreach (['bar_tagged0', 'bar_tagged1'] as $k) {
            foreach (Rx::all($re[$k], $s) as $m) {
                $v = self::length($m[0]);
                if ($v >= 1.5 && $v <= 36) {
                    return $v;
                }
            }
        }
        $g = Rx::exec($re['gauge_slash'], $s);
        if ($g !== null) {
            return (float) $g[0][1];
        }
        $shotgun = Rx::test($re['shotgun'], $s);
        foreach (Rx::all($re['bar_inch'], $s) as $m) {
            $v = self::length($m[0]);
            if (!($v >= 1.5 && $v <= 36)) {
                continue;
            }
            $end = $m[1] + strlen($m[0][0]);
            if (preg_match($re['not_after'], $s, $unused, 0, $end) === 1
                || Rx::test($re['not_before'], Text::tail($s, $m[1], 20))) {
                continue;
            }
            if ($shotgun && $v <= 3.5) {
                continue; // 3" chamber
            }
            return $v;
        }
        return null;
    }

    public static function roundsPerBox(string $title): ?int
    {
        $re = self::re();
        $s = self::prep($title);
        foreach (['rpb0', 'rpb1', 'rpb2', 'rpb3'] as $k) {
            $m = Rx::exec($re[$k], $s);
            if ($m !== null) {
                $n = (int) str_replace(',', '', (string) $m[0][1]);
                if ($n >= 1 && $n <= 20000) {
                    return $n;
                }
            }
        }
        foreach (Rx::all($re['box_case'], $s) as $m) {
            $g = $m[0];
            if (!in_array($g[1] . '/' . $g[2], self::CALIBER_SLASH, true) && !in_array($g[2], ['2', '4', '8', '16', '32'], true)) {
                return (int) $g[1];
            }
        }
        return null;
    }

    /** @param mixed $price @param mixed $rounds */
    public static function costPerRound($price, $rounds): ?float
    {
        if (!is_numeric($price) || !is_numeric($rounds)) {
            return null;
        }
        $p = (float) $price;
        $r = (float) $rounds;
        if (!($p > 0) || !($r > 0)) {
            return null;
        }
        return Text::round4($p / $r);
    }
}
