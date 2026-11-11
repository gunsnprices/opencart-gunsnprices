<?php
/**
 * Ammo Cost per Round & Firearm Specs for OpenCart, by GunsNPrices (https://gunsnprices.com).
 * GPL-3.0-or-later. See LICENSE.
 */

namespace GunsNPricesCpr;

/**
 * Adds the cost per round and the firearm specs to the data OpenCart passes to its product templates.
 *
 * The same class serves OpenCart 3.0 and 4.x: everything version-specific (currency, tax, loading a product) comes in
 * as callables from the event controller of each build.
 */
final class Service {
	/** Inline styles keep the extension free of extra CSS files; themes can override them via .gnp-cpr. */
	const STYLE_PRODUCT = 'font-size:.5em;font-weight:normal;white-space:nowrap';
	const STYLE_LIST = 'font-size:.85em;font-weight:normal;white-space:nowrap';

	/** @var array<string, mixed> */
	private $settings;

	/** @var Repository */
	private $repository;

	/** @var array<string, mixed> */
	private $env;

	/** @var array<string, string> */
	private $lang;

	/** @var array<int, array{price: float, special: float, tax_class_id: int, name: string}> */
	private $rows = [];

	/** @var array<int, string|null> product_id => attribute text */
	private $attributes = [];

	/** @var array<int, array> product_id => option values */
	private $options = [];

	/** @var array<string, array<int, bool>> 'ammo'|'firearm' => product_id => in the categories */
	private $categories = ['ammo' => [], 'firearm' => []];

	/**
	 * @param array<string, mixed>  $settings   Settings::normalize() output
	 * @param Repository            $repository
	 * @param array<string, mixed>  $env        tax: function (float $amount, int $tax_class_id): float, the price as
	 *                                          the store displays it (with or without tax);
	 *                                          money: function (float $amount, bool $extra_decimal): string, an
	 *                                          amount in the default currency formatted in the shopper's currency;
	 *                                          product: function (int $product_id): array, the product row
	 *                                          (model catalog/product getProduct);
	 *                                          decimal_point: string
	 * @param array<string, string> $lang       Catalog language strings without the gnp_ prefix.
	 */
	public function __construct(array $settings, Repository $repository, array $env, array $lang) {
		$this->settings = Settings::normalize($settings);
		$this->repository = $repository;
		$this->env = $env + ['decimal_point' => '.'];
		$this->lang = $lang + self::defaultLang();
	}

	/**
	 * English strings, used for any key the language file does not have.
	 *
	 * @return array<string, string>
	 */
	public static function defaultLang() {
		return [
			'short'          => '%s/rd',
			'long'           => '%s per round',
			'from'           => 'from %s',
			'inline'         => '(%s)',
			'option'         => '%1$s – %2$s',
			'specs'          => 'Firearm specs',
			'caliber'        => 'Caliber',
			'capacity'       => 'Capacity',
			'barrel'         => 'Barrel length',
			'capacity_value' => '%s rounds',
			'barrel_value'   => '%s in'
		];
	}

