<?php
/*
 * Bundled copy of gunsnprices/gunsnprices (MIT License: LICENSE in the gunsnprices folder) for the GunsNPrices OpenCart extension.
 * Changed by tools/vendor-parser.php: the namespace GunsNPrices was renamed to GunsNPricesCpr\Vendor\GunsNPrices.
 */
namespace GunsNPricesCpr\Vendor\GunsNPrices\Internal;

/**
 * Recognize firearm calibers in product titles and normalize caliber fields.
 * Port of gunsnprices/calibers.py (via the JavaScript port, src/calibers.js).
 *
 * @internal
 */
final class Calibers
{
    // word -> spellings used in titles. A word marked optional may be left out ("300 Blackout" for ".300 AAC Blackout").
    const SYN = [
        'win' => ['win', 'winchester'], 'winchester' => ['winchester', 'win'],
        'rem' => ['rem', 'remington'], 'remington' => ['remington', 'rem'],
        'mag' => ['mag', 'magnum'], 'magnum' => ['magnum', 'mag'],
        'wby' => ['wby', 'weatherby'], 'weatherby' => ['weatherby', 'wby'],
        'spl' => ['spl', 'special'], 'special' => ['special', 'spl'],
        'sprg' => ['sprg', 'springfield', 'spfd'], 'springfield' => ['springfield', 'sprg', 'spfd'],
        'government' => ['government', 'govt', 'gov'],
        'acp' => ['acp', 'auto'], 'auto' => ['auto', 'acp'],
        's&w' => ['s&w', 's & w', 'sw', 'smith & wesson', 'smith and wesson', 'smith&wesson'],
        'blackout' => ['blackout', 'blk', 'black out'],
        'creedmoor' => ['creedmoor', 'creed', 'cm', 'crd', 'creedmore'],
        'parabellum' => ['parabellum', 'para', 'luger'],
        'gauge' => ['gauge', 'ga', 'gauges'],
        'bore' => ['bore', 'ga', 'gauge'],
        'lr' => ['lr', 'long rifle'],
        'h&h' => ['h&h', 'h & h', 'holland & holland', 'holland and holland'],
        'h&r' => ['h&r', 'h & r'],
        'bm' => ['bm', 'bushmaster', 'bush'],
    ];
    const OPTIONAL = ['aac', 'nato', 'win', 'winchester', 'rem', 'remington', 'sprg', 'springfield', 'government'];
    const SEP = '[\s\-]*';

