<?php
/**
 * Ammo Cost per Round & Firearm Specs for OpenCart 4, by GunsNPrices (https://gunsnprices.com).
 * GPL-3.0-or-later. See LICENSE.
 */
namespace Opencart\Catalog\Controller\Extension\GunsnpricesCpr\Module;

/**
 * Event handlers on the store front. Registered by the admin controller on install:
 * - catalog/model/catalog/product/get*\/after  -> products(): remembers prices of the products being shown
 * - catalog/view/product/product/before       -> product(): product page
 * - catalog/view/product/thumb/before         -> thumb(): every product in lists and modules
 */
class GunsnpricesCpr extends \Opencart\System\Engine\Controller {
	/** Registry key of the shared Service of the current request. */
	private const KEY = 'gunsnprices_cpr_service';

	/**
	 * The extension has no layout module output.
	 *
	 * @return string
	 */
	public function index(): string {
		return '';
	}

	/**
	 * Event: catalog/model/catalog/product/get*\/after.
	 *
	 * @param string $route
	 * @param array  $args
	 * @param mixed  $output
	 *
	 * @return void
	 */
	public function products(&$route, &$args, &$output): void {
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
	 * @param mixed  $code
	 * @param mixed  $output
	 *
	 * @return void
	 */
	public function product(&$route, &$data, &$code = '', &$output = ''): void {
		$service = $this->service();

		if ($service && is_array($data)) {
			$service->decorateProductPage($data);
		}
	}

	/**
	 * Event: catalog/view/product/thumb/before.
	 *
	 * @param string $route
	 * @param array  $data
	 * @param mixed  $code
	 * @param mixed  $output
	 *
	 * @return void
	 */
	public function thumb(&$route, &$data, &$code = '', &$output = ''): void {
		$service = $this->service();

		if ($service && is_array($data)) {
			$service->decorateCard($data);
		}
	}

	/**
	 * The Service for this request, or null when the extension is turned off for the current store.
	 *
	 * @return \GunsNPricesCpr\Service|null
	 */
	private function service(): ?object {
		if (!$this->config->get('module_gunsnprices_cpr_status')) {
			return null;
		}

		if ($this->registry->has(self::KEY)) {
			return $this->registry->get(self::KEY);
		}

		require_once DIR_EXTENSION . 'gunsnprices_cpr/system/library/gunsnprices_cpr/autoload.php';

		$settings = \GunsNPricesCpr\Settings::fromConfig([$this->config, 'get']);

		$this->load->language('extension/gunsnprices_cpr/module/gunsnprices_cpr');

		$lang = [];

		foreach (array_keys(\GunsNPricesCpr\Service::defaultLang()) as $key) {
			$value = $this->language->get('gnp_cpr_' . $key);

			if ($value !== 'gnp_cpr_' . $key) {
				$lang[$key] = $value;
			}
		}

		$currency = $this->session->data['currency'] ?? $this->config->get('config_currency');
		$decimal_point = $this->language->get('decimal_point');
		$thousand_point = $this->language->get('thousand_point');

		$env = [
			// The price as the store shows it: with tax when "Display Prices With Tax" is on.
			'tax'           => function ($amount, $tax_class_id): float {
				return (float)$this->tax->calculate((float)$amount, (int)$tax_class_id, (bool)$this->config->get('config_tax'));
			},
			// An amount in the default currency, converted and formatted in the shopper's currency.
			'money'         => function ($amount, $extra) use ($currency, $decimal_point, $thousand_point): string {
				if (!$extra) {
					return $this->currency->format((float)$amount, $currency);
				}

				$places = $this->currency->getDecimalPlace($currency) + 1;
				$number = number_format(round((float)$amount * $this->currency->getValue($currency), $places), $places, $decimal_point, $thousand_point);
				$zero = number_format(0, $places - 1, $decimal_point, $thousand_point);

				return \GunsNPricesCpr\Calculator::replaceNumber($this->currency->format(0, $currency, 1), $zero, $number);
			},
			'product'       => function ($product_id): array {
				$this->load->model('catalog/product');

				return $this->model_catalog_product->getProduct((int)$product_id);
			},
			'decimal_point' => $decimal_point
		];

		$service = new \GunsNPricesCpr\Service($settings, new \GunsNPricesCpr\Repository($this->db, DB_PREFIX, (int)$this->config->get('config_language_id')), $env, $lang);

		$this->registry->set(self::KEY, $service);

		return $service;
	}
}
