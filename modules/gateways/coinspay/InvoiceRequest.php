<?php
/**
 * Body of POST /invoices for an invoice of WHMCS, and its idempotency key. No WHMCS calls, so it
 * runs in unit tests. After the OpenCart plugin (core/InvoiceRequest.php).
 */
defined('WHMCS') or die('This file cannot be accessed directly');

class CoinsPay_InvoiceRequest {

	const MAX_DESCRIPTION = 1000;
	const MAX_REFERENCE = 190;
	const MAX_RETURN_URL = 500;

	/**
	 * @param array             $invoice  {number, currency, amount, description, return_url}: currency
	 *                                    and amount as WHMCS hands them to the gateway, converted if
	 *                                    "Convert To For Processing" is set.
	 * @param CoinsPay_Settings $settings
	 * @return array
	 */
	public static function body($invoice, CoinsPay_Settings $settings) {
		// No title: the payment page then names the invoice "Order <reference>" in the buyer's language.
		$body = array(
			'currency' => strtoupper((string)$invoice['currency']),
			'amount' => (string)$invoice['amount'],
			'reference' => CoinsPay_Util::truncate((string)$invoice['number'], self::MAX_REFERENCE),
			'lifetime_minutes' => $settings->lifetime(),
		);
		$description = trim(html_entity_decode(strip_tags((string)$invoice['description']), ENT_QUOTES, 'UTF-8'));
		if ($description !== '') {
			$body['description'] = CoinsPay_Util::truncate($description, self::MAX_DESCRIPTION);
		}

		// A link longer than the API takes would fail the whole invoice; without it the buyer still pays.
		$returnUrl = (string)$invoice['return_url'];
		if ($returnUrl !== '' && strlen($returnUrl) <= self::MAX_RETURN_URL) {
			$body['return_url'] = $returnUrl;
			if ($settings->flag('auto_return')) {
				$body['auto_return'] = true;
			}
		}

		foreach (array('stablecoins_only', 'buyer_pays_fee', 'buyer_pays_network') as $flag) {
			if ($settings->flag($flag)) {
				$body[$flag] = true;
			}
		}
		return $body;
	}

	/**
	 * Idempotency key: the same invoice, attempt and body give the same key, so a repeated "Pay now"
	 * gets the CoinsPay invoice already made. The body is part of the key, so a changed amount never
	 * meets the "same key, other body" error; the site part keeps a staging copy on the same
	 * CoinsPay shop from getting invoices of the live WHMCS.
	 *
	 * @param string $site
	 * @param int    $invoiceId
	 * @param int    $attempt   CoinsPay invoice number within the WHMCS invoice, from 1.
	 * @param array  $body
	 * @return string
	 */
	public static function idempotencyKey($site, $invoiceId, $attempt, $body) {
		return sprintf('whmcs-%s-%d-%d-%s', substr(md5((string)$site), 0, 8), (int)$invoiceId, (int)$attempt, substr(sha1(CoinsPay_Util::json($body)), 0, 12));
	}
}
