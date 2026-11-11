<?php
/*
 * Bundled copy of gunsnprices/gunsnprices (MIT License: LICENSE in the gunsnprices folder) for the GunsNPrices OpenCart extension.
 * Changed by tools/vendor-parser.php: the namespace GunsNPrices was renamed to GunsNPricesCpr\Vendor\GunsNPrices.
 */
namespace GunsNPricesCpr\Vendor\GunsNPrices;

use GunsNPricesCpr\Vendor\GunsNPrices\Internal\Calibers;
use GunsNPricesCpr\Vendor\GunsNPrices\Internal\Models;
use GunsNPricesCpr\Vendor\GunsNPrices\Internal\Specs;
use GunsNPricesCpr\Vendor\GunsNPrices\Internal\Text;

/**
 * Parse firearm and ammunition product titles: brand and model (Glock 19 Gen 5 MOS), caliber, magazine capacity,
 * barrel length, rounds per box and cost per round.
 *
 * Built on the model dictionaries and caliber list behind https://gunsnprices.com. A port of the JavaScript and
 * Python packages with identical results. Every method is static and returns plain arrays.
 *
 * A caliber is ['id' => '9x19mmparabellum', 'name' => '9×19mm Parabellum', 'type' => 'handgun', 'url' => 'https://...'].
 * A model is ['brand', 'family', 'model', 'generation', 'configs' => string[], 'features' => string[], 'source' => 'title'|'mpn'].
 */
final class Parser
{
    const VERSION = '0.1.0';

    /**
     * Parse a product title into brand, model, caliber, capacity, barrel length, rounds per box and cost per round.
     *
     * @param string|null $title product title as a store shows it
     * @param array $opts brand (manufacturer), mpn (part number), sku, price (with ammo it gives costPerRound),
     *                    kind ("firearm" or "ammo"; guessed from the title when omitted)
     * @return array{title: string, kind: string, brand: string, model: array|null, caliber: array|null, calibers: array[],
     *               capacity: int|null, barrelLength: float|null, rounds: int|null, costPerRound: float|null}
     */
    public static function parseTitle(?string $title, array $opts = []): array
    {
        $title = Text::clean($title);
        $brand = self::opt($opts, 'brand');
        $kind = isset($opts['kind']) ? $opts['kind'] : null;
        if ($kind !== 'firearm' && $kind !== 'ammo') {
            $kind = Specs::looksLikeAmmo($title) ? 'ammo' : 'firearm';
        }
        $calibers = Calibers::findCalibers($title);
        $base = [
            'title' => $title, 'kind' => $kind, 'brand' => $brand !== null ? $brand : '', 'model' => null,
            'caliber' => $calibers ? $calibers[0] : null, 'calibers' => $calibers,
            'capacity' => null, 'barrelLength' => null, 'rounds' => null, 'costPerRound' => null,
        ];
        if ($kind === 'ammo') {
            $rounds = Specs::roundsPerBox($title);
            $price = isset($opts['price']) ? $opts['price'] : null;
            $base['rounds'] = $rounds;
            $base['costPerRound'] = $price ? Specs::costPerRound($price, $rounds) : null;
            return $base;
        }
        $model = Models::identifyModel($title, $brand, (string) self::opt($opts, 'mpn'), (string) self::opt($opts, 'sku'));
        $base['brand'] = $model !== null ? $model['brand'] : ($brand !== null ? $brand : '');
        $base['model'] = $model;
        $base['capacity'] = Specs::magazineCapacity($title);
        $base['barrelLength'] = Specs::barrelLength($title);
        return $base;
    }

    /**
     * Find the brand, model family and model in a firearm product title.
     *
     * @param string|null $title product title as a store shows it
     * @param array $opts brand (manufacturer), mpn (part number) and sku, if known
     * @return array{brand: string, family: string, model: string, generation: string, configs: string[], features: string[], source: string}|null
     */
    public static function identifyModel(?string $title, array $opts = []): ?array
    {
        return Models::identifyModel(Text::clean($title), self::opt($opts, 'brand'), (string) self::opt($opts, 'mpn'), (string) self::opt($opts, 'sku'));
    }

    /**
     * Brands covered by the model dictionaries.
     *
     * @return string[]
     */
    public static function listBrands(): array
    {
        return Models::listBrands();
    }

    /**
     * All calibers mentioned in the text, in order of appearance, without duplicates.
     *
     * @return array<int, array{id: string, name: string, type: string, url: string}>
     */
    public static function findCalibers(?string $text): array
    {
        return Calibers::findCalibers(Text::clean($text));
    }

    /**
     * The first caliber mentioned in the text, or null.
     *
     * @return array{id: string, name: string, type: string, url: string}|null
     */
    public static function findCaliber(?string $text): ?array
    {
        return Calibers::findCaliber(Text::clean($text));
    }

    /**
     * Normalize a caliber field ("9mm Luger", "5.56 NATO", "12 GA"); falls back to the first caliber found inside it.
     *
     * @return array{id: string, name: string, type: string, url: string}|null
     */
    public static function normalizeCaliber(?string $value): ?array
    {
        return Calibers::normalizeCaliber(Text::clean($value));
    }

    /**
     * The full caliber list, sorted by name.
     *
     * @return array<int, array{id: string, name: string, type: string, url: string}>
     */
    public static function listCalibers(): array
    {
        return Calibers::listCalibers();
    }

    /** Magazine capacity of a firearm or magazine from its title ("17RD", "15+1", "10-shot"), or null. For "15+1" it is 15. */
    public static function magazineCapacity(?string $title): ?int
    {
        return Specs::magazineCapacity(Text::clean($title));
    }

    /**
     * Barrel length in inches from a firearm title ('4.25"', "16 in", "20-inch", '16 1/2"', "22 BBL"), or null.
     * Chamber length (3" on shotguns), twist rate, handguard and length-of-pull values are skipped.
     */
    public static function barrelLength(?string $title): ?float
    {
        return Specs::barrelLength(Text::clean($title));
    }

    /** Number of rounds in one package of ammunition ("20rds", "50/box", "Box of 25", "1,000 Rounds"), or null. */
    public static function roundsPerBox(?string $title): ?int
    {
        return Specs::roundsPerBox(Text::clean($title));
    }

    /**
     * Price of one round, rounded to 4 decimals (null without a price or a round count).
     *
     * @param int|float|string|null $price
     * @param int|float|null $rounds
     */
    public static function costPerRound($price, $rounds): ?float
    {
        return Specs::costPerRound($price, $rounds);
    }

    /** True if the title reads like ammunition (bullet weight, "ammo", "20/box", ...) rather than a firearm. */
    public static function looksLikeAmmo(?string $title): bool
    {
        return Specs::looksLikeAmmo(Text::clean($title));
    }

    /** A string option, or null when missing or empty (JavaScript's `opts.brand || null`). */
    private static function opt(array $opts, string $name): ?string
    {
        $v = isset($opts[$name]) ? $opts[$name] : null;
        if (!is_scalar($v) || $v === '' || $v === false || $v === 0 || $v === 0.0) {
            return null;
        }
        return Text::clean((string) $v);
    }
}
