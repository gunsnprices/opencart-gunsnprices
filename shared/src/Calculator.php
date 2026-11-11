<?php
/**
 * Ammo Cost per Round & Firearm Specs for OpenCart, by GunsNPrices (https://gunsnprices.com).
 * GPL-3.0-or-later. See LICENSE.
 */

namespace GunsNPricesCpr;

use GunsNPricesCpr\Vendor\GunsNPrices\Parser;

/**
 * Reads round counts and firearm specs from text. No OpenCart code here, so it can be tested on its own.
 */
final class Calculator {
	/** Largest round count accepted from a plain number (a pallet of 22 LR is 50,000). */
	const MAX_ROUNDS = 100000;

	/**
	 * Rounds per box read from a product name: "50 Rounds", "Box of 20", "20rds", "1,000 Round Case".
	 *
	 * @param string|null $name
	 * @return int|null
	 */
	public static function roundsFromName($name) {
		$rounds = self::parser('roundsPerBox', self::text($name));

		return ($rounds !== null && $rounds > 0 && $rounds <= self::MAX_ROUNDS) ? $rounds : null;
	}

	/**
	 * Rounds per box read from an attribute value or an option value name. Accepts a plain number ("50", "1,000")
	 * and everything roundsFromName() reads ("50 Rounds", "Box of 20").
	 *
	 * @param string|null $text
	 * @return int|null
	 */
	public static function roundsFromText($text) {
		$text = trim(self::text($text));

		if ($text === '') {
			return null;
		}

		if (preg_match('/^(?:\d{1,3}(?:,\d{3})+|\d+)$/', $text)) {
			$rounds = (int)str_replace(',', '', $text);

			return ($rounds > 0 && $rounds <= self::MAX_ROUNDS) ? $rounds : null;
		}

		return self::roundsFromName($text);
	}

	/**
	 * True if a product name reads like ammunition. Uses the parser's test (bullet weight in grains, "ammo",
	 * "20/box" and so on) and also accepts shotgun shells, whose names often have no grain weight:
	 * "Federal Top Gun 12 GA 2-3/4" 1 1/8 oz #8 25 Shells", "Remington 12 Gauge 00 Buck 5 Rounds".
	 *
	 * @param string|null $name
	 * @return bool
	 */
	public static function isAmmoName($name) {
		$name = self::text($name);

		if (self::parser('looksLikeAmmo', $name)) {
			return true;
		}

		if (self::roundsFromName($name) === null) {
			return false;
		}

		if (preg_match('/\b(?:pistol|rifle|shotgun|revolver|carbine|handgun|magazines?|mags?|holsters?|cases?\s+for)\b/i', $name)) {
			return false;
		}

		return (bool)preg_match('/\b(?:shells?|buck(?:shot)?|slugs?|ammo|ammunition|cartridges?|shotshells?)\b|\d\s*oz\b/i', $name);
	}

	/**
	 * Price of one round, or null without a price or a round count.
	 *
	 * @param float|int|string|null $price
	 * @param int|null              $rounds
	 * @return float|null
	 */
	public static function costPerRound($price, $rounds) {
		if (!is_numeric($price) || (float)$price <= 0 || !$rounds || $rounds <= 0) {
			return null;
		}

		return (float)$price / (int)$rounds;
	}

	/**
	 * Price of one option value: the product price plus or minus the option price, as OpenCart's cart adds it.
	 *
	 * @param float  $base
	 * @param string $prefix '+' or '-'
	 * @param float  $price
	 * @return float
	 */
	public static function optionTotal($base, $prefix, $price) {
		$price = (float)$price;

		if ($prefix === '-') {
			return (float)$base - $price;
		}

		return (float)$base + $price;
	}

	/**
	 * Firearm specs read from a product name.
	 *
	 * @param string|null $name
	 * @return array{caliber: array|null, capacity: int|null, barrel: float|null} caliber has id, name, type and url
	 */
	public static function firearmSpecs($name) {
		$name = self::text($name);

		return [
			'caliber'  => self::parser('findCaliber', $name),
			'capacity' => self::parser('magazineCapacity', $name),
			'barrel'   => self::parser('barrelLength', $name)
		];
	}

	/**
	 * Everything the extension reads from one product name, for the "Try a product name" box in the admin.
	 *
	 * @param string|null $name
	 * @return array{ammo: bool, rounds: int|null, caliber: array|null, capacity: int|null, barrel: float|null}
	 */
	public static function describe($name) {
		$ammo = self::isAmmoName($name);
		$specs = self::firearmSpecs($name);

		return [
			'ammo'     => $ammo,
			'rounds'   => self::roundsFromName($name),
			'caliber'  => $specs['caliber'],
			'capacity' => $ammo ? null : $specs['capacity'],
			'barrel'   => $ammo ? null : $specs['barrel']
		];
	}

	/**
	 * A barrel length for display: 4.02, 16 and 18.5 rather than 16.0 and 18.50.
	 *
	 * @param float  $value
	 * @param string $decimal_point
	 * @return string
	 */
	public static function formatLength($value, $decimal_point = '.') {
		$text = rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');

		return str_replace('.', $decimal_point, $text);
	}

	/**
	 * Formats an amount with more decimals than the currency has, keeping the currency's symbols and spacing.
	 *
	 * OpenCart's currency formatter always uses the currency's own decimal places. This takes what it prints for zero
	 * ("$0.00", "0,00 €") and puts the longer number where the zero was.
	 *
	 * @param string $zero_formatted What the currency formatter prints for 0, for example "$0.00".
	 * @param string $zero_number    The number part of it, for example "0.00".
	 * @param string $number         The number to put in, for example "0.385".
	 * @return string
	 */
	public static function replaceNumber($zero_formatted, $zero_number, $number) {
		$pos = strrpos($zero_formatted, $zero_number);

		if ($pos === false) {
			return $number;
		}

		return substr_replace($zero_formatted, $number, $pos, strlen($zero_number));
	}

	/**
	 * Calls a parser method with OpenCart's error handler kept out of the way of suppressed warnings.
	 *
	 * The parser compiles its caliber list into one large regular expression the first time it is used. If PCRE
	 * cannot compile one that big, the parser splits it; it tests this with @preg_match(), which raises a warning
	 * that the @ operator hides. Some OpenCart error handlers ignore the @ operator (4.1.0.0 does, 4.1.0.4 does not):
	 * they print the warning into the page or redirect to the error page. While the parser runs, warnings hidden with
	 * @ are dropped here and every other error goes to the handler that was set before.
	 *
	 * @param string $method Parser method
	 * @param mixed  ...$args
	 * @return mixed
	 */
	private static function parser($method, ...$args) {
		$previous = null;

		$previous = set_error_handler(function ($errno, $errstr, $errfile = '', $errline = 0) use (&$previous) {
			if (!(error_reporting() & $errno)) {
				return true;
			}

			return $previous ? (bool)call_user_func($previous, $errno, $errstr, $errfile, $errline) : false;
		});

		try {
			return call_user_func_array([Parser::class, $method], $args);
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * OpenCart stores names HTML-escaped ("9mm 115gr &quot;FMJ&quot;"); the parser decodes entities itself, so this only
	 * turns null and non-strings into strings and drops tags.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function text($value) {
		if ($value === null || is_array($value) || is_object($value)) {
			return '';
		}

		return strip_tags((string)$value);
	}
}
