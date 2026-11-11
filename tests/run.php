<?php
/**
 * Tests for the shared code (Calculator, Settings, Repository, Service) and static checks of both packages.
 * No Composer or PHPUnit needed: php tests/run.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

set_error_handler(function ($severity, $message, $file, $line) {
	// Respect the @ operator, as OpenCart's own handler does (the parser probes PCRE limits with @preg_match).
	if (!(error_reporting() & $severity)) {
		return false;
	}
	throw new ErrorException($message, 0, $severity, $file, $line);
});

$root = dirname(__DIR__);
require $root . '/shared/autoload.php';

use GunsNPricesCpr\Calculator;
use GunsNPricesCpr\Repository;
use GunsNPricesCpr\Service;
use GunsNPricesCpr\Settings;

$passed = 0;
$failed = [];
$current = '';

function test($name, callable $fn) {
	global $passed, $failed, $current;
	$current = $name;
	try {
		$fn();
	} catch (Throwable $e) {
		$failed[] = "$name: " . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
	}
}

function same($expected, $actual, $label = '') {
	global $passed, $failed, $current;
	if ($expected === $actual) {
		$passed++;
		return;
	}
	$failed[] = "$current" . ($label !== '' ? " [$label]" : '') . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
}

function contains($needle, $haystack, $label = '') {
	same(true, strpos((string)$haystack, $needle) !== false, $label !== '' ? $label : "contains $needle");
}

function not_contains($needle, $haystack, $label = '') {
	same(false, strpos((string)$haystack, $needle) !== false, $label !== '' ? $label : "does not contain $needle");
}

/**
 * Stand-in for OpenCart's DB object: answers the three kinds of queries Repository sends from in-memory tables and
 * counts them.
 */
final class FakeDb {
	public $queries = [];
	public $attributes = []; // product_id => [attribute_id => text]
	public $options = [];    // product_id => [[product_option_value_id, option_id, name, price, prefix, subtract, quantity]]
	public $categories = []; // product_id => [category_id path ids...]

	public function query($sql) {
		$this->queries[] = $sql;
		$rows = [];
		preg_match_all('/IN \(([\d,]+)\)/', $sql, $in);
		$lists = array_map(function ($s) {
			return array_map('intval', explode(',', $s));
		}, $in[1]);

		if (strpos($sql, 'product_attribute') !== false) {
			preg_match("/`attribute_id` = '(\d+)'/", $sql, $m);
			foreach ($lists[0] as $pid) {
				if (isset($this->attributes[$pid][(int)$m[1]])) {
					$rows[] = ['product_id' => $pid, 'text' => $this->attributes[$pid][(int)$m[1]]];
				}
			}
		} elseif (strpos($sql, 'product_option_value') !== false) {
			preg_match("/`pov`.`option_id` = '(\d+)'/", $sql, $m);
			$by_id = strpos($sql, '`pov`.`product_option_value_id` IN') !== false;
			foreach ($this->options as $pid => $values) {
				foreach ($values as $v) {
					$match = $by_id ? in_array($v[0], $lists[0], true) : (in_array($pid, $lists[0], true) && $v[1] === (int)$m[1]);
					if ($match && (!$v[5] || $v[6] > 0)) {
						$rows[] = ['product_option_value_id' => $v[0], 'product_id' => $pid, 'name' => $v[2], 'price' => $v[3], 'price_prefix' => $v[4]];
					}
				}
			}
		} elseif (strpos($sql, 'product_to_category') !== false) {
			foreach ($lists[0] as $pid) {
				if (isset($this->categories[$pid]) && array_intersect($this->categories[$pid], $lists[1])) {
					$rows[] = ['product_id' => $pid];
				}
			}
		}

		return (object)['rows' => $rows, 'row' => $rows ? $rows[0] : [], 'num_rows' => count($rows)];
	}
}

