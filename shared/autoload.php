<?php
/**
 * Ammo Cost per Round & Firearm Specs for OpenCart, by GunsNPrices (https://gunsnprices.com).
 * GPL-3.0-or-later. See LICENSE.
 *
 * Loads the extension's classes and the bundled gunsnprices parser (MIT, see vendor/gunsnprices/LICENSE).
 * OpenCart installs extensions without Composer, so the files are required directly.
 */

if (!class_exists('GunsNPricesCpr\Service', false)) {
	$gnp_cpr_dir = __DIR__;

	foreach (['Text', 'Rx', 'Data', 'Calibers', 'Specs', 'Models'] as $gnp_cpr_class) {
		require_once $gnp_cpr_dir . '/vendor/gunsnprices/src/Internal/' . $gnp_cpr_class . '.php';
	}

	require_once $gnp_cpr_dir . '/vendor/gunsnprices/src/Parser.php';

	foreach (['Calculator', 'Settings', 'Repository', 'Service'] as $gnp_cpr_class) {
		require_once $gnp_cpr_dir . '/src/' . $gnp_cpr_class . '.php';
	}

	unset($gnp_cpr_dir, $gnp_cpr_class);
}
