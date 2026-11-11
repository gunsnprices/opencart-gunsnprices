<?php
/*
 * Bundled copy of gunsnprices/gunsnprices (MIT License: LICENSE in the gunsnprices folder) for the GunsNPrices OpenCart extension.
 * Changed by tools/vendor-parser.php: the namespace GunsNPrices was renamed to GunsNPricesCpr\Vendor\GunsNPrices.
 */
namespace GunsNPricesCpr\Vendor\GunsNPrices\Internal;

/**
 * Identify the firearm model in a product title: brand -> family -> model.
 * Port of gunsnprices/models.py (via the JavaScript port, src/models.js); the rules are GunsNPrices' model
 * dictionaries (50 brands).
 *
 * Patterns that run on a normalized title (norm()) are compiled in "spaces" mode: that title has no whitespace
 * but " ", so \s can be a plain space with the same result.
 *
 * @internal
 */
final class Models
{
    // airguns and airsoft are not firearms (the site filters them by category; here we go by the title)
    const AIRGUN = '\bair\s*-?\s*(?:gun|rifle|pistol)s?\b|\bairgun|\bairsoft|\bbb\s*(?:gun|pistol|rifle)|\bpellets?\b|\bco2\b';

    // accessories named after a gun ("Glock 19 holster", "Sig P365 XL 12rd Magazine", "Magpul PMAG for Glock 19")
    const ACC_NOUN = '\b(?:holsters?|magazines?(?!\s*(?:pattern|compatible|well|release|disconnect|safety|catch|cut|tube|extension|fed)\b)|pmags?|slings?|'
        . 'grip panels?|scope mounts?|recoil pads?|speed ?loaders?|mag(?:azine)? loaders?|cleaning kits?|pouch(?:es)?|snap caps?|replacement barrels?|'
        . 'conversion kits?|parts kits?)\b';
    const ACC_INCLUDED = '(?:(?:\bw/|\bwith\b|\bwithout\b|\bno\b|\band\b|&|\+|\bincl\w*|\bplus\b|\(\d\)|\b[1-9]\b|\btwo\b|\bthree\b|\bfour\b|,|\bor\b|\bextra\b|'
        . '\bspare\b|\bfactory\b|\bfits\b|\btakes\b|\baccepts\b|\buses\b)[^,]{0,14}|/\s*)$';
    const GUN_WORDS = '\b(?:pistol|rifle|shotgun|revolver|handgun|carbine|firearm|semi-?auto(?:matic)?|bolt[- ]?action|pump|lever[- ]?action|single[- ]?shot|'
        . 'o/u|over/under|sxs|sbr|derringer)\b|\d+\+1\b|\d+(?:\.\d+)?\s*-?\s*(?:"|in\b|inch)';

    const DASHES = ['‐' => '-', '‑' => '-', '–' => '-', '—' => '-', '“' => '"', '”' => '"', '’' => "'", '|' => ' | ', '*' => ' ', '®' => '', '™' => ''];

    const GEN = '\bgen(?:eration)?\.?\s*-?\s*([1-6])\b|\b([1-6])(?:st|nd|rd|th)\s*gen\b|\bg([1-6])\b';
    const MPN_IN_NAME = '\b(?:gl)?[pue][a-z0-9]\d{3}[a-z0-9-]*|\bg\d{2}c?\d{3}us\b|\bg\d{4}[a-z]*aut\b';

    /** @var array|null */
    private static $dicts = null;

    /** @var array{0: string, 1: string}|null */
    private static $base = null;

    private static function anyRe($patterns): ?string
    {
        return is_array($patterns) && $patterns ? implode('|', $patterns) : null;
    }

    private static function key($s): string
    {
        return (string) preg_replace('/[^a-z0-9]+/u', '', Text::lower(Text::unescapeHtml((string) $s)));
    }

