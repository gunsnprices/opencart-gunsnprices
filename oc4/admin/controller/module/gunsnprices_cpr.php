<?php
/**
 * Ammo Cost per Round & Firearm Specs for OpenCart 4, by GunsNPrices (https://gunsnprices.com).
 * GPL-3.0-or-later. See LICENSE.
 */
namespace Opencart\Admin\Controller\Extension\GunsnpricesCpr\Module;

/**
 * Settings page, "Try a product name" box, and event registration on install.
 */
class GunsnpricesCpr extends \Opencart\System\Engine\Controller {
	/** Route of this controller without the method. */
	private const ROUTE = 'extension/gunsnprices_cpr/module/gunsnprices_cpr';

	/**
	 * Settings page.
	 *
	 * @return void
	 */
	public function index(): void {
		$this->library();
		$this->load->language(self::ROUTE);

		$this->document->setTitle($this->language->get('heading_title'));

		$token = 'user_token=' . $this->session->data['user_token'];
		$store_id = isset($this->request->get['store_id']) ? (int)$this->request->get['store_id'] : 0;

		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', $token)
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', $token . '&type=module')
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link(self::ROUTE, $token)
		];

		$data['save'] = $this->url->link(self::ROUTE . $this->separator() . 'save', $token . '&store_id=' . $store_id);
		$data['reset'] = $this->url->link(self::ROUTE . $this->separator() . 'reset', $token . '&store_id=' . $store_id);
		$data['test'] = $this->url->link(self::ROUTE . $this->separator() . 'test', $token);
		$data['back'] = $this->url->link('marketplace/extension', $token . '&type=module');

		// Stores
		$this->load->model('setting/store');

		$data['stores'] = [];

		$data['stores'][] = [
			'store_id' => 0,
			'name'     => $this->language->get('text_default'),
			'href'     => $this->url->link(self::ROUTE, $token . '&store_id=0')
		];

		foreach ($this->model_setting_store->getStores() as $store) {
			$data['stores'][] = [
				'store_id' => (int)$store['store_id'],
				'name'     => $store['name'],
				'href'     => $this->url->link(self::ROUTE, $token . '&store_id=' . (int)$store['store_id'])
			];
		}

		$data['store_id'] = $store_id;

		// Settings: a store without its own settings uses the default store's.
		$this->load->model('setting/setting');

		$own = $this->model_setting_setting->getSetting(\GunsNPricesCpr\Settings::CODE, $store_id);
		$data['store_own'] = $store_id === 0 || (bool)$own;

		if (!$own && $store_id) {
			$own = $this->model_setting_setting->getSetting(\GunsNPricesCpr\Settings::CODE, 0);
		}

		$settings = \GunsNPricesCpr\Settings::fromConfig(function (string $key) use ($own) {
			return $own[$key] ?? null;
		});

		foreach ($settings as $key => $value) {
			$data[\GunsNPricesCpr\Settings::CODE . '_' . $key] = $value;
		}

		// Choices
		$this->load->model('catalog/attribute');

		$data['attributes'] = [];

		foreach ($this->model_catalog_attribute->getAttributes() as $attribute) {
			$data['attributes'][] = [
				'attribute_id' => (int)$attribute['attribute_id'],
				'name'         => ($attribute['attribute_group'] ? $attribute['attribute_group'] . ' > ' : '') . $attribute['name']
			];
		}

		$this->load->model('catalog/option');

		$data['options'] = [];

		foreach ($this->model_catalog_option->getOptions() as $option) {
			if (in_array($option['type'], ['select', 'radio', 'checkbox'], true)) {
				$data['options'][] = [
					'option_id' => (int)$option['option_id'],
					'name'      => $option['name']
				];
			}
		}

		$this->load->model('catalog/category');

		$data['categories'] = [];

		foreach ($this->model_catalog_category->getCategories() as $category) {
			$data['categories'][] = [
				'category_id' => (int)$category['category_id'],
				'name'        => $category['name']
			];
		}

		usort($data['categories'], function (array $a, array $b): int {
			return strcmp($a['name'], $b['name']);
		});