	/**
	 * @return bool
	 */
	public function enabled() {
		return (bool)$this->settings['status'];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Remembers product rows returned by the catalog/product model (one row or a list), so prices can be read without
	 * another query. Called from a model "after" event.
	 *
	 * @param mixed $output
	 * @return void
	 */
	public function remember($output) {
		if (!$this->enabled() || !is_array($output) || !$output) {
			return;
		}

		if (isset($output['product_id'])) {
			$this->rememberRow($output);

			return;
		}

		foreach ($output as $row) {
			if (!is_array($row) || !isset($row['product_id'])) {
				return;
			}

			$this->rememberRow($row);
		}
	}

	/**
	 * The cost per round of a product as the shopper sees it, in the default currency.
	 *
	 * @param int $product_id
	 * @return array{amount: float, from: bool}|null from is true when it is the lowest of several option values
	 */
	public function quote($product_id) {
		$product_id = (int)$product_id;
		$row = $this->row($product_id);

		if (!$row) {
			return null;
		}

		$s = $this->settings;

		if ($s['ammo_mode'] === 'category' && !$this->inCategories($product_id, 'ammo')) {
			return null;
		}

		$base = $row['special'] > 0 ? $row['special'] : $row['price'];

		if ($base <= 0) {
			return null;
		}

		if ($s['source'] === 'option' && $s['option_id']) {
			$best = null;
			$count = 0;

			foreach ($this->optionValues($product_id) as $value) {
				$amount = $this->optionAmount($row, $base, $value);

				if ($amount !== null) {
					$count++;

					if ($best === null || $amount < $best) {
						$best = $amount;
					}
				}
			}

			if ($best !== null) {
				return ['amount' => $best, 'from' => $count > 1];
			}
		} elseif ($s['source'] === 'attribute' && $s['attribute_id']) {
			$rounds = Calculator::roundsFromText($this->attributeText($product_id));

			if ($rounds) {
				return ['amount' => $this->perRound($base, $row, $rounds), 'from' => false];
			}
		}

		// The product name: always the fallback when the attribute or option has no round count.
		if ($s['ammo_mode'] === 'guess' && !Calculator::isAmmoName($row['name'])) {
			return null;
		}

		$rounds = Calculator::roundsFromName($row['name']);

		if (!$rounds) {
			return null;
		}

		return ['amount' => $this->perRound($base, $row, $rounds), 'from' => false];
	}

	/**
	 * The cost per round as text in the shopper's currency: "$0.38/rd", "$0.38 per round", "from $0.30/rd".
	 *
	 * @param array{amount: float, from: bool} $quote
	 * @return string
	 */
	public function costText(array $quote) {
		$money = call_user_func($this->env['money'], (float)$quote['amount'], $this->settings['decimals'] === 'extra');
		$text = sprintf($this->lang[$this->settings['format']], $money);

		if (!empty($quote['from'])) {
			$text = sprintf($this->lang['from'], $text);
		}

		return $text;
	}

	/**
	 * The HTML added to a price.
	 *
	 * @param string $text    costText() output
	 * @param string $context 'product' or 'list'
	 * @return string
	 */
	public function costHtml($text, $context) {
		$style = $context === 'product' ? self::STYLE_PRODUCT : self::STYLE_LIST;

		if ($this->settings['position'] === 'below') {
			return '<span class="gnp-cpr gnp-cpr-below" style="display:block;' . $style . '">' . $text . '</span>';
		}

		return ' <span class="gnp-cpr" style="' . $style . '">' . sprintf($this->lang['inline'], $text) . '</span>';
	}

	/**
	 * Adds the cost per round to one product of a list (OpenCart 4 product/thumb data, an item of $data['products']
	 * in OpenCart 3). Sets gnp_cost_per_round for themes and, unless the position is "none", appends it to the price.
	 *
	 * @param array<string, mixed> $card
	 * @return void
	 */
	public function decorateCard(array &$card) {
		if (!$this->enabled() || !$this->settings['list'] || empty($card['product_id']) || empty($card['price']) || !is_string($card['price'])) {
			return;
		}

		$quote = $this->quote((int)$card['product_id']);

		if (!$quote) {
			return;
		}

		$text = $this->costText($quote);
		$card['gnp_cost_per_round'] = $text;

		if ($this->settings['position'] !== 'none') {
			$key = (!empty($card['special']) && is_string($card['special'])) ? 'special' : 'price';
			$card[$key] .= $this->costHtml($text, 'list');
		}
	}

	/**
	 * @param array<int, mixed> $cards
	 * @return void
	 */
	public function decorateCards(array &$cards) {
		foreach ($cards as &$card) {
			if (is_array($card)) {
				$this->decorateCard($card);
			}
		}

		unset($card);
	}

	/**
	 * Adds the cost per round, per-option costs and the firearm specs to the product page data.
	 *
	 * @param array<string, mixed> $data Data of the product/product template.
	 * @return void
	 */
	public function decorateProductPage(array &$data) {
		if (!$this->enabled() || empty($data['product_id'])) {
			return;
		}

		$product_id = (int)$data['product_id'];
		$row = $this->row($product_id);

		if (!$row) {
			return;
		}

		if ($this->settings['product'] && !empty($data['price']) && is_string($data['price'])) {
			$quote = $this->quote($product_id);

			if ($quote) {
				$text = $this->costText($quote);
				$data['gnp_cost_per_round'] = $text;

				if ($this->settings['position'] !== 'none') {
					$key = (!empty($data['special']) && is_string($data['special'])) ? 'special' : 'price';
					$data[$key] .= $this->costHtml($text, 'product');
				}
			}

			if ($this->settings['source'] === 'option' && $this->settings['option_id'] && !empty($data['options']) && is_array($data['options'])) {
				$this->decorateOptions($data['options'], $row);
			}
		}

		$this->addSpecs($data, $product_id, $row);

		// OpenCart 3 passes related products to the product page as $data['products'].
		if (!empty($data['products']) && is_array($data['products'])) {
			$this->decorateCards($data['products']);
		}
	}

	/**
	 * The firearm spec rows for a product: label => value (HTML).
	 *
	 * @param int                  $product_id
	 * @param string[]             $skip_labels Lower-case labels of attributes the product already has.
	 * @return array<string, array{label: string, value: string}>
	 */
	public function specRows($product_id, array $skip_labels = []) {
		$product_id = (int)$product_id;
		$s = $this->settings;
		$row = $this->row($product_id);

		if (!$this->enabled() || !$s['specs'] || !$s['specs_fields'] || !$row) {
			return [];
		}

		if ($s['firearm_mode'] === 'category') {
			if (!$this->inCategories($product_id, 'firearm')) {
				return [];
			}
		} elseif (Calculator::isAmmoName($row['name']) || $this->quote($product_id) !== null) {
			return [];
		}

		$specs = Calculator::firearmSpecs($row['name']);

		if ($s['firearm_mode'] === 'guess' && !$specs['caliber']) {
			return [];
		}

		$rows = [];

		foreach ($s['specs_fields'] as $field) {
			$label = $this->lang[$field];

			if (in_array(self::lower($label), $skip_labels, true)) {
				continue;
			}

			$value = '';

			if ($field === 'caliber' && $specs['caliber']) {
				$value = self::escape($specs['caliber']['name']);

				if ($s['caliber_links'] && !empty($specs['caliber']['url'])) {
					$value = '<a href="' . self::escape($specs['caliber']['url']) . '" target="_blank" rel="noopener">' . $value . '</a>';
				}
			} elseif ($field === 'capacity' && $specs['capacity']) {
				$value = self::escape(sprintf($this->lang['capacity_value'], (int)$specs['capacity']));
			} elseif ($field === 'barrel' && $specs['barrel']) {
				$value = self::escape(sprintf($this->lang['barrel_value'], Calculator::formatLength($specs['barrel'], $this->env['decimal_point'])));
			}

			if ($value !== '') {
				$rows[$field] = ['label' => self::escape($label), 'value' => $value];
			}
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param int                  $product_id
	 * @param array                $row
	 * @return void
	 */
	private function addSpecs(array &$data, $product_id, array $row) {
		if (!$this->settings['specs']) {
			return;
		}

		if (!isset($data['attribute_groups']) || !is_array($data['attribute_groups'])) {
			$data['attribute_groups'] = [];
		}

		// Specs the store already gives as attributes ("Caliber", "Capacity") are not repeated.
		$skip = [];

		foreach ($data['attribute_groups'] as $group) {
			if (isset($group['attribute']) && is_array($group['attribute'])) {
				foreach ($group['attribute'] as $attribute) {
					if (isset($attribute['name'])) {
						$skip[] = self::lower(html_entity_decode((string)$attribute['name'], ENT_QUOTES, 'UTF-8'));
					}
				}
			}
		}

		$rows = $this->specRows($product_id, $skip);

		if (!$rows) {
			return;
		}

		$data['gnp_specs'] = $rows;

		if ($this->settings['specs_position'] === 'description') {
			$html = '<table class="table table-bordered gnp-specs"><thead><tr><td colspan="2"><strong>' . self::escape($this->lang['specs']) . '</strong></td></tr></thead><tbody>';

			foreach ($rows as $field => $spec) {
				$html .= '<tr class="gnp-spec-' . $field . '"><td>' . $spec['label'] . '</td><td>' . $spec['value'] . '</td></tr>';
			}

			$html .= '</tbody></table>';
			$data['description'] = $html . (isset($data['description']) ? $data['description'] : '');

			return;
		}

		$attributes = [];

		foreach ($rows as $spec) {
			$attributes[] = ['attribute_id' => 0, 'name' => $spec['label'], 'text' => $spec['value']];
		}

		$data['attribute_groups'][] = [
			'attribute_group_id' => 0,
			'name'               => self::escape($this->lang['specs']),
			'attribute'          => $attributes
		];
	}

	/**
	 * Appends the cost per round to the values of the chosen option ("500 Rounds – $0.30/rd").
	 *
	 * @param array<int, mixed> $options $data['options'] of the product page
	 * @param array             $row
	 * @return void
	 */
	private function decorateOptions(array &$options, array $row) {
		$base = $row['special'] > 0 ? $row['special'] : $row['price'];
		$ids = [];

		foreach ($options as $option) {
			if (isset($option['option_id'], $option['product_option_value']) && (int)$option['option_id'] === (int)$this->settings['option_id'] && is_array($option['product_option_value'])) {
				foreach ($option['product_option_value'] as $value) {
					if (isset($value['product_option_value_id'])) {
						$ids[] = (int)$value['product_option_value_id'];
					}
				}
			}
		}

		if (!$ids) {
			return;
		}

		$raw = $this->repository->optionValuesById($ids);

		foreach ($options as &$option) {
			if (!isset($option['option_id'], $option['product_option_value']) || (int)$option['option_id'] !== (int)$this->settings['option_id'] || !is_array($option['product_option_value'])) {
				continue;
			}

			foreach ($option['product_option_value'] as &$value) {
				$id = isset($value['product_option_value_id']) ? (int)$value['product_option_value_id'] : 0;

				if (!isset($raw[$id], $value['name'])) {
					continue;
				}

				$amount = $this->optionAmount($row, $base, $raw[$id]);

				if ($amount !== null) {
					$text = $this->costText(['amount' => $amount, 'from' => false]);
					$value['gnp_cost_per_round'] = $text;
					$value['name'] = sprintf($this->lang['option'], $value['name'], $text);
				}
			}

			unset($value);
		}

		unset($option);
	}

	/**
	 * Cost per round of one option value, or null if its name has no round count.
	 *
	 * @param array                                               $row
	 * @param float                                               $base
	 * @param array{id: int, name: string, price: float, prefix: string} $value
	 * @return float|null
	 */
	private function optionAmount(array $row, $base, array $value) {
		$rounds = Calculator::roundsFromText($value['name']);

		if (!$rounds) {
			return null;
		}

		$total = Calculator::optionTotal($base, $value['prefix'], $value['price']);

		if ($total <= 0) {
			return null;
		}

		return $this->perRound($total, $row, $rounds);
	}

	/**
	 * @param float $price
	 * @param array $row
	 * @param int   $rounds
	 * @return float
	 */
	private function perRound($price, array $row, $rounds) {
		$display = (float)call_user_func($this->env['tax'], (float)$price, (int)$row['tax_class_id']);

		return (float)Calculator::costPerRound($display, $rounds);
	}

	/**
	 * @param int $product_id
	 * @return array{price: float, special: float, tax_class_id: int, name: string}|null
	 */
	private function row($product_id) {
		if (!isset($this->rows[$product_id]) && $product_id > 0 && isset($this->env['product'])) {
			$product = call_user_func($this->env['product'], $product_id);

			if (is_array($product) && $product) {
				$this->rememberRow($product + ['product_id' => $product_id]);
			}
		}

		return isset($this->rows[$product_id]) ? $this->rows[$product_id] : null;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return void
	 */
	private function rememberRow(array $row) {
		// tax_class_id tells product rows apart from option value and discount rows, which also have
		// product_id, price and (for option values) name.
		if (!isset($row['product_id'], $row['price'], $row['name'], $row['tax_class_id']) || !is_numeric($row['price'])) {
			return;
		}

		$this->rows[(int)$row['product_id']] = [
			'price'        => (float)$row['price'],
			'special'      => (isset($row['special']) && is_numeric($row['special'])) ? (float)$row['special'] : 0.0,
			'tax_class_id' => (int)$row['tax_class_id'],
			'name'         => (string)$row['name']
		];
	}

	/**
	 * Product ids that still need a lookup, the requested one first. Lookups are done for every product seen so far,
	 * so a list of 20 products needs one query.
	 *
	 * @param int                  $product_id
	 * @param array<int, mixed>    $done
	 * @return int[]
	 */
	private function pending($product_id, array $done) {
		$ids = [$product_id];

		foreach (array_keys($this->rows) as $id) {
			if ($id !== $product_id && !array_key_exists($id, $done)) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * @param int $product_id
	 * @return string
	 */
	private function attributeText($product_id) {
		if (!array_key_exists($product_id, $this->attributes)) {
			$ids = $this->pending($product_id, $this->attributes);
			$texts = $this->repository->attributeTexts($ids, $this->settings['attribute_id']);

			foreach ($ids as $id) {
				$this->attributes[$id] = isset($texts[$id]) ? $texts[$id] : null;
			}
		}

		return (string)$this->attributes[$product_id];
	}

	/**
	 * @param int $product_id
	 * @return array<int, array{id: int, name: string, price: float, prefix: string}>
	 */
	private function optionValues($product_id) {
		if (!array_key_exists($product_id, $this->options)) {
			$ids = $this->pending($product_id, $this->options);
			$values = $this->repository->optionValuesByProduct($ids, $this->settings['option_id']);

			foreach ($ids as $id) {
				$this->options[$id] = isset($values[$id]) ? $values[$id] : [];
			}
		}

		return $this->options[$product_id];
	}

	/**
	 * @param int    $product_id
	 * @param string $kind 'ammo' or 'firearm'
	 * @return bool
	 */
	private function inCategories($product_id, $kind) {
		$categories = $this->settings[$kind . '_category'];

		if (!$categories) {
			return false;
		}

		if (!array_key_exists($product_id, $this->categories[$kind])) {
			$ids = $this->pending($product_id, $this->categories[$kind]);
			$found = $this->repository->productsInCategories($ids, $categories);

			foreach ($ids as $id) {
				$this->categories[$kind][$id] = isset($found[$id]);
			}
		}

		return $this->categories[$kind][$product_id];
	}

	/**
	 * @param string $text
	 * @return string
	 */
	private static function escape($text) {
		return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
	}

	/**
	 * @param string $text
	 * @return string
	 */
	private static function lower($text) {
		return function_exists('mb_strtolower') ? mb_strtolower(trim($text), 'UTF-8') : strtolower(trim($text));
	}
}
