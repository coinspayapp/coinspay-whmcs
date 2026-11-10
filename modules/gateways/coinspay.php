<?php
/**
 * CoinsPay for WHMCS: cryptocurrency payments of invoices on the CoinsPay payment page.
 *
 * A third party gateway: "Pay Now" sends the client to CoinsPay, the webhook and the return
 * from the payment page add the payment to the invoice.
 *
 * @license GPL-2.0-or-later
 */

if (!defined('WHMCS')) {
	die('This file cannot be accessed directly');
}

require_once __DIR__ . '/coinspay/loader.php';

/**
 * @return array
 */
function coinspay_MetaData() {
	return array(
		'DisplayName' => 'CoinsPay',
		'APIVersion' => '1.1',
		'DisableLocalCreditCardInput' => true,
		'TokenisedStorage' => false,
	);
}

/**
 * Settings of the gateway. WHMCS may call this on every callback: no database or network here.
 *
 * @return array
 */
function coinspay_config() {
	return array(
		'FriendlyName' => array('Type' => 'System', 'Value' => 'CoinsPay'),
		'api_url' => array(
			'FriendlyName' => 'API address',
			'Type' => 'text',
			'Size' => '60',
			'Description' => 'Leave empty to use https://coinspay.app. Change it only if CoinsPay gives you another address.',
		),
		'api_key' => array(
			'FriendlyName' => 'API key',
			'Type' => 'password',
			'Size' => '60',
			'Description' => 'Issue a key with "Invoices only" access in the CoinsPay dashboard: shop → API keys. A test key (cpk_test_…) lets you pay invoices without money.',
		),
		'webhook_secret' => array(
			'FriendlyName' => 'Webhook secret',
			'Type' => 'password',
			'Size' => '60',
			'Description' => 'From the webhook page of the shop in the CoinsPay dashboard (whsec_…). The webhook address is <code>&lt;your WHMCS address&gt;/modules/gateways/callback/coinspay.php</code>. <a href="../modules/gateways/callback/coinspay.php?a=check" target="_blank">Check the connection</a>',
		),
		'button_text' => array(
			'FriendlyName' => 'Button text',
			'Type' => 'text',
			'Size' => '30',
			'Description' => 'Text of the payment button on the invoice, for example "Pay with crypto". Leave empty for the WHMCS "Pay Now" in the client language.',
		),
		'lifetime' => array(
			'FriendlyName' => 'Invoice lifetime',
			'Type' => 'text',
			'Size' => '6',
			'Default' => '60',
			'Description' => 'Minutes the client has to pay, from 15 to 1440.',
		),
		'auto_return' => array(
			'FriendlyName' => 'Return automatically',
			'Type' => 'yesno',
			'Default' => 'on',
			'Description' => 'After the payment the payment page takes the client back to the invoice by itself.',
		),
		'stablecoins_only' => array(
			'FriendlyName' => 'Stablecoins only',
			'Type' => 'yesno',
			'Description' => 'The client pays in USDT, USDC and other stablecoins only.',
		),
		'buyer_pays_fee' => array(
			'FriendlyName' => 'Client pays the CoinsPay fee',
			'Type' => 'yesno',
		),
		'buyer_pays_network' => array(
			'FriendlyName' => 'Client pays the network fee',
			'Type' => 'yesno',
		),
		'test_admin_only' => array(
			'FriendlyName' => 'Test key for admins only',
			'Type' => 'yesno',
			'Default' => 'on',
			'Description' => 'With a test key, only a signed-in admin sees the payment button.',
		),
		'apply_test' => array(
			'FriendlyName' => 'Add test payments',
			'Type' => 'yesno',
			'Description' => 'A test payment of an admin is added to the invoice: WHMCS then records revenue, sends emails and provisions the service. Use it on a test installation.',
		),
		'debug' => array(
			'FriendlyName' => 'Debug log',
			'Type' => 'yesno',
			'Description' => 'Every step of the module goes to the gateway log.',
		),
	);
}

/**
 * "Pay Now": a form that takes the client to CoinsPay. No side effects — WHMCS draws it on the
 * invoice page, after checkout and maybe in emails.
 *
 * @param array $params
 * @return string HTML.
 */
function coinspay_link($params) {
	try {
		$module = new CoinsPay_Module(new CoinsPay_WhmcsHost($params));
		$settings = $module->settings();
		$payment = new CoinsPay_Payment($module);
		$form = $payment->form($params);
		$text = new CoinsPay_Text($form ? $form['lang'] : 'en');
		if (!$form || $settings->apiKey() === '' || $settings->tokenSecret() === ''
			|| ($settings->isTestMode() && $settings->flag('test_admin_only') && $module->host()->adminId() <= 0)) {
			return '<p>' . CoinsPay_Util::html($text->t('link_unavailable')) . '</p>';
		}

		$html = '<form method="post" action="' . CoinsPay_Util::html($module->callbackUrl(array('a' => 'pay'))) . '">';
		foreach ($form as $name => $value) {
			$html .= '<input type="hidden" name="' . CoinsPay_Util::html($name) . '" value="' . CoinsPay_Util::html($value) . '">';
		}
		$button = $settings->buttonText();
		if ($button === '') {
			$button = isset($params['langpaynow']) && $params['langpaynow'] !== '' ? $params['langpaynow'] : 'Pay Now';
		}
		return $html . '<button type="submit" class="btn btn-success">' . CoinsPay_Util::html($button) . '</button></form>';
	} catch (Exception $e) {
		return '<p>Payment in cryptocurrency is not available right now.</p>';
	}
}
