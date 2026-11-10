<?php
/**
 * Applies the state of a CoinsPay invoice to its WHMCS invoice: lock → fresh invoice → decision →
 * payment or log. A payment is added exactly once, without checkCbTransID: that one stops the
 * script, and the module answers the webhook itself.
 */
defined('WHMCS') or die('This file cannot be accessed directly');

class CoinsPay_Sync {

	/** Open invoices are polled every 5 minutes, closed ones every 30 until the end of polling. */
	const POLL_OPEN = 300;
	const POLL_CLOSED = 1800;

	/** A claim of a payment older than this belongs to a request that died: see Repo::claim(). */
	const CLAIM_STALE = 600;

	/** @var bool A payment is being added now: the InvoicePaid hook of the module keeps out. */
	public static $applying = false;

	/** @var int|null Output buffer level of the payment being added, for interrupted(). */
	private static $buffer;

	/** @var array|null {host, repo, CoinsPay invoice, transaction id} of the payment being added. */
	private static $pending;

	/** @var CoinsPay_Module */
	private $module;

	public function __construct(CoinsPay_Module $module) {
		$this->module = $module;
	}

	/**
	 * @param array $invoice CoinsPay invoice from the API.
	 * @return string What happened, for the log and the webhook answer.
	 * @throws RuntimeException The WHMCS invoice is busy, or the payment did not get recorded; the caller retries later.
	 */
	public function apply($invoice) {
		$repo = $this->module->repo();
		$row = $repo->find(CoinsPay_Util::value($invoice, 'id'));
		if (!$row) {
			return 'not an invoice of this WHMCS';
		}
		$whmcsInvoice = (int)$row['whmcs_invoice'];
		if (!$repo->lock($whmcsInvoice)) {
			throw new RuntimeException('the invoice is busy');
		}
		try {
			return $this->applyLocked($row['invoice_id'], $invoice);
		} finally {
			$repo->unlock($whmcsInvoice);
		}
	}

	private function applyLocked($invoiceId, $invoice) {
		$host = $this->module->host();
		$repo = $this->module->repo();
		// Fresh copies: another request may have changed both while we waited for the lock.
		$row = $repo->find($invoiceId);
		if (!$row) {
			return 'not an invoice of this WHMCS';
		}

		$status = CoinsPay_Util::value($invoice, 'status');
		$repo->update($invoiceId, array('status' => $status, 'snapshot' => CoinsPay_Util::json(self::snapshot($invoice))));
		$fingerprint = CoinsPay_Decision::fingerprint($invoice);
		$whmcs = $host->invoice((int)$row['whmcs_invoice']);
		if (!$whmcs) {
			return $this->gone($row, $invoice, $status, $fingerprint);
		}
		// A payment interrupted halfway is never "no change": it is checked again below.
		if ($row['fingerprint'] === $fingerprint && $row['apply_state'] !== 'applying') {
			$this->settle($invoiceId, $status, $row);
			return 'no change';
		}

		$test = !empty($invoice['test']);
		$transactionId = ($test ? 'test-' : '') . $invoiceId;
		$applied = $row['apply_state'] === 'applied' || $host->hasPayment($transactionId);
		if ($applied && $row['apply_state'] !== 'applied') {
			$repo->update($invoiceId, array('apply_state' => 'applied'));
		}

		$decision = CoinsPay_Decision::decide(
			$invoice,
			array(
				'whmcs_status' => $whmcs['status'],
				// The invoice currency is the client's: changed since, the stored amount means another sum.
				'currency_changed' => (int)$whmcs['currency_id'] !== (int)$row['whmcs_currency_id'],
				// The invoice was deleted and its number given to an invoice of another client.
				'client_changed' => (int)$whmcs['userid'] !== (int)$row['whmcs_userid'],
				'applied' => $applied,
				'test_admin' => !empty($row['test_admin']),
				'apply_test' => $this->module->settings()->flag('apply_test'),
			),
			$this->module->adminText()
		);
		$note = $decision['note'];
		$data = self::logData($invoice, $row);

		switch ($decision['action']) {
			case CoinsPay_Decision::APPLY:
				$this->addPayment($row, $whmcs, $transactionId);
				$host->gatewayLog($data, $note);
				// The balance is added; the excess stays in CoinsPay and is the admin's to decide.
				if (CoinsPay_Util::positive(CoinsPay_Util::value($invoice, 'amount_overpaid'))) {
					$host->activity(sprintf('#%d: %s', $whmcs['id'], $note));
					$repo->update($invoiceId, array('attention' => $note));
				}
				break;
			case CoinsPay_Decision::HOLD:
				$host->gatewayLog($data, $note);
				$host->activity(sprintf('#%d: %s', $whmcs['id'], $note));
				$repo->update($invoiceId, array('attention' => $note));
				break;
			case CoinsPay_Decision::LOG:
				$host->gatewayLog($data, $note);
				break;
		}

		// Only after the action went through: had it failed, the next webhook or poll must decide
		// again, not see "no change" and lose the payment.
		$repo->update($invoiceId, array('fingerprint' => $fingerprint));
		$this->settle($invoiceId, $status, $row);
		$this->module->log(sprintf('invoice #%d, CoinsPay %s (%s): %s', $whmcs['id'], $invoiceId, $status, $decision['action']));
		return $decision['action'] . ($note !== '' ? ': ' . $note : '');
	}