    private static function dicts(): array
    {
        if (self::$dicts !== null) {
            return self::$dicts;
        }
        self::$dicts = [];
        foreach (Data::get('models')['dicts'] as $d) {
            $d['_terms'] = isset($d['mode']) && $d['mode'] === 'terms';
            $d['_exclude'] = self::anyRe(isset($d['exclude']) ? $d['exclude'] : null);
            $d['_exclude_soft'] = self::anyRe(isset($d['exclude_soft']) ? $d['exclude_soft'] : null);
            $d['_block'] = self::anyRe(isset($d['other_brand_block']) ? $d['other_brand_block'] : null);
            $rx = isset($d['brand_rx']) && $d['brand_rx'] !== '' ? $d['brand_rx'] : null;
            $d['_brand_any'] = $rx !== null ? $rx : '(?<![a-z])' . Text::reEscape($d['brand_word']) . '(?![a-z])';
            $d['_brand_start'] = $rx !== null ? '^\W*(?:' . $rx . ')' : null;
            $d['_brand_names'] = [];
            foreach (isset($d['brand_names']) ? $d['brand_names'] : [] as $n) {
                $d['_brand_names'][self::key($n)] = true;
            }
            $d['_deny_names'] = [];
            foreach (isset($d['deny_brand_names']) ? $d['deny_brand_names'] : [] as $n) {
                $d['_deny_names'][self::key($n)] = true;
            }
            foreach (['title_rewrite', 'configs', 'features', 'gens', 'feature_order', 'no_gen_models', 'other_brand_models'] as $f) {
                if (!isset($d[$f]) || !is_array($d[$f])) {
                    $d[$f] = [];
                }
            }
            foreach (['mpn_model_alias', 'mpn_letter_model', 'mpn_letter_gen', 'needed', 'family_display', 'config_implied_by_gen_family'] as $f) {
                if (!isset($d[$f]) || !is_array($d[$f])) {
                    $d[$f] = [];
                }
            }
            if (!$d['_terms']) {
                $bw = Text::reEscape($d['brand_word']);
                $d['_numeric'] = '(?<![a-z0-9.])(?:' . $bw . '\s*-?\s*g?\s*-?\s*|g)(\d{2})(?:(sf|x|c|l|m|s)|\s(sf|x)(?![a-z0-9]))?(?:g([1-6])(?:us)?|(v))?(fs|mos|rtf)?'
                    . '(?![a-z0-9&"+/])(?!\s*-?\s*(?:round|rounds|rd|rds|rnd|mag|mags|magazine)\b)';
                $d['_own_start'] = '^\W*' . $bw . '\b';
                $d['_bare'] = isset($d['bare_model_tokens']) && $d['bare_model_tokens']
                    ? '\b(' . implode('|', $d['bare_model_tokens']) . ')\b' : null;
            }
            self::$dicts[] = $d;
        }
        return self::$dicts;
    }

    /** Pattern for a rule that runs on a normalized title. */
    private static function t(string $source): string
    {
        return Rx::p($source, 'i', true);
    }

    /** The part of norm() that does not depend on the dictionary. */
    private static function base(string $s): string
    {
        if (self::$base !== null && self::$base[0] === $s) {
            return self::$base[1];
        }
        $t = strtr(Text::unescapeHtml($s), self::DASHES);
        $t = (string) preg_replace('/[' . Rx::WS . ']+/u', ' ', Text::lower($t));
        $t = trim($t, ' ');
        self::$base = [$s, $t];
        return $t;
    }

    private static function norm(string $s, array $d): string
    {
        $t = self::base($s);
        foreach ($d['title_rewrite'] as $kv) {
            $t = Rx::replace(self::t($kv[0]), $kv[1], $t);
        }
        return $t;
    }

    private static function isAccessory(string $title): bool
    {
        $t = Text::lower(Text::unescapeHtml(Text::unescapeHtml($title)));
        if (Rx::test(Rx::p(self::GUN_WORDS), $t)) {
            return false;
        }
        $inc = Rx::p(self::ACC_INCLUDED);
        foreach (Rx::all(Rx::p(self::ACC_NOUN), $t) as $m) {
            if (!Rx::test($inc, Text::tail($t, $m[1], 16))) {
                return true;
            }
        }
        return false;
    }

    /** @return string[] */
    public static function listBrands(): array
    {
        $out = [];
        foreach (self::dicts() as $d) {
            $out[] = $d['brand'];
        }
        return $out;
    }

    private static function nonEmpty($s): bool
    {
        return $s !== null && $s !== '';
    }

    // ---------- numeric mode (Glock): codes like 19, 19X, 43X, 20SF; generation from the title or the part number ----------

