<?php
/**
 * Paying a WHMCS invoice: the signed form of _link, then ?a=pay makes or reuses a CoinsPay
 * invoice and sends the buyer to its payment page.
 */
defined('WHMCS') or die('This file cannot be accessed directly');

class CoinsPay_Payment {

	/** A closed invoice is polled one more day for a late payment. */
	const POLL_AFTER_CLOSE = 86400;

	/** @var CoinsPay_Module */
	private $module;

	/** @var string Currency of the CoinsPay invoice being made, for the error message. */
	private $currency = '';

	public function __construct(CoinsPay_Module $module) {
		$this->module = $module;
	}

	/**
	 * Fields of the pay form: the invoice as the page sees it, signed. No side effects: _link
	 * draws the form on the invoice page, after checkout and maybe in emails.
	 *
	 * @param array $params $params of _link.
	 * @return array|null Null when the invoice is not found.
	 */
	public function form($params) {
		$invoice = $this->module->host()->invoice((int)$params['invoiceid']);
		if (!$invoice) {
			return null;
		}
		$fields = array(
			'invoice' => (int)$invoice['id'],
			// What the payment adds to the invoice: its balance in the invoice currency.
			'balance' => (string)$invoice['balance'],
			'currency_id' => (int)$invoice['currency_id'],
			'lang' => $this->module->host()->language((int)$invoice['userid']),
		);
		$fields[CoinsPay_Tokens::FIELD] = $this->module->tokens()->form($fields);
		return $fields;
	}

	/**
	 * ?a=pay: the form came back.
	 *
	 * @param array $post
	 * @return array {redirect} or {error, back}.
	 */
	public function start($post) {
		$fields = array();
		foreach (array('invoice', 'balance', 'currency_id', 'lang', CoinsPay_Tokens::FIELD) as $field) {
			$fields[$field] = isset($post[$field]) && is_scalar($post[$field]) ? (string)$post[$field] : '';
		}
		$invoiceId = (int)$fields['invoice'];
		$back = $this->module->host()->systemUrl() . 'viewinvoice.php?id=' . $invoiceId;
		$lang = $fields['lang'] === 'ru' ? 'ru' : 'en';
		$text = new CoinsPay_Text($lang);
		$settings = $this->module->settings();

		if ($invoiceId <= 0 || !$this->module->tokens()->checkForm($fields, $fields[CoinsPay_Tokens::FIELD])) {
			return array('error' => $text->t('error_link'), 'back' => $back);
		}
		if (!$settings->active() || $settings->apiUrl() === '' || $settings->apiKey() === '') {
			return array('error' => $text->t('error_unavailable'), 'back' => $back);
		}
		if ($settings->isTestMode() && $settings->flag('test_admin_only') && $this->module->host()->adminId() <= 0) {
			return array('error' => $text->t('error_unavailable'), 'back' => $back);
		}

		$repo = $this->module->repo();
		$repo->install();
		if (!$repo->lock($invoiceId)) {
			return array('error' => $text->t('error_busy'), 'back' => $back);
		}
		try {
			return $this->startLocked($fields, $back, $lang, $text);
		} catch (CoinsPay_ApiError $e) {
			$this->module->log('invoice #' . $invoiceId . ': ' . $e->summary(), true);
			$key = $e->errorCode() === 'CURRENCY_NOT_SUPPORTED' ? 'error_currency' : 'error_api';
			return array('error' => $text->t($key, $this->currency), 'back' => $back);
		} finally {
			$repo->unlock($invoiceId);
		}
	}

