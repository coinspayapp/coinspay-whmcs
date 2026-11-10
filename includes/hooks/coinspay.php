<?php
/**
 * Hooks of CoinsPay for WHMCS. WHMCS loads this file on every request, even after the gateway is
 * removed, and an error here would break every page: so it is small, checks that the module is
 * there, and keeps every error inside. Gateway modules have no hooks.php of their own.
 */

if (!defined('WHMCS')) {
	die('This file cannot be accessed directly');
}

$coinspayLoader = __DIR__ . '/../../modules/gateways/coinspay/loader.php';
if (is_file($coinspayLoader)) {
	$coinspayRun = function ($handler) use ($coinspayLoader) {
		return function ($vars) use ($handler, $coinspayLoader) {
			try {
				require_once $coinspayLoader;
				return call_user_func(array('CoinsPay_Hooks', $handler), $vars);
			} catch (Exception $e) {
				return $handler === 'viewInvoice' ? array() : null;
			} catch (Throwable $e) { // phpcs:ignore PHPCompatibility.Interfaces.NewInterfaces.throwableFound -- PHP 5.6 never throws it, PHP 7+ errors land here
				return $handler === 'viewInvoice' ? array() : null;
			}
		};
	};
	foreach (array('InvoiceCancelled', 'UpdateInvoiceTotal', 'InvoiceSplit', 'AddInvoiceLateFee') as $coinspayHook) {
		add_hook($coinspayHook, 1, $coinspayRun('invoiceChanged'));
	}
	add_hook('InvoiceChangeGateway', 1, $coinspayRun('gatewayChanged'));
	add_hook('InvoicePaid', 1, $coinspayRun('invoicePaid'));
	add_hook('AfterCronJob', 1, $coinspayRun('cron'));
	add_hook('ClientAreaPageViewInvoice', 1, $coinspayRun('viewInvoice'));
	add_hook('AdminInvoicesControlsOutput', 1, $coinspayRun('adminInvoice'));
	unset($coinspayHook, $coinspayRun);
}
unset($coinspayLoader);