    private static function findModels(string $t, array $d): array
    {
        $found = [];
        foreach (Rx::all(self::t($d['_numeric']), $t) as $mm) {
            $m = $mm[0];
            $num = Text::lower((string) $m[1]);
            $suf = Text::upperAscii(self::nonEmpty(isset($m[2]) ? $m[2] : null) ? $m[2] : (self::nonEmpty(isset($m[3]) ? $m[3] : null) ? $m[3] : ''));
            if (isset($d['models'][$num . $suf])) {
                $code = $num . $suf;
            } elseif (isset($d['models'][$num])) {
                $code = $num;
            } else {
                continue;
            }
            $g4 = isset($m[4]) ? $m[4] : null;
            $found[] = [
                'code' => $code,
                'num' => $num,
                'pos' => $mm[1],
                'end' => $mm[1] + strlen($m[0]),
                'mos' => Text::lower(isset($m[6]) ? (string) $m[6] : '') === 'mos',
                'gen' => self::nonEmpty($g4) ? $g4 : (self::nonEmpty(isset($m[5]) ? $m[5] : null) ? 'V' : ''),
            ];
        }
        return $found;
    }

    private static function decodeMpn(string $name, string $mpn, string $sku, array $d): ?array
    {
        $cands = [$mpn, $sku];
        foreach (Rx::all(Rx::p(self::MPN_IN_NAME, 'i'), $name) as $m) {
            $cands[] = $m[0][0];
        }
        foreach ($cands as $c) {
            $c = Text::trim($c);
            $m = Rx::exec(Rx::p('^G(\d{2}C?)([345])(\d{2})US$', 'i'), $c);
            if ($m !== null && isset($d['models'][Text::upperAscii($m[0][1])])) {
                return ['code' => Text::upperAscii($m[0][1]), 'letter' => '', 'mpn' => $c, 'gen' => $m[0][2]];
            }
            $m = Rx::exec(Rx::p('^G(\d{2})\d{2}[A-Z]*AUT$', 'i'), $c);
            if ($m !== null && isset($d['models'][$m[0][1]])) {
                return ['code' => $m[0][1], 'letter' => '', 'mpn' => $c, 'gen' => ''];
            }
            $m = Rx::exec(Rx::p('^(?:GL)?([PUE])([A-Z0-9])(\d{2})(\d)', 'i'), $c);
            if ($m === null) {
                continue;
            }
            $letter = Text::upperAscii($m[0][2]);
            $num = $m[0][3];
            if (isset($d['mpn_model_alias'][$num])) {
                $num = $d['mpn_model_alias'][$num];
            }
            $code = isset($d['mpn_letter_model'][$letter . $num]) ? $d['mpn_letter_model'][$letter . $num] : $num;
            if (!isset($d['models'][$code])) {
                continue;
            }
            $gen = isset($d['mpn_letter_gen'][$letter]) ? (string) $d['mpn_letter_gen'][$letter] : '';
            return ['code' => (string) $code, 'letter' => $letter, 'mpn' => $c, 'gen' => $gen];
        }
        return null;
    }