/** A product row as OpenCart's catalog/product model returns it (names are stored HTML-escaped). */
function product_row($id, $name, $price, $special = null, $tax_class_id = 0) {
	return ['product_id' => $id, 'name' => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'), 'model' => 'M' . $id, 'price' => (string)$price, 'special' => $special === null ? null : (string)$special, 'tax_class_id' => (string)$tax_class_id, 'discount' => null];
}

/**
 * A Service with a fake store: USD default, optional currency rate and symbol, 20% tax on tax class 9.
 */
function service(array $settings, $db = null, array $catalog = [], array $currency = []) {
	$currency += ['rate' => 1.0, 'left' => '$', 'right' => '', 'places' => 2];
	$db = $db ?: new FakeDb();
	$env = [
		'tax'           => function ($amount, $tax_class_id) {
			return $tax_class_id === 9 ? $amount * 1.2 : $amount;
		},
		'money'         => function ($amount, $extra) use ($currency) {
			$places = $currency['places'] + ($extra ? 1 : 0);
			return $currency['left'] . number_format(round($amount * $currency['rate'], $places), $places, '.', ',') . $currency['right'];
		},
		'product'       => function ($id) use ($catalog) {
			return isset($catalog[$id]) ? $catalog[$id] : [];
		},
		'decimal_point' => '.'
	];

	return new Service($settings + ['status' => 1], new Repository($db, 'oc_', 1), $env, []);
}

function card($id, $price = '$18.99', $special = false) {
	return ['product_id' => $id, 'name' => 'x', 'price' => $price, 'special' => $special, 'tax' => false];
}

// ---------------------------------------------------------------- Calculator

test('rounds from product names', function () {
	same(50, Calculator::roundsFromName('Federal American Eagle 9mm Luger 115gr FMJ 50 Rounds'));
	same(1000, Calculator::roundsFromName('PMC Bronze 5.56x45mm 55gr FMJ 1000 Round Case'));
	same(1000, Calculator::roundsFromName('Tula 7.62x39 122gr FMJ 1,000 Rounds'));
	same(20, Calculator::roundsFromName('Winchester USA 9mm 115 Grain FMJ, Box of 20'));
	same(25, Calculator::roundsFromName('Hornady Critical Defense 9mm 115gr FTX 25/Box'));
	same(25, Calculator::roundsFromName('Federal Top Gun 12 GA 2-3/4&quot; 1 1/8 oz #8 25 Shells'));
	same(null, Calculator::roundsFromName('Winchester USA 9mm 115 Grain FMJ'));
	same(null, Calculator::roundsFromName(''));
	same(null, Calculator::roundsFromName(null));
});

test('rounds from attribute and option text', function () {
	same(50, Calculator::roundsFromText('50'));
	same(50, Calculator::roundsFromText(' 50 '));
	same(1000, Calculator::roundsFromText('1,000'));
	same(500, Calculator::roundsFromText('500 Rounds'));
	same(20, Calculator::roundsFromText('Box of 20'));
	same(null, Calculator::roundsFromText('0'));
	same(null, Calculator::roundsFromText('250000'));
	same(null, Calculator::roundsFromText('Large'));
	same(null, Calculator::roundsFromText(''));
	same(null, Calculator::roundsFromText(['50']));
});

test('ammunition or not', function () {
	same(true, Calculator::isAmmoName('Federal American Eagle 9mm Luger 115gr FMJ 50 Rounds'));
	same(true, Calculator::isAmmoName('CCI Mini-Mag 22 LR 40gr Copper Plated Round Nose 100 Round Box'));
	same(true, Calculator::isAmmoName('Federal Top Gun 12 GA 2-3/4&quot; 1 1/8 oz #8 25 Shells'));
	same(true, Calculator::isAmmoName('Remington 12 Gauge 2-3/4" 00 Buck 5 Rounds'));
	same(false, Calculator::isAmmoName('Glock 19 Gen 5 9mm 4.02&quot; 15rd Pistol'));
	same(false, Calculator::isAmmoName('Glock 17 Magazine 9mm 17rd'));
	same(false, Calculator::isAmmoName('Mossberg 590A1 12 GA 20" 8rd Pump Shotgun'));
	same(false, Calculator::isAmmoName('Glock Logo T-Shirt Black XL'));
});

test('cost per round and option totals', function () {
	same(0.3798, round(Calculator::costPerRound(18.99, 50), 4));
	same(null, Calculator::costPerRound(0, 50));
	same(null, Calculator::costPerRound(18.99, 0));
	same(null, Calculator::costPerRound('abc', 50));
	same(167.99, Calculator::optionTotal(17.99, '+', 150));
	same(12.99, round(Calculator::optionTotal(17.99, '-', 5), 2));
});

test('firearm specs', function () {
	$specs = Calculator::firearmSpecs('Glock 19 Gen 5 9mm 4.02&quot; 15rd Pistol');
	same('9×19mm Parabellum', $specs['caliber']['name']);
	same('https://gunsnprices.com/ammo/handgun-ammo/9-19mm-parabellum', $specs['caliber']['url']);
	same(15, $specs['capacity']);
	same(4.02, $specs['barrel']);
	$specs = Calculator::firearmSpecs('Mossberg 590A1 12 GA 20" 8rd Pump Shotgun');
	same('12 Gauge', $specs['caliber']['name']);
	same(20.0, $specs['barrel']);
	same(['caliber' => null, 'capacity' => null, 'barrel' => null], Calculator::firearmSpecs('Glock Logo T-Shirt'));
	$d = Calculator::describe('Hornady Critical Defense 9mm 115gr FTX 25/Box');
	same(true, $d['ammo']);
	same(25, $d['rounds']);
	same(null, $d['capacity']);
});

test('number formatting helpers', function () {
	same('4.02', Calculator::formatLength(4.02));
	same('16', Calculator::formatLength(16.0));
	same('18.5', Calculator::formatLength(18.5));
	same('18,5', Calculator::formatLength(18.5, ','));
	same('$0.385', Calculator::replaceNumber('$0.00', '0.00', '0.385'));
	same('0,385 €', Calculator::replaceNumber('0,00 €', '0,00', '0,385'));
	same('¥0.4', Calculator::replaceNumber('¥0', '0', '0.4'));
});

test('warnings the parser hides with @ never reach an error handler that ignores @ (OpenCart 4.1.0.0)', function () use ($root) {
	// A fresh process, so the caliber patterns are compiled here and not reused from the tests above.
	$code = 'set_error_handler(function ($no, $str, $file, $line) { echo "HANDLER: $str\n"; return true; });'
		. 'require ' . var_export($root . '/shared/autoload.php', true) . ';'
		. '$s = GunsNPricesCpr\Calculator::firearmSpecs("Glock 19 Gen 5 9mm 4.02 in 15rd Pistol");'
		. 'echo $s["caliber"]["name"], "|", $s["capacity"], "|", $s["barrel"], "\n";'
		. 'echo $undefined_variable_on_purpose ?? "", "\n";'
		. 'echo @$undefined_variable_on_purpose2, "\n";'
		. 'trigger_error("not from the parser", E_USER_WARNING);';
	exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $out, $status);
	$out = implode("\n", $out);
	same(0, $status);
	contains('9×19mm Parabellum|15|4.02', $out);
	not_contains('too large', $out, 'parser warning hidden');
	contains('HANDLER: not from the parser', $out, 'other warnings still reach the store\'s handler');
});

