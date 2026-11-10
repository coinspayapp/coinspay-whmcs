<?php
/**
 * WHMCS 7.0–9.x as the module needs it: plain SQL through the PDO of Capsule, which every version
 * has, and the documented gateway functions. No localAPI: before 7.2 it needs an admin name.
 */
defined('WHMCS') or die('This file cannot be accessed directly');

class CoinsPay_WhmcsHost implements CoinsPay_Host {

	const GATEWAY = 'coinspay';

	/** @var array|null */
	private $settings;

	/** @var array per request */
	private $config = array();

	/**
	 * @param array|null $settings $params of a module function; read with getGatewayVariables() when null.
	 */
	public function __construct($settings = null) {
		$this->settings = $settings;
	}

	public function pdo() {
		return \WHMCS\Database\Capsule::connection()->getPdo();
	}

	public function settings() {
		if ($this->settings === null) {
			self::requireFile('includes/gatewayfunctions.php', 'getGatewayVariables');
			$this->settings = getGatewayVariables(self::GATEWAY);
		}
		return is_array($this->settings) ? $this->settings : array();
	}

	public function systemUrl() {
		$url = rtrim((string)$this->config('SystemURL'), '/');
		return $url === '' ? '' : $url . '/';
	}

	public function systemLanguage() {
		return self::languageCode($this->config('Language'));
	}

	public function invoice($invoiceId) {
		$row = $this->row(
			'SELECT i.id, i.userid, i.status, i.total, i.paymentmethod, c.currency AS currency_id, cur.code AS currency_code
			FROM tblinvoices i LEFT JOIN tblclients c ON c.id = i.userid LEFT JOIN tblcurrencies cur ON cur.id = c.currency
			WHERE i.id = ?',
			array((int)$invoiceId)
		);
		if (!$row) {
			return null;
		}
		$paid = $this->row('SELECT COALESCE(SUM(amountin), 0) - COALESCE(SUM(amountout), 0) AS paid FROM tblaccounts WHERE invoiceid = ?', array((int)$invoiceId));
		$row['balance'] = number_format(max(0, (float)$row['total'] - (float)$paid['paid']), 2, '.', '');
		$row['total'] = number_format((float)$row['total'], 2, '.', '');
		$row['id'] = (int)$row['id'];
		$row['userid'] = (int)$row['userid'];
		$row['currency_id'] = (int)$row['currency_id'];
		return $row;
	}

	public function currency($currencyId) {
		$row = $this->row('SELECT id, code, rate FROM tblcurrencies WHERE id = ?', array((int)$currencyId));
		if (!$row) {
			return null;
		}
		return array('id' => (int)$row['id'], 'code' => strtoupper((string)$row['code']), 'rate' => (string)$row['rate']);
	}

	public function hasPayment($transactionId) {
		return (bool)$this->row('SELECT 1 AS found FROM tblaccounts WHERE gateway = ? AND transid = ? LIMIT 1', array(self::GATEWAY, (string)$transactionId));
	}

	public function addPayment($invoiceId, $transactionId, $amount) {
		self::requireFile('includes/invoicefunctions.php', 'addInvoicePayment');
		addInvoicePayment((int)$invoiceId, (string)$transactionId, (string)$amount, '0.00', self::GATEWAY);
	}

	public function gatewayLog($data, $result) {
		self::requireFile('includes/gatewayfunctions.php', 'logTransaction');
		$settings = $this->settings();
		logTransaction(isset($settings['name']) ? $settings['name'] : 'CoinsPay', $data, (string)$result);
	}

	public function activity($message) {
		if (function_exists('logActivity')) {
			logActivity('CoinsPay: ' . $message);
		}
	}

	public function adminId() {
		return (int)self::session('adminid');
	}

	public function language($userId) {
		$session = (string)self::session('Language');
		if ($session !== '') {
			return self::languageCode($session);
		}
		if ($userId > 0) {
			$row = $this->row('SELECT language FROM tblclients WHERE id = ?', array((int)$userId));
			if ($row && (string)$row['language'] !== '') {
				return self::languageCode($row['language']);
			}
		}
		return $this->systemLanguage();
	}

	/** WHMCS names languages: "russian", "english". The payment page speaks en and ru. */
	public static function languageCode($name) {
		return strtolower(trim((string)$name)) === 'russian' ? 'ru' : 'en';
	}

	private function config($setting) {
		if (!array_key_exists($setting, $this->config)) {
			$row = $this->row('SELECT value FROM tblconfiguration WHERE setting = ? LIMIT 1', array($setting));
			$this->config[$setting] = $row ? (string)$row['value'] : '';
		}
		return $this->config[$setting];
	}

	private function row($sql, $args) {
		$statement = $this->pdo()->prepare($sql);
		CoinsPay_Repo::run($statement, $args);
		$row = $statement->fetch(PDO::FETCH_ASSOC);
		$statement->closeCursor();
		return $row ? $row : null;
	}

	/** A value of the session: WHMCS\Session where it exists, $_SESSION before it. */
	private static function session($key) {
		if (class_exists('WHMCS\Session') && method_exists('WHMCS\Session', 'get')) {
			return \WHMCS\Session::get($key);
		}
		return isset($_SESSION[$key]) ? $_SESSION[$key] : null;
	}

	private static function requireFile($file, $function) {
		if (!function_exists($function)) {
			require_once ROOTDIR . '/' . $file;
		}
	}
}