    private static function parseNumeric(string $t, string $name, ?string $brand, string $mpn, string $sku, array $d): ?array
    {
        $pm = self::decodeMpn($name, $mpn, $sku, $d);
        if ($pm === null && $d['_exclude_soft'] !== null && Rx::test(self::t($d['_exclude_soft']), $t)) {
            return null;
        }
        if ($brand !== null) {
            $own = isset($d['_brand_names'][self::key($brand)]) || $pm !== null;
        } else {
            $own = $pm !== null || Rx::test(self::t($d['_own_start']), $t);
        }
        $models = self::findModels($t, $d);
        if (!$models && $own) {
            $m = Rx::exec(Rx::p('^(\d{2})(x)?\b', '', true), $t);
            if ($m !== null) {
                $code = $m[0][1] . Text::upperAscii(isset($m[0][2]) ? (string) $m[0][2] : '');
                if (isset($d['models'][$code])) {
                    $models = [['code' => $code, 'num' => $m[0][1], 'pos' => 0, 'end' => strlen($m[0][0]), 'mos' => false, 'gen' => '']];
                }
            }
        }
        if (!$models && $own && $d['_bare'] !== null) {
            $m = Rx::exec(Rx::p($d['_bare'], '', true), $t);
            if ($m !== null) {
                $models = [[
                    'code' => Text::upperAscii($m[0][1]), 'num' => substr($m[0][1], 0, 2),
                    'pos' => $m[1], 'end' => $m[1] + strlen($m[0][0]), 'mos' => false, 'gen' => '',
                ]];
            }
        }
        if (!$own) {
            if (!$models) {
                return null;
            }
            if ($d['_block'] !== null && Rx::test(self::t($d['_block']), $t)) {
                return null;
            }
        }
        $src = 'title';
        $first = null;
        if ($models) {
            $first = $models[0];
            $code = $first['code'];
            foreach ($models as $x) {
                if ($x['num'] === $first['num'] && strlen($x['code']) > strlen($code)) {
                    $code = $x['code'];
                }
            }
        } elseif ($own && $pm !== null) {
            $code = $pm['code'];
            $src = 'mpn';
        } else {
            return null;
        }
        $gen = '';
        if ($first !== null && $first['gen'] !== '') {
            $gen = $first['gen'];
        } else {
            $m = Rx::exec(Rx::p(self::GEN, '', true), $t);
            if ($m !== null) {
                foreach ([1, 2, 3] as $i) {
                    if (self::nonEmpty(isset($m[0][$i]) ? $m[0][$i] : null)) {
                        $gen = $m[0][$i];
                        break;
                    }
                }
            } elseif ($first !== null && Rx::test(Rx::p('^\s*v\b', '', true), (string) substr($t, $first['end']))) {
                $gen = 'V';
            } elseif ($pm !== null && $pm['gen'] !== '' && $pm['code'] === $code) {
                $gen = $pm['gen'];
            }
        }
        if (in_array($code, $d['no_gen_models'], true) && $gen !== 'V') {
            $gen = '';
        }
        $configs = [];
        foreach ($d['configs'] as $kv) {
            if (Rx::test(self::t($kv[1]), $t)
                || ($kv[0] === 'MOS' && (($first !== null && $first['mos']) || ($pm !== null && strpos(Text::lower($pm['mpn']), 'mos') !== false)))) {
                $configs[] = $kv[0];
            }
        }
        $features = [];
        foreach ($d['features'] as $kv) {
            if (Rx::test(self::t($kv[1]), $t)) {
                $features[] = $kv[0];
            }
        }
        if ($pm !== null && Rx::test(Rx::p('\d(?:mos)?tb\b', 'i'), $pm['mpn']) && !in_array('Threaded Barrel', $features, true)) {
            $features[] = 'Threaded Barrel';
        }
        return [
            'code' => $code, 'family' => isset($d['models'][$code]) ? (string) $d['models'][$code] : 'undefined',
            'gen' => $gen, 'configs' => $configs, 'features' => $features, 'src' => $src,
        ];
    }

    // ---------- terms mode (all other brands): ordered list of model patterns, first match wins ----------

    private static function parseTerms(string $t, ?string $brand, array $d): ?array
    {
        if ($brand !== null && isset($d['_deny_names'][self::key($brand)])) {
            return null;
        }
        $own = ($brand !== null && isset($d['_brand_names'][self::key($brand)])) || Rx::test(self::t($d['_brand_start']), $t);
        if (!$own) {
            if (!Rx::test(self::t($d['_brand_any']), $t)) {
                return null;
            }
            if ($d['_block'] !== null && Rx::test(self::t($d['_block']), $t)) {
                return null;
            }
        }
        $hit = null;
        foreach ($d['models'] as $m) {
            if (Rx::test(self::t($m['match']), $t) && !(isset($m['not']) && $m['not'] !== '' && Rx::test(self::t($m['not']), $t))) {
                $hit = $m;
                break;
            }
        }
        if ($hit === null) {
            return null;
        }
        $allowed = $d['other_brand_models'];
        if (!$own && $allowed && !in_array($hit['name'], $allowed, true)) {
            return null;
        }
        $gen = '';
        foreach ($d['gens'] as $kv) {
            if (Rx::test(self::t($kv[1]), $t)) {
                $gen = (string) $kv[0];
                break;
            }
        }
        $configs = [];
        $hitConfigs = isset($hit['configs']) ? $hit['configs'] : [];
        $impliedConfigs = isset($hit['implied_configs']) ? $hit['implied_configs'] : [];
        $implied = $d['config_implied_by_gen_family'];
        foreach ($d['configs'] as $kv) {
            $name = $kv[0];
            if (!in_array($name, $hitConfigs, true)) {
                continue;
            }
            $imp = isset($implied[$name][$hit['family']]) ? $implied[$name][$hit['family']] : [];
            if (Rx::test(self::t($kv[1]), $t) || ($gen !== '' && in_array($gen, $imp, true)) || in_array($name, $impliedConfigs, true)) {
                $configs[] = $name;
            }
        }
        $features = [];
        foreach ($d['features'] as $kv) {
            if (Rx::test(self::t($kv[1]), $t)) {
                $features[] = $kv[0];
            }
        }
        return ['code' => $hit['name'], 'family' => (string) $hit['family'], 'gen' => $gen, 'configs' => $configs, 'features' => $features, 'src' => 'title'];
    }

