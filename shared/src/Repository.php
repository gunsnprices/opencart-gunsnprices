<?php
/**
 * Ammo Cost per Round & Firearm Specs for OpenCart, by GunsNPrices (https://gunsnprices.com).
 * GPL-3.0-or-later. See LICENSE.
 */

namespace GunsNPricesCpr;

/**
 * Read-only queries for attribute values, option values and categories. The tables and columns used here are the same
 * in OpenCart 3.0 and 4.x. Every query takes a list of products, so a category page needs one query, not one per
 * product.
 */
final class Repository {
	/** @var object OpenCart DB object with query() */
	private $db;

	/** @var string */
	private $prefix;

	/** @var int */
	private $language_id;

	/**
	 * @param object $db          OpenCart's $this->db
	 * @param string $prefix      DB_PREFIX
	 * @param int    $language_id config_language_id of the current request
	 */
	public function __construct($db, $prefix, $language_id) {
		$this->db = $db;
		$this->prefix = (string)$prefix;
		$this->language_id = (int)$language_id;
	}

	/**
	 * Text of one attribute for each product, in the current language.
	 *
	 * @param int[] $product_ids
	 * @param int   $attribute_id
	 * @return array<int, string> product_id => text
	 */
	public function attributeTexts(array $product_ids, $attribute_id) {
		$ids = self::ids($product_ids);

		if (!$ids || (int)$attribute_id <= 0) {
			return [];
		}

		$query = $this->db->query("SELECT `product_id`, `text` FROM `" . $this->prefix . "product_attribute` WHERE `attribute_id` = '" . (int)$attribute_id . "' AND `language_id` = '" . $this->language_id . "' AND `product_id` IN (" . implode(',', $ids) . ")");

		$out = [];

		foreach ($query->rows as $row) {
			$out[(int)$row['product_id']] = (string)$row['text'];
		}

		return $out;
	}

	/**
	 * Values of one option for each product, in stock or not subtracting stock, in the option's sort order.
	 *
	 * @param int[] $product_ids
	 * @param int   $option_id
	 * @return array<int, array<int, array{id: int, name: string, price: float, prefix: string}>> product_id => values
	 */
	public function optionValuesByProduct(array $product_ids, $option_id) {
		$ids = self::ids($product_ids);

		if (!$ids || (int)$option_id <= 0) {
			return [];
		}

		$out = [];

		foreach ($this->optionValueRows("`pov`.`option_id` = '" . (int)$option_id . "' AND `pov`.`product_id` IN (" . implode(',', $ids) . ")") as $row) {
			$out[(int)$row['product_id']][] = self::optionValue($row);
		}

		return $out;
	}

	/**
	 * Option values by their product_option_value_id.
	 *
	 * @param int[] $product_option_value_ids
	 * @return array<int, array{id: int, name: string, price: float, prefix: string}> product_option_value_id => value
	 */
	public function optionValuesById(array $product_option_value_ids) {
		$ids = self::ids($product_option_value_ids);

		if (!$ids) {
			return [];
		}

		$out = [];

		foreach ($this->optionValueRows("`pov`.`product_option_value_id` IN (" . implode(',', $ids) . ")") as $row) {
			$out[(int)$row['product_option_value_id']] = self::optionValue($row);
		}

		return $out;
	}

	/**
	 * Which of the products are in one of the categories or in a subcategory of one.
	 *
	 * @param int[] $product_ids
	 * @param int[] $category_ids
	 * @return array<int, true> product_id => true for products in the categories
	 */
	public function productsInCategories(array $product_ids, array $category_ids) {
		$ids = self::ids($product_ids);
		$categories = self::ids($category_ids);

		if (!$ids || !$categories) {
			return [];
		}

		$query = $this->db->query("SELECT DISTINCT `p2c`.`product_id` FROM `" . $this->prefix . "product_to_category` `p2c` LEFT JOIN `" . $this->prefix . "category_path` `cp` ON (`cp`.`category_id` = `p2c`.`category_id`) WHERE `p2c`.`product_id` IN (" . implode(',', $ids) . ") AND `cp`.`path_id` IN (" . implode(',', $categories) . ")");

		$out = [];

		foreach ($query->rows as $row) {
			$out[(int)$row['product_id']] = true;
		}

		return $out;
	}

	/**
	 * @param string $where SQL condition on `pov`
	 * @return array<int, array<string, mixed>>
	 */
	private function optionValueRows($where) {
		$query = $this->db->query("SELECT `pov`.`product_option_value_id`, `pov`.`product_id`, `pov`.`price`, `pov`.`price_prefix`, `ovd`.`name` FROM `" . $this->prefix . "product_option_value` `pov` LEFT JOIN `" . $this->prefix . "option_value` `ov` ON (`ov`.`option_value_id` = `pov`.`option_value_id`) LEFT JOIN `" . $this->prefix . "option_value_description` `ovd` ON (`ovd`.`option_value_id` = `pov`.`option_value_id` AND `ovd`.`language_id` = '" . $this->language_id . "') WHERE " . $where . " AND (`pov`.`subtract` = '0' OR `pov`.`quantity` > '0') ORDER BY `ov`.`sort_order` ASC, `pov`.`product_option_value_id` ASC");

		return $query->rows;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{id: int, name: string, price: float, prefix: string}
	 */
	private static function optionValue(array $row) {
		return [
			'id'     => (int)$row['product_option_value_id'],
			'name'   => (string)$row['name'],
			'price'  => (float)$row['price'],
			'prefix' => (string)$row['price_prefix']
		];
	}

	/**
	 * Positive integer ids, without duplicates.
	 *
	 * @param array<int, mixed> $ids
	 * @return int[]
	 */
	private static function ids(array $ids) {
		$out = [];

		foreach ($ids as $id) {
			$id = (int)$id;

			if ($id > 0) {
				$out[$id] = $id;
			}
		}

		return array_values($out);
	}
}