	private function startLocked($fields, $back, $lang, CoinsPay_Text $text) {
		$host = $this->module->host();
		$repo = $this->module->repo();
		$settings = $this->module->settings();
		$invoiceId = (int)$fields['invoice'];

		// The invoice now, not as the form saw it: paid meanwhile, its sum or the client's currency
		// changed, moved to another gateway — back to the invoice page, which draws a fresh form.
		$whmcs = $host->invoice($invoiceId);
		if (!$whmcs || $whmcs['status'] !== 'Unpaid' || $whmcs['paymentmethod'] !== CoinsPay_WhmcsHost::GATEWAY
			|| $whmcs['balance'] !== $fields['balance'] || (int)$whmcs['currency_id'] !== (int)$fields['currency_id']) {
			return array('redirect' => $back);
		}

		$charge = $this->charge($whmcs);
		$currency = $this->currency = $charge['currency'];
		$amount = CoinsPay_Util::formatAmount($charge['amount'], $this->module->currencies()->scale($currency));
		$test = $settings->isTestMode();
		$admin = $host->adminId() > 0;
		$client = $this->module->client();

		$current = $repo->current($invoiceId);
		// A test invoice of an admin is not handed to a client: its payment would be added.
		if ($current && $current['status'] === 'open' && $current['sent_amount'] === $amount && $current['sent_currency'] === $currency
			&& (bool)$current['test'] === $test && (!$test || (bool)$current['test_admin'] === $admin)
			&& $current['whmcs_amount'] === $whmcs['balance'] && (int)$current['whmcs_currency_id'] === (int)$whmcs['currency_id']
			&& (int)$current['whmcs_userid'] === (int)$whmcs['userid']) {
			try {
				$invoice = $client->invoice($current['invoice_id']);
				if (CoinsPay_Util::value($invoice, 'status') === 'open') {
					return array('redirect' => self::withLang(CoinsPay_Util::value($invoice, 'pay_url'), $lang));
				}
				// Paid or closed meanwhile: its state goes to WHMCS, and a new invoice is made if still due.
				$this->module->sync()->apply($invoice);
				$whmcs = $host->invoice($invoiceId);
				if (!$whmcs || $whmcs['status'] !== 'Unpaid') {
					return array('redirect' => $back);
				}
			} catch (CoinsPay_ApiError $e) {
				if (!$e->isNotFound()) {
					throw $e;
				}
			}
		}

		$attempt = count($repo->forInvoice($invoiceId)) + 1;
		$body = CoinsPay_InvoiceRequest::body(
			array(
				'number' => (string)$invoiceId,
				'currency' => $currency,
				'amount' => $amount,
				'description' => $text->t('invoice_description', $invoiceId),
				'return_url' => $this->module->callbackUrl(array('a' => 'return', 'invoice' => $invoiceId, CoinsPay_Tokens::FIELD => $this->module->tokens()->returnToken($invoiceId))),
			),
			$settings
		);
		$invoice = $client->createInvoice($body, CoinsPay_InvoiceRequest::idempotencyKey($host->systemUrl(), $invoiceId, $attempt, $body));
		if (CoinsPay_Util::value($invoice, 'status') !== 'open' && !$repo->find(CoinsPay_Util::value($invoice, 'id'))) {
			// The key met an invoice made earlier and closed since — the tables were wiped, say. One more attempt.
			$attempt++;
			$invoice = $client->createInvoice($body, CoinsPay_InvoiceRequest::idempotencyKey($host->systemUrl(), $invoiceId, $attempt, $body));
		}

		$invoiceUuid = CoinsPay_ApiClient::id(CoinsPay_Util::value($invoice, 'id'));
		if (!$repo->find($invoiceUuid)) {
			$repo->add(array(
				'invoice_id' => $invoiceUuid,
				'whmcs_invoice' => $invoiceId,
				'whmcs_userid' => (int)$whmcs['userid'],
				'attempt' => $attempt,
				'sent_amount' => $amount,
				'sent_currency' => $currency,
				'whmcs_amount' => $whmcs['balance'],
				'whmcs_currency_id' => (int)$whmcs['currency_id'],
				'test' => !empty($invoice['test']) ? 1 : 0,
				'test_admin' => $admin ? 1 : 0,
				'status' => CoinsPay_Util::value($invoice, 'status'),
				'pay_url' => CoinsPay_Util::value($invoice, 'pay_url'),
				'snapshot' => CoinsPay_Util::json(CoinsPay_Sync::snapshot($invoice)),
				'next_poll' => time() + CoinsPay_Sync::POLL_OPEN,
				'poll_until' => time() + $settings->lifetime() * 60 + self::POLL_AFTER_CLOSE,
			));
			$this->module->log(sprintf('invoice #%d: CoinsPay invoice %s for %s %s', $invoiceId, $invoiceUuid, $amount, $currency));
		}

		$this->cancelReplaced($invoiceId, $invoiceUuid);
		return array('redirect' => self::withLang(CoinsPay_Util::value($invoice, 'pay_url'), $lang));
	}