    /**
     * Which matching dictionary wins: the product's own brand -> brand at the start of the title -> brand mentioned earlier.
     *
     * @return int[]
     */
    private static function rank(string $t, array $d, ?string $brand): array
    {
        if ($brand !== null && isset($d['_brand_names'][self::key($brand)])) {
            return [0, 0];
        }
        $m = Rx::exec(self::t($d['_brand_any']), $t);
        $at = $m === null ? null : Text::units(substr($t, 0, $m[1]));
        if ($at !== null && $at <= 2) {
            return [1, 0];
        }
        return [2, $at === null ? 1000000 : $at];
    }

    /** @return string[] */
    private static function words(string $x): array
    {
        return preg_match_all('/[a-z0-9.&+]+/', Text::lower($x), $m) ? $m[0] : [];
    }

    private static function leaf(array $r, array $d): string
    {
        if ($d['_terms']) {
            $s = $r['code'] . ($r['gen'] !== '' ? ' ' . $r['gen'] : '');
        } else {
            $s = $d['brand'] . ' ' . $r['code'];
            if ($r['gen'] !== '') {
                $s .= $r['gen'] === 'V' ? ' V' : ' Gen ' . $r['gen'];
            }
        }
        foreach ($r['configs'] as $c) {
            // skip a config already spelled out in the name ("Plus Shield" on "S&W M&P Shield Plus")
            $have = array_flip(self::words($s));
            $all = true;
            foreach (self::words($c) as $w) {
                if (!isset($have[$w])) {
                    $all = false;
                    break;
                }
            }
            if (!Rx::test(Rx::p('(?<![a-z0-9])' . Text::reEscape($c) . '(?![a-z0-9])', 'i'), $s) && !$all) {
                $s .= ' ' . $c;
            }
        }
        $needed = isset($d['needed'][$r['family']]) ? $d['needed'][$r['family']] : [];
        foreach ($d['feature_order'] as $f) {
            if (in_array($f, $r['features'], true) && in_array($f, $needed, true)) {
                $s .= ' ' . $f;
            }
        }
        return $s;
    }

    /** @return array{0: array|null, 1: string} result and the normalized title */
    private static function parseOne(string $title, array $d, ?string $brand, string $mpn, string $sku): array
    {
        $t = self::norm($title, $d);
        if ($d['_exclude'] !== null && Rx::test(self::t($d['_exclude']), $t)) {
            return [null, $t];
        }
        if ($d['_terms']) {
            return [self::parseTerms($t, $brand, $d), $t];
        }
        // the numeric dictionary (Glock) only runs when the brand is given or named in the title
        if (!(($brand !== null && isset($d['_brand_names'][self::key($brand)])) || Rx::test(self::t($d['_brand_any']), $t))) {
            return [null, $t];
        }
        return [self::parseNumeric($t, $title, $brand, $mpn, $sku, $d), $t];
    }

    /**
     * @param string|null $brand null or a non-empty brand
     * @return array{brand: string, family: string, model: string, generation: string, configs: string[], features: string[], source: string}|null
     */
    public static function identifyModel(string $title, ?string $brand, string $mpn, string $sku): ?array
    {
        if ($title === '' || Rx::test(Rx::p(self::AIRGUN, 'i'), $title) || self::isAccessory($title)) {
            return null;
        }
        $hits = [];
        foreach (self::dicts() as $d) {
            list($r, $t) = self::parseOne($title, $d, $brand, $mpn, $sku);
            if ($r !== null) {
                $hits[] = [self::rank($t, $d, $brand), $d, $r];
            }
        }
        if (!$hits) {
            return null;
        }
        $hits = Text::stableSort($hits, function ($a, $b) {
            return $a[0][0] !== $b[0][0] ? $a[0][0] <=> $b[0][0] : $a[0][1] <=> $b[0][1];
        });
        $d = $hits[0][1];
        $r = $hits[0][2];
        $fd = $d['family_display'];
        if (isset($fd[$r['family']]) && (string) $fd[$r['family']] !== '') {
            $family = (string) $fd[$r['family']];
        } else {
            $family = !$d['_terms'] ? $d['brand'] . ' ' . $r['family'] : $r['family'];
        }
        return [
            'brand' => $d['brand'],
            'family' => $family,
            'model' => self::leaf($r, $d),
            'generation' => $r['gen'],
            'configs' => $r['configs'],
            'features' => $r['features'],
            'source' => $r['src'],
        ];
    }
}
