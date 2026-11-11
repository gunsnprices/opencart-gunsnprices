<?php
/**
 * Ammo Cost per Round & Firearm Specs for OpenCart 3.0, by GunsNPrices (https://gunsnprices.com).
 * GPL-3.0-or-later. See LICENSE.
 */

/**
 * Settings page, "Try a product name" box, and event registration on install.
 */
class ControllerExtensionModuleGunsnpricesCpr extends Controller {
	/** @var array<string, string> */
	private $error = array();

	/**
	 * Settings page; saves on POST.
	 *
	 * @return void
	 */
	public function index() {
		$this->library();
		$this->load->language('extension/module/gunsnprices_cpr');

		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('setting/setting');

		$token = 'user_token=' . $this->session->data['user_token'];
		$store_id = isset($this->request->get['store_id']) ? (int)$this->request->get['store_id'] : 0;
		$posted = null;

		if ($this->request->server['REQUEST_METHOD'] == 'POST') {
			$posted = \GunsNPricesCpr\Settings::fromPost($this->request->post);

			if ($this->validate($posted)) {
				$this->model_setting_setting->editSetting(\GunsNPricesCpr\Settings::CODE, \GunsNPricesCpr\Settings::toConfig($posted), $store_id);

				$this->session->data['success'] = $this->language->get('text_success');

				$this->response->redirect($this->url->link('extension/module/gunsnprices_cpr', $token . '&store_id=' . $store_id, true));
			}
		}

		$data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';

		foreach (array('attribute_id', 'option_id', 'ammo_category', 'firearm_category') as $key) {
			$data['error_' . $key] = isset($this->error[$key]) ? $this->error[$key] : '';
		}

		if (isset($this->session->data['success'])) {
			$data['success'] = $this->session->data['success'];

			unset($this->session->data['success']);
		} else {
			$data['success'] = '';
		}

		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', $token, true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', $token . '&type=module', true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/module/gunsnprices_cpr', $token, true)
		);

		$data['action'] = $this->url->link('extension/module/gunsnprices_cpr', $token . '&store_id=' . $store_id, true);
		$data['reset'] = $this->url->link('extension/module/gunsnprices_cpr/reset', $token . '&store_id=' . $store_id, true);
		$data['test'] = str_replace('&amp;', '&', $this->url->link('extension/module/gunsnprices_cpr/test', $token, true));
		$data['cancel'] = $this->url->link('marketplace/extension', $token . '&type=module', true);

		// Stores
		$this->load->model('setting/store');

		$data['stores'] = array();

		$data['stores'][] = array(
			'store_id' => 0,
			'name'     => $this->language->get('text_default'),
			'href'     => str_replace('&amp;', '&', $this->url->link('extension/module/gunsnprices_cpr', $token . '&store_id=0', true))
		);

		foreach ($this->model_setting_store->getStores() as $store) {
			$data['stores'][] = array(
				'store_id' => (int)$store['store_id'],
				'name'     => $store['name'],
				'href'     => str_replace('&amp;', '&', $this->url->link('extension/module/gunsnprices_cpr', $token . '&store_id=' . (int)$store['store_id'], true))
			);
		}

		$data['store_id'] = $store_id;

		// Settings: a store without its own settings uses the default store's.
		$own = $this->model_setting_setting->getSetting(\GunsNPricesCpr\Settings::CODE, $store_id);
		$data['store_own'] = $store_id === 0 || (bool)$own;

		if (!$own && $store_id) {
			$own = $this->model_setting_setting->getSetting(\GunsNPricesCpr\Settings::CODE, 0);
		}

		if ($posted !== null) {
			$settings = $posted;
		} else {
			$settings = \GunsNPricesCpr\Settings::fromConfig(function ($key) use ($own) {
				return isset($own[$key]) ? $own[$key] : null;
			});
		}

		foreach ($settings as $key => $value) {
			$data[\GunsNPricesCpr\Settings::CODE . '_' . $key] = $value;
		}

		// Choices
		$this->load->model('catalog/attribute');

		$data['attributes'] = array();

		foreach ($this->model_catalog_attribute->getAttributes() as $attribute) {
			$data['attributes'][] = array(
				'attribute_id' => (int)$attribute['attribute_id'],
				'name'         => ($attribute['attribute_group'] ? $attribute['attribute_group'] . ' &gt; ' : '') . $attribute['name']
			);
		}

		$this->load->model('catalog/option');

		$data['options'] = array();

		foreach ($this->model_catalog_option->getOptions() as $option) {
			if (in_array($option['type'], array('select', 'radio', 'checkbox'), true)) {
				$data['options'][] = array(
					'option_id' => (int)$option['option_id'],
					'name'      => $option['name']
				);
			}
		}

		$this->load->model('catalog/category');

		$data['categories'] = array();

		foreach ($this->model_catalog_category->getCategories(array('sort' => 'name')) as $category) {
			$data['categories'][] = array(
				'category_id' => (int)$category['category_id'],
				'name'        => $category['name']
			);
		}

		$data['spec_fields'] = array();

