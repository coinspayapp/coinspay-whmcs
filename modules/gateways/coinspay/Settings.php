<?php
/**
 * Settings of the gateway, from the values WHMCS keeps for it.
 */
defined('WHMCS') or die('This file cannot be accessed directly');

class CoinsPay_Settings {

	const VERSION = '0.1.0';
	const LIFETIME_MIN = 15;
	const LIFETIME_MAX = 1440;
	const LIFETIME_DEFAULT = 60;
	/** The API address when the admin leaves the field empty. */
	const DEFAULT_API_URL = 'https://coinspay.app';

	/** @var array Defaults of the yes/no settings, used until the admin saves the gateway. */
	public static $flags = array(
		'auto_return' => true,
		'stablecoins_only' => false,
		'buyer_pays_fee' => false,
		'buyer_pays_network' => false,
		'test_admin_only' => true,
		'apply_test' => false,
		'debug' => false,
	);

	/** @var array */
	private $values;

	/**
	 * @param array $values $params of a module function or getGatewayVariables().
	 */
	public function __construct($values) {
		$this->values = is_array($values) ? $values : array();
	}

	/** Whether the gateway is activated in WHMCS. */
	public function active() {
		return !empty($this->values['type']);
	}

	public function apiUrl() {
		$url = rtrim(trim($this->get('api_url')), '/');
		return $url === '' ? self::DEFAULT_API_URL : $url;
	}

	/** @return string Text of the payment button, '' for the "Pay Now" of WHMCS in the client language. */
	public function buttonText() {
		return trim($this->get('button_text'));
	}

	public function apiKey() {
		return trim($this->get('api_key'));
	}

	public function webhookSecret() {
		return trim($this->get('webhook_secret'));
	}

	public function isTestMode() {
		return strpos($this->apiKey(), 'cpk_test_') === 0;
	}

	public function lifetime() {
		return self::clampLifetime($this->get('lifetime'));
	}

	/** @return int Currency id of "Convert To For Processing", 0 when not set. */
	public function convertTo() {
		return (int)$this->get('convertto');
	}

	public static function clampLifetime($value) {
		$value = (int)$value;
		if ($value <= 0) {
			return self::LIFETIME_DEFAULT;
		}
		return max(self::LIFETIME_MIN, min(self::LIFETIME_MAX, $value));
	}

	/**
	 * A yes/no setting. WHMCS stores a ticked box as "on" and an unticked one as "".
	 *
	 * @param string $key
	 * @return bool
	 */
	public function flag($key) {
		if (!array_key_exists($key, $this->values)) {
			return !empty(self::$flags[$key]);
		}
		return in_array(strtolower(trim((string)$this->values[$key])), array('on', 'yes', '1', 'true'), true);
	}

	/**
	 * Secret of the module's own signed links. Made of the key and the webhook secret, so it
	 * needs no storage; replacing either makes old links invalid, and the page draws new ones.
	 */
	public function tokenSecret() {
		if ($this->apiKey() === '' || $this->webhookSecret() === '') {
			return '';
		}
		return hash('sha256', 'coinspay-whmcs|' . $this->apiKey() . '|' . $this->webhookSecret());
	}

	/**
	 * @param string $userAgent
	 * @param int    $timeout   Seconds.
	 * @return CoinsPay_ApiClient
	 */
	public function client($userAgent, $timeout = CoinsPay_ApiClient::TIMEOUT) {
		return new CoinsPay_ApiClient($this->apiUrl(), $this->apiKey(), $userAgent, $timeout);
	}

	private function get($key) {
		return isset($this->values[$key]) && is_scalar($this->values[$key]) ? (string)$this->values[$key] : '';
	}
}