		$data['spec_fields'] = [];

		foreach (\GunsNPricesCpr\Settings::specFields() as $field) {
			$data['spec_fields'][] = [
				'value' => $field,
				'text'  => $this->language->get('text_field_' . $field)
			];
		}

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view(self::ROUTE, $data));
	}

	/**
	 * Saves the settings of one store.
	 *
	 * @return void
	 */
	public function save(): void {
		$this->library();
		$this->load->language(self::ROUTE);

		$json = [];

		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}

		$store_id = isset($this->request->get['store_id']) ? (int)$this->request->get['store_id'] : 0;

		$settings = \GunsNPricesCpr\Settings::fromPost($this->request->post);

		if (!$json) {
			foreach ($this->validate($settings) as $key => $error) {
				$json['error'][$key] = $error;
			}

			if (isset($json['error'])) {
				$json['error']['warning'] = $this->language->get('error_warning');
			}
		}

		if (!$json) {
			$this->load->model('setting/setting');

			$this->model_setting_setting->editSetting(\GunsNPricesCpr\Settings::CODE, \GunsNPricesCpr\Settings::toConfig($settings), $store_id);

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Deletes a store's own settings, so it uses the default store's settings again.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->library();
		$this->load->language(self::ROUTE);

		$json = [];

		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error'] = $this->language->get('error_permission');
		}

		$store_id = isset($this->request->get['store_id']) ? (int)$this->request->get['store_id'] : 0;

		if (!$json && !$store_id) {
			$json['error'] = $this->language->get('error_reset_default');
		}

		if (!$json) {
			$this->load->model('setting/setting');

			$this->model_setting_setting->deleteSetting(\GunsNPricesCpr\Settings::CODE, $store_id);

			$json['success'] = $this->language->get('text_reset');
			$json['redirect'] = str_replace('&amp;', '&', $this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token'] . '&store_id=' . $store_id));
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * "Try a product name": what the extension reads from a name, as an HTML table.
	 *
	 * @return void
	 */
	public function test(): void {
		$this->library();
		$this->load->language(self::ROUTE);

		$json = [];

		if (!$this->user->hasPermission('access', self::ROUTE)) {
			$json['error'] = $this->language->get('error_permission');
		}

		$name = isset($this->request->post['name']) ? trim((string)$this->request->post['name']) : '';
		$price = isset($this->request->post['price']) ? (float)str_replace(',', '.', (string)$this->request->post['price']) : 0.0;

		if (!$json && $name === '') {
			$json['error'] = $this->language->get('error_test_name');
		}

		if (!$json) {
			$result = \GunsNPricesCpr\Calculator::describe($name);
			$rows = [];

			$rows[] = [$this->language->get('text_test_type'), $this->language->get($result['ammo'] ? 'text_test_ammo' : 'text_test_firearm')];

			if ($result['ammo']) {
				$rows[] = [$this->language->get('text_test_rounds'), $result['rounds'] ? (string)$result['rounds'] : '-'];

				if ($result['rounds'] && $price > 0) {
					$cost = (float)\GunsNPricesCpr\Calculator::costPerRound($price, $result['rounds']);
					$rows[] = [$this->language->get('text_test_cost'), $this->currency->format($cost, $this->config->get('config_currency'), 1)];
				}
			}

			$rows[] = [$this->language->get('text_field_caliber'), $result['caliber'] ? $result['caliber']['name'] : '-'];

			if (!$result['ammo']) {
				$rows[] = [$this->language->get('text_field_capacity'), $result['capacity'] ? (string)$result['capacity'] : '-'];
				$rows[] = [$this->language->get('text_field_barrel'), $result['barrel'] ? \GunsNPricesCpr\Calculator::formatLength($result['barrel']) . ' in' : '-'];
			}

			$html = '<table class="table table-bordered table-sm mb-0"><tbody>';

			foreach ($rows as $row) {
				$html .= '<tr><th class="w-25">' . htmlspecialchars($row[0], ENT_QUOTES, 'UTF-8') . '</th><td>' . htmlspecialchars($row[1], ENT_QUOTES, 'UTF-8') . '</td></tr>';
			}

			$html .= '</tbody></table>';

			$json['html'] = $html;
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Called by Extensions > Extensions > Modules > Install: registers the events and saves the default settings.
	 *
	 * @return void
	 */
	public function install(): void {
		if (!$this->user->hasPermission('modify', 'extension/module')) {
			return;
		}

		$this->library();

		$this->load->model('setting/event');

		foreach ($this->events() as $event) {
			$this->model_setting_event->deleteEventByCode($event['code']);
			$this->model_setting_event->addEvent($event);
		}

		$this->load->model('setting/setting');

		if (!$this->model_setting_setting->getSetting(\GunsNPricesCpr\Settings::CODE, 0)) {
			$this->model_setting_setting->editSetting(\GunsNPricesCpr\Settings::CODE, \GunsNPricesCpr\Settings::toConfig(\GunsNPricesCpr\Settings::defaults()), 0);
		}
	}

	/**
	 * Called by Extensions > Extensions > Modules > Uninstall. OpenCart deletes the settings itself.
	 *
	 * @return void
	 */
	public function uninstall(): void {
		if (!$this->user->hasPermission('modify', 'extension/module')) {
			return;
		}

		$this->load->model('setting/event');

		foreach ($this->events() as $event) {
			$this->model_setting_event->deleteEventByCode($event['code']);
		}
	}

	/**
	 * The events this extension uses.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function events(): array {
		$action = 'extension/gunsnprices_cpr/module/gunsnprices_cpr' . $this->separator();

		return [
			[
				'code'        => 'gunsnprices_cpr_products',
				'description' => 'Ammo Cost per Round & Firearm Specs (GunsNPrices): reads product prices from the catalog/product model.',
				'trigger'     => 'catalog/model/catalog/product/get*/after',
				'action'      => $action . 'products',
				'status'      => 1,
				'sort_order'  => 0
			],
			[
				'code'        => 'gunsnprices_cpr_product',
				'description' => 'Ammo Cost per Round & Firearm Specs (GunsNPrices): cost per round and specs on product pages.',
				'trigger'     => 'catalog/view/product/product/before',
				'action'      => $action . 'product',
				'status'      => 1,
				'sort_order'  => 0
			],
			[
				'code'        => 'gunsnprices_cpr_thumb',
				'description' => 'Ammo Cost per Round & Firearm Specs (GunsNPrices): cost per round in product lists.',
				'trigger'     => 'catalog/view/product/thumb/before',
				'action'      => $action . 'thumb',
				'status'      => 1,
				'sort_order'  => 0
			]
		];
	}

	/**
	 * Checks choices that need a second setting.
	 *
	 * @param array<string, mixed> $settings
	 * @return array<string, string> input name suffix => error
	 */
	private function validate(array $settings): array {
		$errors = [];

		if ($settings['source'] === 'attribute' && !$settings['attribute_id']) {
			$errors['attribute_id'] = $this->language->get('error_attribute');
		}

		if ($settings['source'] === 'option' && !$settings['option_id']) {
			$errors['option_id'] = $this->language->get('error_option');
		}

		if ($settings['ammo_mode'] === 'category' && !$settings['ammo_category']) {
			$errors['ammo_category'] = $this->language->get('error_category');
		}

		if ($settings['firearm_mode'] === 'category' && !$settings['firearm_category']) {
			$errors['firearm_category'] = $this->language->get('error_category');
		}

		return $errors;
	}

	/**
	 * The method separator in routes: "." since OpenCart 4.0.2.0, "|" before.
	 *
	 * @return string
	 */
	private function separator(): string {
		return version_compare(VERSION, '4.0.2.0', '>=') ? '.' : '|';
	}

	/**
	 * Loads the extension's classes and the bundled parser.
	 *
	 * @return void
	 */
	private function library(): void {
		require_once DIR_EXTENSION . 'gunsnprices_cpr/system/library/gunsnprices_cpr/autoload.php';
	}
}