		foreach (\GunsNPricesCpr\Settings::specFields() as $field) {
			$data['spec_fields'][] = array(
				'value' => $field,
				'text'  => $this->language->get('text_field_' . $field)
			);
		}

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/module/gunsnprices_cpr', $data));
	}

	/**
	 * Deletes a store's own settings, so it uses the default store's settings again.
	 *
	 * @return void
	 */
	public function reset() {
		$this->library();
		$this->load->language('extension/module/gunsnprices_cpr');

		$store_id = isset($this->request->get['store_id']) ? (int)$this->request->get['store_id'] : 0;

		if ($store_id && $this->user->hasPermission('modify', 'extension/module/gunsnprices_cpr')) {
			$this->load->model('setting/setting');

			$this->model_setting_setting->deleteSetting(\GunsNPricesCpr\Settings::CODE, $store_id);

			$this->session->data['success'] = $this->language->get('text_reset');
		}

		$this->response->redirect($this->url->link('extension/module/gunsnprices_cpr', 'user_token=' . $this->session->data['user_token'] . '&store_id=' . $store_id, true));
	}

	/**
	 * "Try a product name": what the extension reads from a name, as an HTML table.
	 *
	 * @return void
	 */
	public function test() {
		$this->library();
		$this->load->language('extension/module/gunsnprices_cpr');

		$json = array();

		if (!$this->user->hasPermission('access', 'extension/module/gunsnprices_cpr')) {
			$json['error'] = $this->language->get('error_permission');
		}

		$name = isset($this->request->post['name']) ? trim((string)$this->request->post['name']) : '';
		$price = isset($this->request->post['price']) ? (float)str_replace(',', '.', (string)$this->request->post['price']) : 0.0;

		if (!$json && $name === '') {
			$json['error'] = $this->language->get('error_test_name');
		}

		if (!$json) {
			$result = \GunsNPricesCpr\Calculator::describe($name);
			$rows = array();

			$rows[] = array($this->language->get('text_test_type'), $this->language->get($result['ammo'] ? 'text_test_ammo' : 'text_test_firearm'));

			if ($result['ammo']) {
				$rows[] = array($this->language->get('text_test_rounds'), $result['rounds'] ? (string)$result['rounds'] : '-');

				if ($result['rounds'] && $price > 0) {
					$cost = (float)\GunsNPricesCpr\Calculator::costPerRound($price, $result['rounds']);
					$rows[] = array($this->language->get('text_test_cost'), $this->currency->format($cost, $this->config->get('config_currency'), 1));
				}
			}

			$rows[] = array($this->language->get('text_field_caliber'), $result['caliber'] ? $result['caliber']['name'] : '-');

			if (!$result['ammo']) {
				$rows[] = array($this->language->get('text_field_capacity'), $result['capacity'] ? (string)$result['capacity'] : '-');
				$rows[] = array($this->language->get('text_field_barrel'), $result['barrel'] ? \GunsNPricesCpr\Calculator::formatLength($result['barrel']) . ' in' : '-');
			}

			$html = '<table class="table table-bordered table-condensed" style="margin-bottom: 0;"><tbody>';

			foreach ($rows as $row) {
				$html .= '<tr><th style="width: 25%;">' . htmlspecialchars($row[0], ENT_QUOTES, 'UTF-8') . '</th><td>' . htmlspecialchars($row[1], ENT_QUOTES, 'UTF-8') . '</td></tr>';
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
	public function install() {
		$this->library();

		$this->load->model('setting/event');

		foreach ($this->events() as $code => $event) {
			$this->model_setting_event->deleteEventByCode($code);
			$this->model_setting_event->addEvent($code, $event[0], $event[1], 1, 0);
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
	public function uninstall() {
		$this->load->model('setting/event');

		foreach (array_keys($this->events()) as $code) {
			$this->model_setting_event->deleteEventByCode($code);
		}
	}

	/**
	 * The events this extension uses: code => [trigger, action].
	 *
	 * @return array<string, string[]>
	 */
	private function events() {
		$action = 'extension/module/gunsnprices_cpr/';

		$events = array(
			'gunsnprices_cpr_products' => array('catalog/model/catalog/product/get*/after', $action . 'products'),
			'gunsnprices_cpr_product'  => array('catalog/view/product/product/before', $action . 'product')
		);

		// Templates that list products. Related products are part of product/product.
		$lists = array(
			'category'         => 'product/category',
			'search'           => 'product/search',
			'special'          => 'product/special',
			'manufacturer'     => 'product/manufacturer_info',
			'module_featured'  => 'extension/module/featured',
			'module_latest'    => 'extension/module/latest',
			'module_best'      => 'extension/module/bestseller',
			'module_special'   => 'extension/module/special'
		);

		foreach ($lists as $code => $route) {
			$events['gunsnprices_cpr_' . $code] = array('catalog/view/' . $route . '/before', $action . 'listing');
		}

		return $events;
	}

	/**
	 * Checks permission and choices that need a second setting.
	 *
	 * @param array<string, mixed> $settings
	 * @return bool
	 */
	private function validate(array $settings) {
		if (!$this->user->hasPermission('modify', 'extension/module/gunsnprices_cpr')) {
			$this->error['warning'] = $this->language->get('error_permission');

			return false;
		}

		if ($settings['source'] === 'attribute' && !$settings['attribute_id']) {
			$this->error['attribute_id'] = $this->language->get('error_attribute');
		}

		if ($settings['source'] === 'option' && !$settings['option_id']) {
			$this->error['option_id'] = $this->language->get('error_option');
		}

		if ($settings['ammo_mode'] === 'category' && !$settings['ammo_category']) {
			$this->error['ammo_category'] = $this->language->get('error_category');
		}

		if ($settings['firearm_mode'] === 'category' && !$settings['firearm_category']) {
			$this->error['firearm_category'] = $this->language->get('error_category');
		}

		if ($this->error) {
			$this->error['warning'] = $this->language->get('error_warning');
		}

		return !$this->error;
	}

	/**
	 * Loads the extension's classes and the bundled parser.
	 *
	 * @return void
	 */
	private function library() {
		require_once DIR_SYSTEM . 'library/gunsnprices_cpr/autoload.php';
	}
}