// ---------------------------------------------------------------- Settings

test('settings defaults and normalization', function () {
	$d = Settings::defaults();
	same(0, $d['status']);
	same(0, $d['caliber_links'], 'caliber links off by default');
	same('name', $d['source']);
	$s = Settings::normalize(['status' => '1', 'format' => 'bogus', 'position' => 'below', 'option_id' => '12', 'ammo_category' => '[3,"4",0,-1,4]', 'specs_fields' => ['barrel', 'model', 'caliber'], 'unknown' => 'x']);
	same(1, $s['status']);
	same('short', $s['format'], 'unknown choice falls back');
	same('below', $s['position']);
	same(12, $s['option_id']);
	same([3, 4], $s['ammo_category']);
	same(['caliber', 'barrel'], $s['specs_fields'], 'fields keep display order, unknown dropped');
	same(false, array_key_exists('unknown', $s));
	same(1, Settings::normalize(['product' => '7'])['product'], 'toggles are 0 or 1');
});

test('settings from config and form', function () {
	$config = ['module_gunsnprices_cpr_status' => '1', 'module_gunsnprices_cpr_format' => 'long', 'module_gunsnprices_cpr_firearm_category' => '["5"]'];
	$s = Settings::fromConfig(function ($key) use ($config) {
		return isset($config[$key]) ? $config[$key] : null;
	});
	same(1, $s['status']);
	same('long', $s['format']);
	same([5], $s['firearm_category']);
	same('inline', $s['position'], 'missing keys get defaults');

	$post = ['module_gunsnprices_cpr_status' => '1', 'module_gunsnprices_cpr_ammo_mode' => 'category', 'module_gunsnprices_cpr_ammo_category' => ['33'], 'other' => 'x'];
	$s = Settings::fromPost($post);
	same([33], $s['ammo_category']);
	same([], $s['specs_fields'], 'unposted checkbox list means none');
	$c = Settings::toConfig($s);
	same(count(Settings::defaults()), count($c));
	same('category', $c['module_gunsnprices_cpr_ammo_mode']);
});

