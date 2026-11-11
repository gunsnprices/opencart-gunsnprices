<?php
/**
 * Ammo Cost per Round & Firearm Specs for OpenCart 3.0, by GunsNPrices (https://gunsnprices.com).
 * GPL-3.0-or-later. See LICENSE.
 */

/**
 * Event handlers on the store front. Registered by the admin controller on install:
 * - catalog/model/catalog/product/get*\/after -> products(): remembers prices of the products being shown
 * - catalog/view/product/product/before      -> product(): product page and its related products
 * - catalog/view/product/category/before and the other list templates -> listing()
 *
 * OpenCart 3 stops calling event handlers after one returns a value, so none of these return anything.
 */
class ControllerExtensionModuleGunsnpricesCpr extends Controller {
	/** Registry key of the shared Service of the current request. */
	const KEY = 'gunsnprices_cpr_service';

	/**
	 * The extension has no layout module output.
	 *
	 * @return void
	 */
	public function index() {
	}

	/**
	 * Event: catalog/model/catalog/product/get*\/after.
	 *
	 * @param string $route
	 * @param array  $args
	 * @param mixed  $output
	 * @return void
	 */
	public function products(&$route, &$args, &$output) {
		$service = $this->service();

		if ($service) {
			$service->remember($output);
		}
	}

	/**
	 * Event: catalog/view/product/product/before.
	 *
	 * @param string $route
	 * @param array  $data
	 * @return void
	 */
	public function product(&$route, &$data) {
		$service = $this->service();

		if ($service && is_array($data)) {
			$service->decorateProductPage($data);
		}
	}

	/**
	 * Event: catalog/view/<list template>/before.
	 *
	 * @param string $route
	 * @param array  $data
	 * @return void
	 */
	public function listing(&$route, &$data) {
		$service = $this->service();

		if ($service && is_array($data) && !empty($data['products']) && is_array($data['products'])) {
			$service->decorateCards($data['products']);
		}
	}

	/**
	 * The Service for this request, or null when the extension is turned off for the current store.
	 *
	 * @return \GunsNPricesCpr\Service|null
	 */
	private function service() {
		if (!$this->config->get('module_gunsnprices_cpr_status')) {
			return null;
		}

		if ($this->registry->has(self::KEY)) {
			return $this->registry->get(self::KEY);
		}

		require_once DIR_SYSTEM . 'library/gunsnprices_cpr/autoload.php';

		$settings = \GunsNPricesCpr\Settings::fromConfig(array($this->config, 'get'));

		$this->load->language('extension/module/gunsnprices_cpr');

		$lang = array();

		foreach (array_keys(\GunsNPricesCpr\Service::defaultLang()) as $key) {
			$value = $this->language->get('gnp_cpr_' . $key);

			if ($value !== 'gnp_cpr_' . $key) {
				$lang[$key] = $value;
			}
		}

		$currency = isset($this->session->data['currency']) ? $this->session->data['currency'] : $this->config->get('config_currency');
		$decimal_point = $this->language->get('decimal_point');
		$thousand_point = $this->language->get('thousand_point');
		$registry = $this->registry;

		$env = array(
			// The price as the store shows it: with tax when "Display Prices With Tax" is on.
			'tax'           => function ($amount, $tax_class_id) use ($registry) {
				return (float)$registry->get('tax')->calculate((float)$amount, (int)$tax_class_id, $registry->get('config')->get('config_tax'));
			},
			// An amount in the default currency, converted and formatted in the shopper's currency.
			'money'         => function ($amount, $extra) use ($registry, $currency, $decimal_point, $thousand_point) {
				$formatter = $registry->get('currency');

				if (!$extra) {
					return $formatter->format((float)$amount, $currency);
				}

				$places = (int)$formatter->getDecimalPlace($currency) + 1;
				$number = number_format(round((float)$amount * $formatter->getValue($currency), $places), $places, $decimal_point, $thousand_point);
				$zero = number_format(0, $places - 1, $decimal_point, $thousand_point);

				return \GunsNPricesCpr\Calculator::replaceNumber($formatter->format(0, $currency, 1), $zero, $number);
			},
			'product'       => function ($product_id) use ($registry) {
				$registry->get('load')->model('catalog/product');

				return $registry->get('model_catalog_product')->getProduct((int)$product_id);
			},
			'decimal_point' => $decimal_point
		);

		$service = new \GunsNPricesCpr\Service($settings, new \GunsNPricesCpr\Repository($this->db, DB_PREFIX, (int)$this->config->get('config_language_id')), $env, $lang);

		$this->registry->set(self::KEY, $service);

		return $service;
	}
}
