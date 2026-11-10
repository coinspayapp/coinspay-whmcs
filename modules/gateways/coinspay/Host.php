<?php
/**
 * What the module needs from WHMCS. CoinsPay_WhmcsHost is the real one; unit tests use a fake.
 */
defined('WHMCS') or die('This file cannot be accessed directly');

interface CoinsPay_Host {

	/** @return PDO The database connection of WHMCS. */
	public function pdo();

	/** @return array Settings of the gateway, as getGatewayVariables() gives them. */
	public function settings();

	/** @return string Address of WHMCS with the trailing slash (SystemURL). */
	public function systemUrl();

	/** @return string en or ru: the default language of WHMCS. */
	public function systemLanguage();

	/**
	 * The invoice of WHMCS, or null.
	 *
	 * @param int $invoiceId
	 * @return array|null {id, userid, status, total, balance, currency_id, currency_code, paymentmethod}.
	 *                    total and balance are in the invoice currency: the currency of the client.
	 */
	public function invoice($invoiceId);

	/**
	 * A currency of WHMCS, or null.
	 *
	 * @param int $currencyId
	 * @return array|null {id, code, rate}: rate to the default currency, as tblcurrencies keeps it.
	 */
	public function currency($currencyId);

	/**
	 * Whether a payment with this transaction id of the gateway is recorded.
	 *
	 * @param string $transactionId
	 * @return bool
	 */
	public function hasPayment($transactionId);

	/**
	 * Adds a payment to the invoice (addInvoicePayment).
	 *
	 * @param int    $invoiceId
	 * @param string $transactionId
	 * @param string $amount In the invoice currency.
	 */
	public function addPayment($invoiceId, $transactionId, $amount);

	/**
	 * A line of the gateway log (logTransaction).
	 *
	 * @param mixed  $data
	 * @param string $result
	 */
	public function gatewayLog($data, $result);

	/** A line of the activity log (logActivity): the admin sees it on the home page. */
	public function activity($message);

	/** @return int Id of the admin signed in in this session, 0 when none. */
	public function adminId();

	/** @return string Language of this session or of the client: en or ru. */
	public function language($userId);
}