// ---------------------------------------------------------------- Service: lists

test('list: cost per round after the price, specials and tax', function () {
	$service = service([]);
	$service->remember([
		product_row(1, 'Federal American Eagle 9mm Luger 115gr FMJ 50 Rounds', 18.99),
		product_row(2, 'PMC Bronze 5.56x45mm 55gr FMJ 1000 Round Case', 449.99, 399.99),
		product_row(3, 'Hornady Critical Defense 9mm 115gr FTX 25/Box', 29.99, null, 9),
		product_row(4, 'Glock 19 Gen 5 9mm 4.02" 15rd Pistol', 549)
	]);
	$cards = [card(1), card(2, '$449.99', '$399.99'), card(3, '$35.99'), card(4, '$549.00')];
	$service->decorateCards($cards);
	contains('($0.38/rd)', $cards[0]['price']);
	same('$0.38/rd', $cards[0]['gnp_cost_per_round']);
	contains('($0.40/rd)', $cards[1]['special'], 'special price is used');
	same('$449.99', $cards[1]['price'], 'old price untouched');
	contains('($1.44/rd)', $cards[2]['price'], '29.99 + 20% tax = 35.99 / 25');
	same('$549.00', $cards[3]['price'], 'firearm untouched');
	same(false, isset($cards[3]['gnp_cost_per_round']));
});

test('list: formats, positions, decimals and currency', function () {
	$row = product_row(1, 'CCI Mini-Mag 22 LR 40gr Copper Plated Round Nose 100 Round Box', 11.49);

	$service = service(['format' => 'long', 'position' => 'below', 'decimals' => 'extra']);
	$service->remember($row);
	$c = card(1, '$11.49');
	$service->decorateCard($c);
	contains('display:block', $c['price']);
	contains('>$0.115 per round<', $c['price']);

	$service = service(['position' => 'none']);
	$service->remember($row);
	$c = card(1, '$11.49');
	$service->decorateCard($c);
	same('$11.49', $c['price'], 'position none leaves the price alone');
	same('$0.11/rd', $c['gnp_cost_per_round']);

	$service = service([], null, [], ['rate' => 0.9, 'left' => '', 'right' => ' €']);
	$service->remember($row);
	$c = card(1, '10.34 €');
	$service->decorateCard($c);
	contains('(0.10 €/rd)', $c['price'], 'converted to the shopper currency');
});

