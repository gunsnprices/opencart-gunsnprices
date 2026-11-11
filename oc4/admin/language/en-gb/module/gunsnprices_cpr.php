<?php
// Heading
$_['heading_title']          = 'Ammo Cost per Round & Firearm Specs';

// Text
$_['text_extension']         = 'Extensions';
$_['text_edit']              = 'Edit Ammo Cost per Round & Firearm Specs';
$_['text_success']           = 'Success: You have modified Ammo Cost per Round & Firearm Specs!';
$_['text_reset']             = 'Success: This store uses the default store settings again.';
$_['text_default']           = 'Default';
$_['text_store_inherit']     = 'This store has no settings of its own and uses the settings of the default store, shown below. Saving creates settings for this store only.';
$_['text_store_own']         = 'This store has its own settings.';
$_['text_general']           = 'General';
$_['text_cost']              = 'Cost per round';
$_['text_cost_help']         = 'Price divided by the number of rounds in the box. Uses the price the shopper sees: special price if there is one, with or without tax as set in System > Settings, in the shopper\'s currency.';
$_['text_specs']             = 'Firearm specs';
$_['text_specs_help']        = 'Caliber, magazine capacity and barrel length read from the product name, on firearm product pages. Specs the product already has as attributes with the same name (for example "Caliber") are not repeated.';
$_['text_test']              = 'Try a product name';
$_['text_test_help']         = 'Shows what the extension reads from a name. Nothing is saved.';
$_['text_position_inline']   = 'After the price: $21.99 ($0.44/rd)';
$_['text_position_below']    = 'On its own line below the price';
$_['text_position_none']     = 'Do not change the price (template variable gnp_cost_per_round only)';
$_['text_format_short']      = '$0.38/rd';
$_['text_format_long']       = '$0.38 per round';
$_['text_decimals_currency'] = 'Same as the currency ($0.38)';
$_['text_decimals_extra']    = 'One more decimal place ($0.385)';
$_['text_source_name']       = 'Product name ("Federal 9mm 115gr FMJ 50 Rounds")';
$_['text_source_attribute']  = 'Product attribute';
$_['text_source_option']     = 'Product option: one round count per option value';
$_['text_mode_guess']        = 'Guess from the product name';
$_['text_mode_category']     = 'Products in the categories below (and their subcategories)';
$_['text_specs_tab']         = 'Specification tab (as an attribute group)';
$_['text_specs_description'] = 'Top of the product description';
$_['text_field_caliber']     = 'Caliber';
$_['text_field_capacity']    = 'Capacity';
$_['text_field_barrel']      = 'Barrel length';
$_['text_select']            = '-- Select --';
$_['text_none']              = 'There are none in your store yet.';
$_['text_test_type']         = 'Type';
$_['text_test_ammo']         = 'Ammunition';
$_['text_test_firearm']      = 'Firearm or other';
$_['text_test_rounds']       = 'Rounds per box';
$_['text_test_cost']         = 'Cost per round';
$_['text_credit']            = 'This extension is free. It reads names with the open source parser of <a href="https://gunsnprices.com" target="_blank" rel="noopener">GunsNPrices</a>, a gun and ammo price comparison site, and makes no requests to other sites.';

// Entry
$_['entry_store']            = 'Settings for store';
$_['entry_status']           = 'Status';
$_['entry_product']          = 'Show on product pages';
$_['entry_list']             = 'Show in product lists';
$_['entry_position']         = 'Position';
$_['entry_format']           = 'Format';
$_['entry_decimals']         = 'Decimal places';
$_['entry_source']           = 'Read the round count from';
$_['entry_attribute']        = 'Attribute';
$_['entry_option']           = 'Option';
$_['entry_ammo_mode']        = 'Which products are ammunition';
$_['entry_ammo_category']    = 'Ammunition categories';
$_['entry_specs']            = 'Show firearm specs';
$_['entry_specs_position']   = 'Position';
$_['entry_specs_fields']     = 'Specs to show';
$_['entry_firearm_mode']     = 'Which products are firearms';
$_['entry_firearm_category'] = 'Firearm categories';
$_['entry_caliber_links']    = 'Link calibers to GunsNPrices.com';
$_['entry_test_name']        = 'Product name';
$_['entry_test_price']       = 'Price (optional)';

// Help
$_['help_list']              = 'Category, search, specials and brand pages, related products, and modules that show product boxes, such as Featured, Latest, Bestsellers and Specials.';
$_['help_position']          = 'With "Do not change the price", themes can print {{ gnp_cost_per_round }} in the product template and {{ product.gnp_cost_per_round }} in lists.';
$_['help_source']            = 'An attribute value can be "50", "1,000" or "50 Rounds". Option values are named like "50 Rounds" or "Box of 20"; each value then shows its own cost per round and lists show the lowest ("from $0.30/rd"). When the attribute or option has no round count, the product name is read.';
$_['help_ammo_mode']         = 'Guessing reads names with a bullet weight ("115gr"), "ammo", "cartridges", "box of", "/box" or shotgun shell words ("shells", "buck", "slug") and a round count. If your names are short, such as "9mm 50rd", choose categories instead.';
$_['help_firearm_mode']      = 'Guessing shows specs for products whose name has a caliber and does not read like ammunition, so magazines and barrels get specs too.';
$_['help_caliber_links']     = 'Off by default. When on, caliber names link to their price comparison page on GunsNPrices.com, for example 9×19mm Parabellum to gunsnprices.com/ammo/handgun-ammo/9-19mm-parabellum. Links open in a new tab.';

// Button
$_['button_reset']           = 'Use default store settings';
$_['button_test']            = 'Read';

// Error
$_['error_permission']       = 'Warning: You do not have permission to modify Ammo Cost per Round & Firearm Specs!';
$_['error_warning']          = 'Warning: Please check the form carefully for errors!';
$_['error_attribute']        = 'Choose the attribute that holds the round count.';
$_['error_option']           = 'Choose the option whose values hold the round count.';
$_['error_category']         = 'Choose at least one category.';
$_['error_reset_default']    = 'The default store always has its own settings.';
$_['error_test_name']        = 'Enter a product name.';
