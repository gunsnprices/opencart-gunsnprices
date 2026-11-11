<?php
/**
 * Ammo Cost per Round & Firearm Specs for OpenCart, by GunsNPrices (https://gunsnprices.com).
 * GPL-3.0-or-later. See LICENSE.
 */

namespace GunsNPricesCpr;

/**
 * The extension's settings: defaults, allowed values and conversion from and to OpenCart setting keys.
 */
final class Settings {
	/** OpenCart setting code; every key is stored as module_gunsnprices_cpr_<name>. */
	const CODE = 'module_gunsnprices_cpr';

	/**
	 * Default values. Links to gunsnprices.com (caliber_links) are off by default.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return [
			'status'           => 0,
			// Cost per round
			'product'          => 1,          // on the product page
			'list'             => 1,          // in product lists (category, search, specials, brands, modules)
			'position'         => 'inline',   // inline: after the price; below: on its own line; none: template variable only
			'format'           => 'short',    // short: $0.38/rd; long: $0.38 per round
			'decimals'         => 'currency', // currency: as many decimals as the currency; extra: one more
			'source'           => 'name',     // name, attribute or option
			'attribute_id'     => 0,
			'option_id'        => 0,
			'ammo_mode'        => 'guess',    // guess: from the product name; category: products in ammo_category
			'ammo_category'    => [],
			// Firearm specs
			'specs'            => 1,
			'specs_position'   => 'tab',      // tab: Specification tab; description: top of the description
			'specs_fields'     => ['caliber', 'capacity', 'barrel'],
			'firearm_mode'     => 'guess',    // guess: names with a caliber that are not ammunition; category
			'firearm_category' => [],
			'caliber_links'    => 0
		];
	}

	/**
	 * Allowed values of the choice settings; the first one is the default.
	 *
	 * @return array<string, string[]>
	 */
	public static function choices() {
		return [
			'position'       => ['inline', 'below', 'none'],
			'format'         => ['short', 'long'],
			'decimals'       => ['currency', 'extra'],
			'source'         => ['name', 'attribute', 'option'],
			'ammo_mode'      => ['guess', 'category'],
			'specs_position' => ['tab', 'description'],
			'firearm_mode'   => ['guess', 'category']
		];
	}

	/**
	 * Spec fields the firearm block can show, in display order.
	 *
	 * @return string[]
	 */
	public static function specFields() {
		return ['caliber', 'capacity', 'barrel'];
	}

	/**
	 * Cleans settings from the config or a form: unknown keys are dropped, missing keys get their defaults,
	 * values are cast to their types and checked against the allowed choices.
	 *
	 * @param array<string, mixed> $raw Unprefixed keys.
	 * @return array<string, mixed>
	 */
	public static function normalize(array $raw) {
		$defaults = self::defaults();
		$choices = self::choices();
		$out = [];

		foreach ($defaults as $key => $default) {
			$value = array_key_exists($key, $raw) ? $raw[$key] : $default;

			if (isset($choices[$key])) {
				$value = in_array((string)$value, $choices[$key], true) ? (string)$value : $default;
			} elseif (is_array($default)) {
				$value = self::toList($value);

				if ($key === 'specs_fields') {
					$value = array_values(array_intersect(self::specFields(), $value));
				} else {
					$value = array_values(array_unique(array_filter(array_map('intval', $value), function ($id) {
						return $id > 0;
					})));
				}
			} else {
				$value = max(0, (int)$value);

				if ($key !== 'attribute_id' && $key !== 'option_id') {
					$value = $value ? 1 : 0;
				}
			}

			$out[$key] = $value;
		}

		return $out;
	}

	/**
	 * Reads the settings of the current store from OpenCart's config.
	 *
	 * @param callable $get function (string $key): mixed, for example [$this->config, 'get']
	 * @return array<string, mixed>
	 */
	public static function fromConfig(callable $get) {
		$raw = [];

		foreach (array_keys(self::defaults()) as $key) {
			$value = $get(self::CODE . '_' . $key);

			if ($value !== null) {
				$raw[$key] = $value;
			}
		}

		return self::normalize($raw);
	}

	/**
	 * Settings with OpenCart keys, ready for model_setting_setting->editSetting().
	 *
	 * @param array<string, mixed> $settings Unprefixed keys.
	 * @return array<string, mixed>
	 */
	public static function toConfig(array $settings) {
		$out = [];

		foreach (self::normalize($settings) as $key => $value) {
			$out[self::CODE . '_' . $key] = $value;
		}

		return $out;
	}

	/**
	 * Strips the setting prefix from posted form keys.
	 *
	 * @param array<string, mixed> $post
	 * @return array<string, mixed>
	 */
	public static function fromPost(array $post) {
		$raw = [];
		$prefix = self::CODE . '_';

		foreach ($post as $key => $value) {
			if (strpos((string)$key, $prefix) === 0) {
				$raw[substr($key, strlen($prefix))] = $value;
			}
		}

		// Unchecked checkbox lists are not posted at all.
		foreach (['ammo_category', 'firearm_category', 'specs_fields'] as $key) {
			if (!isset($raw[$key])) {
				$raw[$key] = [];
			}
		}

		return self::normalize($raw);
	}

	/**
	 * A list from a setting value: an array, a JSON array (how OpenCart stores arrays) or a comma-separated string.
	 *
	 * @param mixed $value
	 * @return array<int, mixed>
	 */
	private static function toList($value) {
		if (is_array($value)) {
			return array_values($value);
		}

		if (is_string($value) && $value !== '') {
			$decoded = json_decode($value, true);

			if (is_array($decoded)) {
				return array_values($decoded);
			}

			return explode(',', $value);
		}

		return [];
	}
}