test('list: hidden prices, disabled extension, list setting off', function () {
	$row = product_row(1, 'Federal American Eagle 9mm Luger 115gr FMJ 50 Rounds', 18.99);

	$service = service([]);
	$service->remember($row);
	$c = card(1, false);
	$service->decorateCard($c);
	same(false, $c['price'], 'no price shown (login required) means no cost per round');

	$service = service(['status' => 0]);
	$service->remember($row);
	$c = card(1);
	$service->decorateCard($c);
	same('$18.99', $c['price']);

	$service = service(['list' => 0]);
	$service->remember($row);
	$c = card(1);
	$service->decorateCard($c);
	same('$18.99', $c['price']);
});

test('list: products not remembered are loaded once', function () {
	$calls = 0;
	$catalog = [7 => product_row(7, 'Federal American Eagle 9mm Luger 115gr FMJ 50 Rounds', 18.99)];
	$db = new FakeDb();
	$service = new Service(['status' => 1], new Repository($db, 'oc_', 1), [
		'tax'     => function ($a) {
			return $a;
		},
		'money'   => function ($a) {
			return '$' . number_format($a, 2);
		},
		'product' => function ($id) use ($catalog, &$calls) {
			$calls++;
			return isset($catalog[$id]) ? $catalog[$id] : false;
		}
	], []);
	$c = card(7);
	$service->decorateCard($c);
	$c2 = card(7);
	$service->decorateCard($c2);
	contains('($0.38/rd)', $c2['price']);
	same(1, $calls);
	$c3 = card(8);
	$service->decorateCard($c3);
	same('$18.99', $c3['price'], 'unknown product untouched');
});

test('remember ignores rows that are not products', function () {
	$service = service([]);
	$service->remember([product_row(1, 'Federal American Eagle 9mm Luger 115gr FMJ 50 Rounds', 18.99)]);
	// An option value row has product_id, price and name too, but no tax_class_id.
	$service->remember([['product_option_value_id' => 5, 'product_id' => 1, 'price' => '150', 'name' => '500 Rounds']]);
	$service->remember(['attribute_group_id' => 1, 'name' => 'Specs']);
	$service->remember('not an array');
	$c = card(1);
	$service->decorateCard($c);
	contains('($0.38/rd)', $c['price']);
});

// ---------------------------------------------------------------- Service: sources

test('attribute source, one query for a whole list', function () {
	$db = new FakeDb();
	$db->attributes = [1 => [12 => '50'], 2 => [12 => '1,000']];
	$service = service(['source' => 'attribute', 'attribute_id' => 12], $db);
	$service->remember([product_row(1, 'Magtech 9mm 124gr FMJ', 16.49), product_row(2, 'Tula 7.62x39 FMJ', 299.99), product_row(3, 'Federal American Eagle 9mm Luger 115gr FMJ 50 Rounds', 18.99)]);
	$cards = [card(1, '$16.49'), card(2, '$299.99'), card(3)];
	$service->decorateCards($cards);
	contains('($0.33/rd)', $cards[0]['price']);
	contains('($0.30/rd)', $cards[1]['price']);
	contains('($0.38/rd)', $cards[2]['price'], 'falls back to the name');
	same(1, count($db->queries));
});

test('option source: lowest cost per round in lists, per value on the product page', function () {
	$db = new FakeDb();
	$db->options = [5 => [[51, 9, '50 Rounds', '0.0000', '+', 1, 10], [52, 9, '500 Rounds', '150.0000', '+', 1, 10], [53, 9, '1000 Rounds', '300.0000', '+', 1, 0], [54, 9, 'Gift wrap', '2.0000', '+', 0, 0]]];
	$service = service(['source' => 'option', 'option_id' => 9], $db);
	$service->remember(product_row(5, 'Winchester USA 9mm 115 Grain FMJ', 17.99));
	$c = card(5, '$17.99');
	$service->decorateCard($c);
	contains('(from $0.34/rd)', $c['price'], 'out-of-stock 1000 Rounds is skipped');

	$data = [
		'product_id' => 5, 'price' => '$17.99', 'special' => false, 'description' => '', 'attribute_groups' => [],
		'options'    => [['option_id' => 9, 'product_option_value' => [['product_option_value_id' => 51, 'name' => '50 Rounds'], ['product_option_value_id' => 52, 'name' => '500 Rounds']]], ['option_id' => 3, 'product_option_value' => [['product_option_value_id' => 99, 'name' => 'Red']]]]
	];
	$service->decorateProductPage($data);
	contains('(from $0.34/rd)', $data['price']);
	same('50 Rounds – $0.36/rd', $data['options'][0]['product_option_value'][0]['name']);
	same('500 Rounds – $0.34/rd', $data['options'][0]['product_option_value'][1]['name']);
	same('Red', $data['options'][1]['product_option_value'][0]['name'], 'other options untouched');
});