    // common title spellings that the synonym table does not produce
    const EXTRA = [
        '.300 AAC Blackout' => ['300 aac', '300 bo', '300 black out', '300 blackout', '300 blk'],
        '6.5mm Creedmoor' => ['6.5 creedmoor', '6.5 crd', '6.5 cm', '6.5mm cm', '6.5 cr'],
        '6mm Creedmoor' => ['6 creedmoor'],
        '.22 LR' => ['22lr', '22 lr', '22 long rifle'],
        '.22 WMR' => ['22 mag', '22 magnum', '22 win mag', '22 wmr'],
        '5.56×45mm NATO' => ['5.56 nato', '5.56mm nato', '556 nato', '5.56x45', '5.56', '5.56mm', '5.56 mm'],
        '7.62x51mm NATO' => ['7.62 nato', '7.62mm nato', '762 nato', '762x51'],
        '7.62x39mm' => ['762x39'],
        '.410 Bore' => ['410 ga', '410 gauge', '410 bore'],
        '.45 Colt' => ['45 lc', '45 long colt'],
        '.44 S&W Special' => ['44 spl', '44 special', '44 spcl'],
        '.38 S&W Special' => ['38 spl', '38 special', '38 spcl', '38 spc', '38 sp', '38 spp', '38+p'],
        '.357 Mag' => ['357 rm', '357 rem mag', '357 rem magnum', '357 remington magnum', '357 remington mag'],
        '9×19mm Parabellum' => ['9mm luger', '9mm para', '9 mm luger', '9mm'],
        '10mm Auto' => ['10mm', '10 mm'],
        '.45 ACP' => ['45 acp', '45 auto', '45 automatic', '45 automatic colt pistol'],
        '.40 S&W' => ['40 s&w', '40 sw', '40 cal', '40s', '.40'],
        '.380 ACP' => ['380 acp', '380 auto', '380'],
        '.243 Win' => ['243', '.243'],
        '.308 Win' => ['308', '.308'],
        '.350 Legend' => ['.350', '350 leg', '350 lgnd', '350 lgd'],
        '.450 BM' => ['450 bushmaster', '450 bush'],
        '.204 Ruger' => ['.204'],
        '.177' => ['177', '.177'],
        '.300 Win Mag' => ['300 wm'],
        '7mm Remington Magnum' => ['7mm rm', '7 rm', '7mm rem mag'],
        '.338 Lapua Mag' => ['338 lapua', '338 lap'],
        '.30 Carbine' => ['30 m1', '30 m1 carbine'],
        '.30-06 Springfield' => ['30-06 sp'],
        '7.92×57mm Mauser' => ['8x57', '8x57mm', '8x57 js', '8x57 is'],
        '.50 AE' => ['50 action express'],
        '12 Gauge' => ['12 ga', '12ga', '12 gauge', '12-gauge', '12g'],
        '20 Gauge' => ['20 ga', '20ga', '20 gauge', '20-gauge', '20g'],
        '16 Gauge' => ['16 ga', '16ga', '16 gauge', '16g'],
        '28 Gauge' => ['28 ga', '28ga', '28 gauge', '28g'],
        '10 Gauge' => ['10g'],
    ];
    // a bare number or "Nmm" in a title is usually something else, not a caliber; these exceptions were checked on listings
    const BARE = '^\.?\d+(?:\.\d+)?(?:\s*mm)?$';
    const ALLOW_BARE = ['380', '410', '9mm', '10mm', '10 mm', '5.56', '5.56mm', '5.56 mm', '243', '.243', '308', '.308', '.40', '.350', '.204', '177', '.177'];
    // "12g" next to CO2 is a 12-gram CO2 cartridge, not 12 gauge
    const GRAM = '^\d+\s?g$';
    // shotguns "O/U 12/28": gauge / barrel length; on ammo "20/25" is rounds per box / boxes per case
    const CONTEXT = [
        ['(?<![a-z0-9.\/$#-])12\/(?:1[89]|2\d|3[0-4])(?:\.5)?(?![\d\/.])(?!\s*(?:ga|gauge|rd|round))', '12gauge'],
        ['(?<![a-z0-9.\/$#-])20\/(?:18|18\.5|2[0-46-9]|3[0-2])(?![\d\/.])(?!\s*(?:ga|gauge|rd|round))', '20gauge'],
        ['(?<![a-z0-9.\/$#-])28\/(?:2[6-9]|3[0-2])(?![\d\/.])(?!\s*(?:ga|gauge|rd|round))', '28gauge'],
        ['(?<![a-z0-9.$#-])\.?357(?=\s*\/\s*\.?38)', '357mag'],
    ];

    /** @var array|null {byKey: array<string, array>, big: string[], byId: array<string, array>} */
    private static $index = null;

    public static function key(string $s): string
    {
        $t = Text::lower(Text::unescapeHtml($s));
        $t = str_replace(['×', 'х'], 'x', $t);
        $t = (string) preg_replace('/(?<=[0-9])\.(?=[0-9])/', '#', $t);
        $t = (string) preg_replace('/[^a-z0-9#&]+/u', '', $t);
        return str_replace(['#', '&'], ['.', 'and'], $t);
    }

    /** @return string[] */
    private static function tokens(string $name): array
    {
        $s = str_replace('×', 'x', Text::lower(Text::unescapeHtml($name)));
        if ($s !== '' && $s[0] === '.') {
            $s = substr($s, 1);
        }
        $out = [];
        foreach (preg_split('/[' . Rx::WS . ']+/u', Text::trim($s)) as $t) {
            if ($t !== '') {
                $out[] = $t;
            }
        }
        return $out;
    }

    /** @return string[] */
    private static function tokenAlts(string $tok): array
    {
        if (preg_match('/^\.?[0-9]+(?:\.[0-9]+)?x[0-9]+mm[a-z]*$/D', $tok)) {
            return [$tok, str_replace('mm', '', $tok)];
        }
        if (preg_match('/^[0-9]+mm$/D', $tok)) {
            return [$tok, substr($tok, 0, -2) . ' mm'];
        }
        $alts = isset(self::SYN[$tok]) ? self::SYN[$tok] : [$tok];
        if (in_array($tok, self::OPTIONAL, true)) {
            $alts[] = '';
        }
        return $alts;
    }

