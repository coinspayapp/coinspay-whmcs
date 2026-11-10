<?php
/**
 * The module in one place: settings, tables, API client and texts for one request.
 */
defined('WHMCS') or die('This file cannot be accessed directly');

class CoinsPay_Module {

	/** @var CoinsPay_Host */
	private $host;

	/** @var CoinsPay_Settings|null */
	private $settings;

	/** @var CoinsPay_Repo|null */
	private $repo;

	/** @var CoinsPay_Currencies|null */
	private $currencies;

	/** @var int Seconds of an API request: short inside hooks of pages. */
	private $timeout;

	/** @var CoinsPay_ApiClient|null */
	private $client;

	public function __construct(CoinsPay_Host $host, $timeout = CoinsPay_ApiClient::TIMEOUT) {
		$this->host = $host;
		$this->timeout = (int)$timeout;
	}

	public function host() {
		return $this->host;
	}

	public function settings() {
		if ($this->settings === null) {
			$this->settings = new CoinsPay_Settings($this->host->settings());
		}
		return $this->settings;
	}

	public function repo() {
		if ($this->repo === null) {
			$this->repo = new CoinsPay_Repo($this->host);
		}
		return $this->repo;
	}

	public function currencies() {
		if ($this->currencies === null) {
			$this->currencies = new CoinsPay_Currencies($this);
		}
		return $this->currencies;
	}

	public function client() {
		if ($this->client === null) {
			$this->client = $this->settings()->client($this->userAgent(), $this->timeout);
		}
		return $this->client;
	}

	/** For tests: an API client of their own. */
	public function setClient($client) {
		$this->client = $client;
	}

	/** For tests: tables in memory. */
	public function setRepo(CoinsPay_Repo $repo) {
		$this->repo = $repo;
	}

	public function tokens() {
		return new CoinsPay_Tokens($this->settings()->tokenSecret());
	}

	/** Texts in the language of WHMCS: the gateway log and messages for the admin. */
	public function adminText() {
		return new CoinsPay_Text($this->host->systemLanguage());
	}

	public function sync() {
		return new CoinsPay_Sync($this);
	}

	public function userAgent() {
		return 'CoinsPay-WHMCS/' . CoinsPay_Settings::VERSION . '; PHP/' . PHP_VERSION;
	}

	/** Address of the single entry point of the module. */
	public function callbackUrl($query = array()) {
		$url = $this->host->systemUrl() . 'modules/gateways/callback/coinspay.php';
		return $query ? $url . '?' . http_build_query($query, '', '&') : $url;
	}

	/**
	 * A debug line of the gateway log; errors always.
	 *
	 * @param string $message
	 * @param bool   $error
	 */
	public function log($message, $error = false) {
		if ($error || $this->settings()->flag('debug')) {
			$this->host->gatewayLog(array('module' => 'coinspay'), ($error ? 'Error: ' : '') . $message);
		}
	}
}