test('category mode', function () {
	$db = new FakeDb();
	$db->categories = [1 => [33], 2 => [40]];
	$service = service(['ammo_mode' => 'category', 'ammo_category' => [33]], $db);
	$service->remember([product_row(1, 'Magtech 9mm 50rd', 16.49), product_row(2, 'Federal American Eagle 9mm Luger 115gr FMJ 50 Rounds', 18.99)]);
	$cards = [card(1, '$16.49'), card(2)];
	$service->decorateCards($cards);
	contains('($0.33/rd)', $cards[0]['price'], 'short name counts in an ammo category');
	same('$18.99', $cards[1]['price'], 'outside the categories nothing is added');
});

// ---------------------------------------------------------------- Service: product page and specs

test('product page: price and specs in the Specification tab', function () {
	$service = service([]);
	$service->remember(product_row(1, 'Federal American Eagle 9mm Luger 115gr FMJ 50 Rounds', 18.99));
	$service->remember(product_row(2, 'Glock 19 Gen 5 9mm 4.02" 15rd Pistol', 549));

	$data = ['product_id' => 1, 'price' => '$18.99', 'special' => false, 'attribute_groups' => [], 'description' => ''];
	$service->decorateProductPage($data);
	contains('($0.38/rd)', $data['price']);
	contains('font-size:.5em', $data['price']);
	same([], $data['attribute_groups'], 'no specs on ammunition');

	$data = ['product_id' => 2, 'price' => '$549.00', 'special' => false, 'attribute_groups' => [['name' => 'Other', 'attribute' => [['name' => 'caliber', 'text' => '9mm']]]], 'description' => '<p>x</p>'];
	$service->decorateProductPage($data);
	same('$549.00', $data['price']);
	same(2, count($data['attribute_groups']));
	same('Firearm specs', $data['attribute_groups'][1]['name']);
	$names = array_column($data['attribute_groups'][1]['attribute'], 'name');
	same(['Capacity', 'Barrel length'], $names, 'existing Caliber attribute is not repeated');
	same('15 rounds', $data['attribute_groups'][1]['attribute'][0]['text']);
	same('4.02 in', $data['attribute_groups'][1]['attribute'][1]['text']);
	not_contains('gunsnprices.com', json_encode($data), 'no links by default');
});

test('product page: specs at the top of the description, caliber links', function () {
	$service = service(['specs_position' => 'description', 'caliber_links' => 1, 'specs_fields' => ['caliber']]);
	$service->remember(product_row(2, 'Sig Sauer P365 XL 9mm 3.7" 12+1 Optic Ready', 699.99));
	$data = ['product_id' => 2, 'price' => '$699.99', 'special' => false, 'description' => '<p>Desc</p>'];
	$service->decorateProductPage($data);
	same(0, strpos($data['description'], '<table class="table table-bordered gnp-specs">'));
	contains('<a href="https://gunsnprices.com/ammo/handgun-ammo/9-19mm-parabellum" target="_blank" rel="noopener">9×19mm Parabellum</a>', $data['description']);
	not_contains('Capacity', $data['description'], 'only chosen fields');
	contains('<p>Desc</p>', $data['description']);
});

