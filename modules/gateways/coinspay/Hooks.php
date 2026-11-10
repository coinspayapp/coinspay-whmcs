<?php
/**
 * Handlers of the hooks in includes/hooks/coinspay.php. They run inside pages and the cron of
 * WHMCS: short API timeouts, no tables created, every error kept inside.
 */
defined('WHMCS') or die('This file cannot be accessed directly');

class CoinsPay_Hooks {

	/** Seconds of an API request from a hook. */
	const TIMEOUT = 5;

	/**
	 * The module, or null when the gateway is off or not set up yet.
	 *
	 * @return CoinsPay_Module|null
	 */
	public static function module() {
		$module = new CoinsPay_Module(new CoinsPay_WhmcsHost(), self::TIMEOUT);
		$settings = $module->settings();
		if (!$settings->active() || $settings->apiKey() === '' || !$module->repo()->ready()) {
			return null;
		}
		return $module;
	}

	/**
	 * The WHMCS invoice was cancelled, changed, split or got a late fee: its open CoinsPay
	 * invoices without money are cancelled, and the next "Pay now" makes one for the new sum.
	 */
	public static function invoiceChanged($vars) {
		// InvoiceSplit names the invoice that was split originalinvoiceid.
		$invoiceId = !empty($vars['invoiceid']) ? (int)$vars['invoiceid'] : (!empty($vars['originalinvoiceid']) ? (int)$vars['originalinvoiceid'] : 0);
		$module = self::module();
		if ($module && $invoiceId > 0) {
			$payment = new CoinsPay_Payment($module);
			$payment->cancelReplaced($invoiceId);
		}
	}

	/** Another gateway was chosen for the invoice. */
	public static function gatewayChanged($vars) {
		if (isset($vars['paymentmethod']) && $vars['paymentmethod'] !== CoinsPay_WhmcsHost::GATEWAY) {
			self::invoiceChanged($vars);
		}
	}

	/** The invoice got paid: by another method, unless the module itself is adding the payment. */
	public static function invoicePaid($vars) {
		if (!CoinsPay_Sync::$applying) {
			self::invoiceChanged($vars);
		}
	}

	/** Every run of the cron of WHMCS. */
	public static function cron() {
		$module = self::module();
		if ($module) {
			$poller = new CoinsPay_Poller($module);
			$poller->run();
		}
	}

	/** The client opens the invoice: in case the webhook and the cron did not bring its state. */
	public static function viewInvoice($vars) {
		$module = self::module();
		if ($module && !empty($vars['invoiceid'])) {
			$poller = new CoinsPay_Poller($module);
			$poller->checkInvoice((int)$vars['invoiceid']);
		}
		return array();
	}

	/** Panel on the admin page of an invoice: its CoinsPay invoices and money that needs a decision. */
	public static function adminInvoice($vars) {
		$module = self::module();
		if (!$module || empty($vars['invoiceid'])) {
			return '';
		}
		$admin = new CoinsPay_Admin($module);
		return $admin->panel((int)$vars['invoiceid']);
	}
}