    private static function bareOk(string $v): bool
    {
        return !Rx::test(Rx::p(self::BARE), $v) || in_array($v, self::ALLOW_BARE, true);
    }

    /** @return string[] */
    private static function variants(string $name): array
    {
        $toks = self::tokens($name);
        if (!$toks || strpos($name, '/') !== false) {
            return [];
        }
        $lists = array_map([self::class, 'tokenAlts'], $toks);
        $out = [];
        // itertools.product order (last list varies fastest), first 400 combinations
        $idx = array_fill(0, count($lists), 0);
        $last = count($lists) - 1;
        for ($n = 0; $n < 400; $n++) {
            $parts = [];
            foreach ($idx as $k => $i) {
                if ($lists[$k][$i] !== '') {
                    $parts[] = $lists[$k][$i];
                }
            }
            $v = implode(' ', $parts);
            if ($v !== '' && self::bareOk($v)) {
                $out[$v] = true;
            }
            $k = $last;
            while ($k >= 0 && ++$idx[$k] === count($lists[$k])) {
                $idx[$k--] = 0;
            }
            if ($k < 0) {
                break;
            }
        }
        $out = array_map('strval', array_keys($out));
        sort($out, SORT_STRING);
        return $out;
    }

    private static function rx(string $variant): string
    {
        $parts = [];
        foreach (explode(' ', $variant) as $tok) {
            $t = Text::reEscape($tok);
            $t = (string) preg_replace('/(?<=[0-9])x(?=[0-9])/', '\\\\s*x\\\\s*', $t);
            $parts[] = implode(self::SEP, explode('\\-', $t));
        }
        $body = implode(self::SEP, $parts);
        return preg_match('/^[0-9]/', $variant) ? '\.?' . $body : $body;
    }

    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }
        $byId = [];
        $conflict = [];
        $pats = [];
        foreach (Data::get('calibers')['calibers'] as $c) {
            $cal = ['id' => $c['id'], 'name' => $c['name'], 'type' => $c['type'], 'url' => isset($c['url']) ? (string) $c['url'] : ''];
            $byId[$c['id']] = $cal;
            $names = array_merge(
                [$c['name'], isset($c['site_name']) ? $c['site_name'] : ''],
                $c['aliases'],
                isset(self::EXTRA[$c['name']]) ? self::EXTRA[$c['name']] : []
            );
            foreach ($names as $nm) {
                $nm = (string) $nm;
                $own = Text::lower(Text::unescapeHtml($nm));
                $vs = self::variants($nm);
                if (self::bareOk($own)) {
                    $vs[] = $own;
                }
                foreach ($vs as $v) {
                    $k = self::key($v);
                    if ($k === '' || !preg_match('/[0-9]/', $k)) {
                        continue;
                    }
                    if (!isset($conflict[$k])) {
                        $conflict[$k] = [];
                    }
                    $dup = false;
                    foreach ($conflict[$k] as $x) {
                        if ($x[1]['id'] === $cal['id']) {
                            $dup = true;
                            break;
                        }
                    }
                    if (!$dup) {
                        $conflict[$k][] = [isset($c['n']) ? (int) $c['n'] : 0, $cal];
                    }
                    if (!isset($pats[$k])) {
                        $pats[$k] = $v !== $own || strpos($v, '/') === false ? self::rx($v) : Text::reEscape($v);
                    }
                }
            }
        }
        $byKey = [];
        foreach ($conflict as $k => $cands) {
            $cands = Text::stableSort($cands, function ($a, $b) {
                return $b[0] <=> $a[0];
            });
            // one spelling, several calibers: keep the one with 10x+ more listings, otherwise the spelling is ambiguous
            if (count($cands) === 1 || $cands[0][0] >= 10 * max(1, $cands[1][0])) {
                $byKey[(string) $k] = $cands[0][1];
            }
        }
        $keys = [];
        foreach (array_keys($byKey) as $k) {
            $keys[] = [(string) $k, Text::units($pats[$k])];
        }
        $keys = Text::stableSort($keys, function ($a, $b) {
            return $b[1] <=> $a[1];
        });
        $alts = [];
        foreach ($keys as $k) {
            $alts[] = $pats[$k[0]];
        }
        self::$index = ['byKey' => $byKey, 'big' => self::bigPatterns($alts), 'byId' => $byId];
        return self::$index;
    }

    /**
     * The alternation of every spelling, as one PCRE pattern or, if PCRE cannot compile one that big,
     * as consecutive chunks (find() then picks the leftmost match, earliest chunk on a tie: the same result).
     *
     * @param string[] $alts
     * @return string[]
     */
    private static function bigPatterns(array $alts): array
    {
        $one = self::big($alts);
        if (count($alts) < 2 || @preg_match($one, '') !== false) {
            return [$one];
        }
        // "regular expression is too large" (PCRE's compiled-size limit): split in halves until each part compiles
        $half = (int) ceil(count($alts) / 2);
        return array_merge(self::bigPatterns(array_slice($alts, 0, $half)), self::bigPatterns(array_slice($alts, $half)));
    }

    /** @param string[] $alts */
    private static function big(array $alts): string
    {
        // The JavaScript regex has the i flag. Every spelling is lowercase and prep() lowercased the subject, so the
        // flag changes nothing, and without it PCRE gets a smaller pattern. The subject's only whitespace is " ".
        return Rx::p('(?<![a-z0-9.$])(?:' . implode('|', $alts) . ')(?![a-z0-9])', '', true);
    }

    /**
     * Leftmost-first matches of the spelling alternation (String.prototype.matchAll).
     *
     * @return array<int, array{0: string, 1: int}>
     */
    private static function find(array $patterns, string $s): array
    {
        if (count($patterns) === 1) {
            $out = [];
            foreach (Rx::all($patterns[0], $s) as $m) {
                $out[] = [$m[0][0], $m[1]];
            }
            return $out;
        }
        $out = [];
        $pos = 0;
        $len = strlen($s);
        $next = [];
        while ($pos <= $len) {
            $best = null;
            foreach ($patterns as $i => $p) {
                if (!isset($next[$i]) || ($next[$i] !== false && $next[$i][1] < $pos)) {
                    $m = Rx::exec($p, $s, $pos);
                    $next[$i] = $m === null ? false : [$m[0][0], $m[1]];
                }
                if ($next[$i] !== false && ($best === null || $next[$i][1] < $best[1])) {
                    $best = $next[$i];
                }
            }
            if ($best === null) {
                break;
            }
            $out[] = $best;
            $pos = $best[1] + max(1, strlen($best[0]));
        }
        return $out;
    }

    public static function prep(string $text): string
    {
        $s = Text::lower(Text::unescapeHtml($text));
        $s = str_replace(['×', '”', '″', '®', '™'], ['x', '"', '"', '', ''], $s);
        return (string) preg_replace('/[' . Rx::WS . ']+/u', ' ', $s);
    }

    /** @return array<int, array{id: string, name: string, type: string, url: string}> */
    public static function findCalibers(string $text): array
    {
        $ix = self::index();
        $s = self::prep($text);
        $co2 = strpos($s, 'co2') !== false;
        $hits = [];
        foreach (self::find($ix['big'], $s) as $m) {
            if ($co2 && Rx::test(Rx::p(self::GRAM), $m[0])) {
                continue;
            }
            $k = self::key($m[0]);
            if (isset($ix['byKey'][$k])) {
                $hits[] = [$m[1], $ix['byKey'][$k]];
            }
        }
        foreach (self::CONTEXT as $ctx) {
            foreach (Rx::all(Rx::p($ctx[0], '', true), $s) as $m) {
                $hits[] = [$m[1], $ix['byId'][$ctx[1]]];
            }
        }
        $hits = Text::stableSort($hits, function ($a, $b) {
            return $a[0] <=> $b[0];
        });
        $out = [];
        $seen = [];
        foreach ($hits as $h) {
            if (!isset($seen[$h[1]['id']])) {
                $seen[$h[1]['id']] = true;
                $out[] = $h[1];
            }
        }
        return $out;
    }

    public static function findCaliber(string $text): ?array
    {
        $found = self::findCalibers($text);
        return $found ? $found[0] : null;
    }

    public static function normalizeCaliber(string $value): ?array
    {
        $ix = self::index();
        $k = self::key($value);
        return isset($ix['byKey'][$k]) ? $ix['byKey'][$k] : self::findCaliber($value);
    }

    /** @return array<int, array{id: string, name: string, type: string, url: string}> */
    public static function listCalibers(): array
    {
        return Text::stableSort(array_values(self::index()['byId']), function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
    }
}