test('product page: specs off, firearm categories, non-gun products', function () {
	$service = service(['specs' => 0]);
	$service->remember(product_row(2, 'Glock 19 Gen 5 9mm 4.02" 15rd Pistol', 549));
	$data = ['product_id' => 2, 'price' => '$549.00'];
	$service->decorateProductPage($data);
	same(false, isset($data['attribute_groups']) && $data['attribute_groups']);

	$db = new FakeDb();
	$db->categories = [2 => [57]];
	$service = service(['firearm_mode' => 'category', 'firearm_category' => [57]], $db);
	$service->remember([product_row(2, 'Glock 19 Gen 5 9mm 4.02" 15rd Pistol', 549), product_row(3, 'Ruger 10/22 Carbine .22 LR 18.5" 10rd', 299)]);
	$data = ['product_id' => 3, 'price' => '$299.00', 'attribute_groups' => []];
	$service->decorateProductPage($data);
	same([], $data['attribute_groups'], 'not in a firearm category');

	$service = service([]);
	$service->remember(product_row(4, 'Glock Logo T-Shirt Black XL', 24.99));
	$data = ['product_id' => 4, 'price' => '$24.99', 'attribute_groups' => []];
	$service->decorateProductPage($data);
	same([], $data['attribute_groups'], 'no caliber, no specs');
});

test('product page: related products (OpenCart 3) and escaping', function () {
	$service = service([]);
	$service->remember([product_row(2, 'Glock 19 Gen 5 9mm 4.02" 15rd Pistol', 549), product_row(1, 'Federal American Eagle 9mm Luger 115gr FMJ 50 Rounds', 18.99)]);
	$data = ['product_id' => 2, 'price' => '$549.00', 'products' => [card(1)]];
	$service->decorateProductPage($data);
	contains('($0.38/rd)', $data['products'][0]['price']);
	foreach ($data['attribute_groups'][0]['attribute'] as $a) {
		same(false, strpos($a['text'], '<script') !== false);
	}
});

// ---------------------------------------------------------------- Packages

test('language files match between builds and cover the strings in use', function () use ($root) {
	$load = function ($file) {
		$_ = [];
		require $file;
		return $_;
	};
	$a4 = $load($root . '/oc4/admin/language/en-gb/module/gunsnprices_cpr.php');
	$a3 = $load($root . '/oc3/upload/admin/language/en-gb/extension/module/gunsnprices_cpr.php');
	same(array_keys($a4), array_keys($a3), 'admin keys');
	$c4 = $load($root . '/oc4/catalog/language/en-gb/module/gunsnprices_cpr.php');
	$c3 = $load($root . '/oc3/upload/catalog/language/en-gb/extension/module/gunsnprices_cpr.php');
	same($c4, $c3, 'catalog strings');
	foreach (Service::defaultLang() as $key => $value) {
		same($value, isset($c4['gnp_cpr_' . $key]) ? $c4['gnp_cpr_' . $key] : null, "catalog gnp_cpr_$key");
	}
	foreach (['oc4/admin/view/template/module/gunsnprices_cpr.twig' => $a4, 'oc3/upload/admin/view/template/extension/module/gunsnprices_cpr.twig' => $a3] as $twig => $strings) {
		preg_match_all('/\{\{ ((?:text|entry|help|button|error)_[a-z_]+) \}\}/', file_get_contents($root . '/' . $twig), $m);
		$core = ['button_save', 'button_back', 'button_cancel', 'text_enabled', 'text_disabled', 'text_yes', 'text_no', 'error_warning', 'error_attribute_id', 'error_option_id', 'error_ammo_category', 'error_firearm_category'];
		foreach (array_unique($m[1]) as $key) {
			if (!in_array($key, $core, true)) {
				same(true, isset($strings[$key]), "$twig uses $key");
			}
		}
	}
});

