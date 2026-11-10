<?php
/**
 * Signed values of the module's own links and forms.
 */
defined('WHMCS') or die('This file cannot be accessed directly');

class CoinsPay_Tokens {

	/** Name of the signature field and parameter: not "token", which WHMCS uses for its CSRF token. */
	const FIELD = 'cp_sig';

	/** @var string */
	private $secret;

	public function __construct($secret) {
		$this->secret = (string)$secret;
	}

	/**
	 * Signature of the pay form: what the invoice was when the page drew it. ?a=pay checks it
	 * against the invoice now and counts the amount to pay itself.
	 *
	 * @param array $form {invoice, balance, currency_id, lang}.
	 * @return string
	 */
	public function form($form) {
		return $this->sign('pay|' . implode('|', array(
			(int)$form['invoice'],
			(string)$form['balance'],
			(int)$form['currency_id'],
			(string)$form['lang'],
		)));
	}

	public function checkForm($form, $signature) {
		return $this->secret !== '' && CoinsPay_Util::equals($this->form($form), (string)$signature);
	}

	/**
	 * Signature of the return link of an invoice. Without time in it: the return link is part of
	 * the invoice body, and the body is part of the idempotency key.
	 */
	public function returnToken($invoiceId) {
		return $this->sign('return|' . (int)$invoiceId);
	}

	public function checkReturn($invoiceId, $signature) {
		return $this->secret !== '' && CoinsPay_Util::equals($this->returnToken($invoiceId), (string)$signature);
	}

	private function sign($data) {
		return substr(hash_hmac('sha256', $data, $this->secret), 0, 40);
	}
}
