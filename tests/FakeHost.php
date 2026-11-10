<?php
/**
 * WHMCS for unit tests: one invoice, payments in an array, and an addInvoicePayment that can
 * fail, print or claim success without recording anything, as asked.
 */
final class FakeHost implements CoinsPay_Host {

	public $settings = array('type' => 'Invoices', 'name' => 'CoinsPay', 'api_url' => 'https://pay.example/api/v1', 'api_key' => 'cpk_live_key', 'webhook_secret' => 'whsec_test');
	public $invoice = array('id' => 42, 'userid' => 7, 'status' => 'Unpaid', 'total' => '100.00', 'balance' => '100.00', 'currency_id' => 1, 'currency_code' => 'USD', 'paymentmethod' => 'coinspay');
	public $payments = array();
	public $log = array();
	public $activity = array();
	public $admin = 0;

	/** @var string|null "throw" — fail before recording; "throw_after" — record, then fail; "silent" — record nothing. */
	public $fail = null;

	public function pdo() {
		throw new LogicException('no database in unit tests');
	}

	public function settings() {
		return $this->settings;
	}

	public function systemUrl() {
		return 'https://whmcs.example/';
	}

	public function systemLanguage() {
		return 'en';
	}

	public function invoice($invoiceId) {
		return (int)$invoiceId === $this->invoice['id'] ? $this->invoice : null;
	}

	/** Rates to the default currency, as tblcurrencies keeps them. */
	public $currencies = array(
		1 => array('id' => 1, 'code' => 'USD', 'rate' => '1.00000'),
		2 => array('id' => 2, 'code' => 'RUB', 'rate' => '92.50000'),
	);

	public function currency($currencyId) {
		return isset($this->currencies[(int)$currencyId]) ? $this->currencies[(int)$currencyId] : null;
	}

	public function hasPayment($transactionId) {
		return isset($this->payments[$transactionId]);
	}

	public function addPayment($invoiceId, $transactionId, $amount) {
		$fail = $this->fail;
		$this->fail = null;
		if ($fail === 'throw') {
			throw new RuntimeException('the invoice could not be saved');
		}
		echo 'output of another module';
		if ($fail !== 'silent') {
			$this->payments[$transactionId] = $amount;
			$this->invoice['balance'] = number_format(max(0, (float)$this->invoice['balance'] - (float)$amount), 2, '.', '');
			if ((float)$this->invoice['balance'] <= 0) {
				$this->invoice['status'] = 'Paid';
			}
		}
		if ($fail === 'throw_after') {
			throw new RuntimeException('a hook of another module failed');
		}
	}

	public function gatewayLog($data, $result) {
		$this->log[] = $result;
	}

	public function activity($message) {
		$this->activity[] = $message;
	}

	public function adminId() {
		return $this->admin;
	}

	public function language($userId) {
		return 'en';
	}
}

/** The tables of the module in memory. */
final class MemoryRepo extends CoinsPay_Repo {

	public $rows = array();

	public function __construct() {
	}

	public function ready() {
		return true;
	}

	public function add($row) {
		$this->rows[$row['invoice_id']] = $row + array('whmcs_userid' => 7, 'apply_state' => 'none', 'claimed' => null, 'fingerprint' => null, 'attention' => null, 'snapshot' => null);
	}

	public function claim($invoiceId, $stale) {
		$row = $this->rows[strtolower($invoiceId)];
		if ($row['apply_state'] !== 'none' && !($row['apply_state'] === 'applying' && ($row['claimed'] === null || $row['claimed'] < time() - $stale))) {
			return false;
		}
		$this->update($invoiceId, array('apply_state' => 'applying', 'claimed' => time()));
		return true;
	}

	public function find($invoiceId) {
		return isset($this->rows[strtolower($invoiceId)]) ? $this->rows[strtolower($invoiceId)] : null;
	}

	public function forInvoice($whmcsInvoice) {
		$rows = array();
		foreach ($this->rows as $row) {
			if ((int)$row['whmcs_invoice'] === (int)$whmcsInvoice) {
				$rows[] = $row;
			}
		}
		usort($rows, function ($a, $b) {
			return $b['attempt'] - $a['attempt'];
		});
		return $rows;
	}

	public function update($invoiceId, $columns) {
		$this->rows[strtolower($invoiceId)] = $columns + $this->rows[strtolower($invoiceId)];
	}

	public function due($limit) {
		$now = time();
		return array_slice(array_values(array_filter($this->rows, function ($row) use ($now) {
			return $row['next_poll'] !== null && $row['next_poll'] <= $now && $row['poll_until'] > $now;
		})), 0, $limit);
	}

	public $kv = array();

	public function install() {
	}

	public function kvGet($key, $maxAge = 0) {
		return isset($this->kv[$key]) ? $this->kv[$key] : null;
	}

	public function kvSet($key, $value) {
		$this->kv[$key] = (string)$value;
	}

	public $turns = array();

	public function takeTurn($key, $every) {
		if (isset($this->turns[$key]) && $this->turns[$key] > time() - $every) {
			return false;
		}
		$this->turns[$key] = time();
		return true;
	}

	public function lock($whmcsInvoice) {
		return true;
	}

	public function unlock($whmcsInvoice) {
	}
}

/** The CoinsPay API in memory: invoices by id, and the requests that came. */
final class FakeClient extends CoinsPay_ApiClient {

	public $invoices = array();
	public $created = array();
	public $cancelled = array();

	public function __construct() {
	}

	public function currencies() {
		return array('fiat' => array(array('code' => 'USD', 'scale' => 2), array('code' => 'RUB', 'scale' => 2)), 'crypto' => array());
	}

	public function createInvoice($body, $idempotencyKey) {
		$this->created[] = array($body, $idempotencyKey);
		$id = sprintf('00000000-0000-4000-8000-%012d', count($this->created));
		return $this->invoices[$id] = array(
			'id' => $id, 'number' => count($this->created), 'status' => 'open', 'test' => false,
			'currency' => $body['currency'], 'amount' => $body['amount'], 'amount_paid' => '0', 'amount_pending' => '0',
			'amount_due' => $body['amount'], 'amount_overpaid' => null, 'paid_late' => false, 'paid_manually' => false,
			'pay_url' => 'https://pay.example/pay/' . $id, 'payments' => array(),
		);
	}

	public $reads = 0;

	public function invoice($id) {
		$this->reads++;
		if (!isset($this->invoices[$id])) {
			throw new CoinsPay_ApiError(404, 'NOT_FOUND', 'Invoice not found');
		}
		return $this->invoices[$id];
	}

	public function cancelInvoice($id) {
		$this->cancelled[] = $id;
		$this->invoices[$id]['status'] = 'cancelled';
		return $this->invoices[$id];
	}
}