test('shipped PHP: syntax, PHP 7.4-compatible OpenCart 3 and shared code, no network calls', function () use ($root) {
	$files = [];
	foreach (['shared', 'oc3', 'oc4'] as $dir) {
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
		foreach ($it as $f) {
			if (substr($f->getFilename(), -4) === '.php') {
				$files[] = $f->getPathname();
			}
		}
	}
	same(true, count($files) >= 17, 'php files found');
	foreach ($files as $file) {
		exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
		same(0, $code, 'php -l ' . substr($file, strlen($root) + 1));
		$out = [];
		$code_text = file_get_contents($file);
		// Code without comments, for the syntax checks below.
		$bare = '';
		foreach (token_get_all($code_text) as $t) {
			$bare .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t;
		}
		$rel = substr($file, strlen($root) + 1);
		foreach (['curl_init', 'fsockopen', 'stream_socket_client', 'file_get_contents(\'http', 'file_get_contents("http', 'get_headers(', 'gethostbyname'] as $net) {
			same(false, strpos($code_text, $net) !== false, "$rel has no $net");
		}
		if (strpos($rel, 'oc4/') !== 0) {
			$bad = [];
			foreach (token_get_all($code_text) as $t) {
				if (is_array($t) && in_array(token_name($t[0]), ['T_MATCH', 'T_NULLSAFE_OBJECT_OPERATOR', 'T_ATTRIBUTE', 'T_ENUM', 'T_READONLY'], true)) {
					$bad[] = token_name($t[0]) . ' line ' . $t[2];
				}
			}
			same([], $bad, "$rel uses PHP 8-only syntax");
			same(0, preg_match('/\b(?:str_contains|str_starts_with|str_ends_with|array_is_list)\(/', $bare), "$rel uses PHP 8-only functions");
			same(0, preg_match('/\)\s*:\s*\??(?:mixed|static|never|object)\b|\bfn\s*\(|\?\?=/', $bare), "$rel uses PHP 8-only types or arrow functions");
		}
	}
});

test('built packages have the layout OpenCart expects', function () use ($root) {
	$oc4 = $root . '/dist/gunsnprices_cpr.ocmod.zip';
	$oc3 = $root . '/dist/gunsnprices_cpr_oc3.ocmod.zip';
	if (!is_file($oc4) || !is_file($oc3) || !class_exists('ZipArchive')) {
		throw new RuntimeException('run php tools/build.php first');
	}
	$names = function ($file) {
		$zip = new ZipArchive();
		$zip->open($file);
		$out = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$out[] = $zip->getNameIndex($i);
		}
		$zip->close();
		return $out;
	};
	$n4 = $names($oc4);
	foreach (['install.json', 'admin/controller/module/gunsnprices_cpr.php', 'catalog/controller/module/gunsnprices_cpr.php', 'system/library/gunsnprices_cpr/autoload.php', 'system/library/gunsnprices_cpr/vendor/gunsnprices/LICENSE', 'system/library/gunsnprices_cpr/vendor/gunsnprices/LICENSE-DATA', 'LICENSE.txt'] as $f) {
		same(true, in_array($f, $n4, true), "OC4 zip has $f");
	}
	$zip = new ZipArchive();
	$zip->open($oc4);
	$install = json_decode($zip->getFromName('install.json'), true);
	$zip->close();
	same('Ammo Cost per Round & Firearm Specs', $install['name']);
	same('GunsNPrices', $install['author']);
	$n3 = $names($oc3);
	foreach ($n3 as $f) {
		same(true, strpos($f, 'upload/') === 0 || in_array($f, ['LICENSE.txt', 'README.md', 'CHANGELOG.md'], true), "OC3 zip entry $f");
	}
	foreach (['upload/admin/controller/extension/module/gunsnprices_cpr.php', 'upload/catalog/controller/extension/module/gunsnprices_cpr.php', 'upload/system/library/gunsnprices_cpr/autoload.php'] as $f) {
		same(true, in_array($f, $n3, true), "OC3 zip has $f");
	}
	foreach (array_merge($n4, $n3) as $f) {
		same(false, (bool)preg_match('#(^|/)\.|__MACOSX|\.DS_Store#', $f), "no hidden file $f");
	}
});

echo $passed . ' assertions passed, ' . count($failed) . " failed (PHP " . PHP_VERSION . ")\n";
foreach ($failed as $f) {
	echo "FAIL $f\n";
}
exit($failed ? 1 : 0);