	/**
	 * What the client pays, counted here and never taken from the form: the balance, converted to
	 * the currency of "Convert To For Processing" by the rates of WHMCS as convertCurrency() does.
	 *
	 * @param array $whmcs The WHMCS invoice.
	 * @return array {amount, currency}
	 */
	public function charge($whmcs) {
		$charge = array('amount' => (string)$whmcs['balance'], 'currency' => strtoupper((string)$whmcs['currency_code']));
		$to = $this->module->settings()->convertTo();
		if ($to <= 0 || $to === (int)$whmcs['currency_id']) {
			return $charge;
		}
		$from = $this->module->host()->currency((int)$whmcs['currency_id']);
		$target = $this->module->host()->currency($to);
		if (!$from || !$target || (float)$from['rate'] <= 0) {
			return $charge;
		}
		$amount = round((float)$whmcs['balance'] / (float)$from['rate'] * (float)$target['rate'], 2);
		return array('amount' => number_format($amount, 2, '.', ''), 'currency' => $target['code']);
	}

	/**
	 * Cancels the open CoinsPay invoices of a WHMCS invoice that hold no money: replaced by a new
	 * one, or not wanted any more — the WHMCS invoice was cancelled, changed or paid otherwise.
	 * CoinsPay cancels an open invoice even with a payment on its way, so each one is read fresh
	 * first; a payment that still lands in the gap is held for the admin.
	 *
	 * @param int    $invoiceId
	 * @param string $keep      CoinsPay invoice to leave open; '' cancels all.
	 */
	public function cancelReplaced($invoiceId, $keep = '') {
		foreach ($this->module->repo()->forInvoice($invoiceId) as $row) {
			if ($row['invoice_id'] === $keep || $row['status'] !== 'open') {
				continue;
			}
			try {
				$invoice = $this->module->client()->invoice($row['invoice_id']);
				$hasMoney = CoinsPay_Util::positive(CoinsPay_Util::value($invoice, 'amount_paid')) || CoinsPay_Util::positive(CoinsPay_Util::value($invoice, 'amount_pending'));
				if (CoinsPay_Util::value($invoice, 'status') === 'open' && !$hasMoney) {
					$invoice = $this->module->client()->cancelInvoice($row['invoice_id']);
				}
				$this->module->sync()->apply($invoice);
			} catch (CoinsPay_ApiError $e) {
				// Money came meanwhile (INVOICE_NOT_OPEN) or the API is away: its real state comes with polling.
				$this->module->log(sprintf('invoice #%d: CoinsPay invoice %s not cancelled: %s', $invoiceId, $row['invoice_id'], $e->summary()), !in_array($e->status(), array(404, 409), true));
			} catch (RuntimeException $e) {
				// Busy, or a payment WHMCS did not record: the next webhook or poll applies it.
				$this->module->log(sprintf('invoice #%d: CoinsPay invoice %s: %s', $invoiceId, $row['invoice_id'], $e->getMessage()), true);
			}
		}
	}

	/** The payment page in the buyer's language. */
	public static function withLang($payUrl, $lang) {
		if ($payUrl === '') {
			return '';
		}
		return $payUrl . (strpos($payUrl, '?') === false ? '?' : '&') . 'lang=' . ($lang === 'ru' ? 'ru' : 'en');
	}
}