	/**
	 * addInvoicePayment exactly once. Its own hooks, emails and provisioning run inside it and may
	 * fail, print or even exit; tblaccounts tells whether the payment got recorded.
	 */
	private function addPayment($row, $whmcs, $transactionId) {
		$host = $this->module->host();
		$repo = $this->module->repo();
		if (!$repo->claim($row['invoice_id'], self::CLAIM_STALE)) {
			throw new RuntimeException('the payment of invoice #' . (int)$whmcs['id'] . ' is being added by another request');
		}
		// The request that held the claim before may have recorded the payment and died.
		if ($host->hasPayment($transactionId)) {
			self::release($host, $repo, $row['invoice_id'], $transactionId);
			return;
		}

		ignore_user_abort(true);
		if (function_exists('set_time_limit')) {
			@set_time_limit(120); // phpcs:ignore Generic.PHP.NoSilencedErrors -- disabled on some hosts
		}
		if (self::$buffer === null) {
			register_shutdown_function(array(__CLASS__, 'interrupted'));
		}
		self::$applying = true;
		self::$pending = array($host, $repo, $row['invoice_id'], $transactionId);
		ob_start();
		self::$buffer = ob_get_level();
		$recorded = false;
		try {
			$host->addPayment((int)$whmcs['id'], $transactionId, $row['whmcs_amount']);
		} finally {
			ob_end_clean();
			self::$applying = false;
			self::$pending = null;
			$recorded = self::release($host, $repo, $row['invoice_id'], $transactionId);
		}
		if (!$recorded) {
			throw new RuntimeException('WHMCS did not record the payment of invoice #' . (int)$whmcs['id']);
		}
	}

	/** Ends the claim: applied when tblaccounts has the payment, free for the next try when not. */
	private static function release(CoinsPay_Host $host, CoinsPay_Repo $repo, $invoiceId, $transactionId) {
		$recorded = $host->hasPayment($transactionId);
		$repo->update($invoiceId, array('apply_state' => $recorded ? 'applied' : 'none', 'claimed' => null));
		return $recorded;
	}

	/**
	 * Shutdown: something inside addInvoicePayment called exit. Its output is dropped, the claim
	 * ends as tblaccounts tells, and the webhook gets 500, so CoinsPay sends it again.
	 */
	public static function interrupted() {
		if (!self::$applying || self::$pending === null) {
			return;
		}
		$pending = self::$pending;
		self::$applying = false;
		self::$pending = null;
		while (ob_get_level() >= self::$buffer && ob_get_level() > 0) {
			ob_end_clean();
		}
		if (!headers_sent()) {
			http_response_code(500);
		}
		try {
			self::release($pending[0], $pending[1], $pending[2], $pending[3]);
		} catch (Exception $e) {
			// The claim stays and is taken over after CLAIM_STALE.
		}
	}

	/**
	 * The WHMCS invoice was deleted. Money on its CoinsPay invoice is not added anywhere: the
	 * admin is told once per state.
	 */
	private function gone($row, $invoice, $status, $fingerprint) {
		$hasMoney = CoinsPay_Util::positive(CoinsPay_Util::value($invoice, 'amount_paid')) || CoinsPay_Util::positive(CoinsPay_Util::value($invoice, 'amount_pending'));
		if ($hasMoney && $row['fingerprint'] !== $fingerprint) {
			$received = CoinsPay_Util::normalize((string)((float)CoinsPay_Util::value($invoice, 'amount_paid') + (float)CoinsPay_Util::value($invoice, 'amount_pending'))) . ' ' . CoinsPay_Util::value($invoice, 'currency');
			$note = $this->module->adminText()->t('log_invoice_gone', CoinsPay_Util::value($invoice, 'number'), $received, (int)$row['whmcs_invoice']);
			$this->module->host()->gatewayLog(self::logData($invoice, $row), $note);
			$this->module->host()->activity(sprintf('#%d: %s', $row['whmcs_invoice'], $note));
			$this->module->repo()->update($row['invoice_id'], array('attention' => $note, 'fingerprint' => $fingerprint));
		}
		$this->settle($row['invoice_id'], $status, $row);
		return 'the WHMCS invoice is gone';
	}

	/**
	 * Polling of the invoice after this state: open — every 5 minutes, paid — never, expired or
	 * cancelled — every 30 minutes until the end of polling: a late payment may come.
	 */
	private function settle($invoiceId, $status, $row) {
		if ($status === 'open') {
			$next = time() + self::POLL_OPEN;
		} elseif ($status === 'paid') {
			$next = null;
		} else {
			$next = time() + self::POLL_CLOSED;
		}
		if ($next !== null && $next >= (int)$row['poll_until']) {
			$next = null;
		}
		$this->module->repo()->update($invoiceId, array('next_poll' => $next));
	}

	/** What the module keeps of an invoice: for the admin panel and the next decision. */
	public static function snapshot($invoice) {
		$copy = array();
		foreach (array('id', 'number', 'status', 'test', 'currency', 'amount', 'amount_paid', 'amount_pending', 'amount_due', 'amount_overpaid', 'pay_url', 'expires_at', 'paid_late', 'paid_manually') as $field) {
			$copy[$field] = isset($invoice[$field]) ? $invoice[$field] : null;
		}
		return $copy;
	}

	private static function logData($invoice, $row) {
		return array(
			'coinspay_invoice' => CoinsPay_Util::value($invoice, 'id'),
			'number' => CoinsPay_Util::value($invoice, 'number'),
			'status' => CoinsPay_Util::value($invoice, 'status'),
			'test' => !empty($invoice['test']) ? 'yes' : 'no',
			'amount' => CoinsPay_Util::value($invoice, 'amount') . ' ' . CoinsPay_Util::value($invoice, 'currency'),
			'paid' => CoinsPay_Util::value($invoice, 'amount_paid'),
			'pending' => CoinsPay_Util::value($invoice, 'amount_pending'),
			'whmcs_amount' => (string)$row['whmcs_amount'],
		);
	}
}
