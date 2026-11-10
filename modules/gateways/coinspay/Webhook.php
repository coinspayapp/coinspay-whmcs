<?php
/**
 * Webhook of CoinsPay: checks the signature, then reads the invoice from the API instead of
 * trusting the event, and applies it. The event is only a reason to look.
 */
defined('WHMCS') or die('This file cannot be accessed directly');

class CoinsPay_Webhook {

	/** @var CoinsPay_Module */
	private $module;

	public function __construct(CoinsPay_Module $module) {
		$this->module = $module;
	}

	/**
	 * @param string   $body   Raw request body.
	 * @param string   $header x-cpay-signature.
	 * @param int|null $now    For tests.
	 * @return array {0: HTTP status, 1: text}. 2xx — CoinsPay stops sending the event.
	 */
	public function handle($body, $header, $now = null) {
		$settings = $this->module->settings();
		// A switched-off gateway has no settings: CoinsPay retries until it is back on.
		if (!$settings->active()) {
			return array(503, 'the CoinsPay gateway is not active');
		}
		if ($settings->webhookSecret() === '' || !CoinsPay_Signature::verify($body, $header, $settings->webhookSecret(), $now)) {
			return array(401, 'bad signature');
		}
		$event = json_decode((string)$body, true);
		if (!is_array($event) || !isset($event['type']) || !is_string($event['type'])) {
			return array(400, 'not an event');
		}
		$repo = $this->module->repo();
		if ($event['type'] === 'webhook.test') {
			$repo->kvSet('webhook_test', (string)time());
			return array(200, 'test event received');
		}
		$data = isset($event['data']) && is_array($event['data']) ? $event['data'] : array();
		$invoiceId = '';
		if (isset($data['invoice']) && is_array($data['invoice'])) {
			$invoiceId = CoinsPay_Util::value($data['invoice'], 'id');
		} elseif (isset($data['deposit']) && is_array($data['deposit'])) {
			$invoiceId = CoinsPay_Util::value($data['deposit'], 'invoice_id');
		}
		$row = $invoiceId !== '' && $repo->ready() ? $repo->find($invoiceId) : null;
		if (!$row) {
			return array(200, 'not an invoice of this WHMCS');
		}
		// Ours, but made with a key of the other mode: the admin switched keys. 503 brings the event
		// again until the key of its mode is back, so a real payment is not dropped.
		if ((bool)$row['test'] !== $settings->isTestMode()) {
			$this->module->log(sprintf('webhook: CoinsPay invoice %s is of the other mode than the key now', $row['invoice_id']), true);
			return array(503, 'the invoice is of the other mode than the key now');
		}

		try {
			$invoice = $this->module->client()->invoice($invoiceId);
			return array(200, $this->module->sync()->apply($invoice));
		} catch (CoinsPay_ApiError $e) {
			$this->module->log('webhook: ' . $row['invoice_id'] . ': ' . $e->summary(), true);
			if ($e->isNotFound()) {
				// Ours, yet unknown to this key: a key of another shop now. CoinsPay sends it again.
				return array(503, 'the invoice is not found for this key');
			}
			return array(503, 'the CoinsPay API is not reachable');
		} catch (Exception $e) {
			$this->module->log('webhook: ' . $e->getMessage(), true);
			return array(500, 'the invoice was not applied');
		}
	}
}
