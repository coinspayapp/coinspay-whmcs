<?php
/**
 * The webhook: signature, mode, unknown invoices, and the answers CoinsPay acts on.
 */

use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase {

	const ID = '00000000-0000-4000-8000-000000000001';

	/** @var FakeHost */
	private $host;

	/** @var FakeClient */
	private $client;

	/** @var CoinsPay_Webhook */
	private $webhook;

	/** @var MemoryRepo */
	private $repo;

	private function moduleWith(FakeHost $host) {
		$module = new CoinsPay_Module($host);
		$module->setRepo($this->repo);
		$module->setClient($this->client);
		return $module;
	}

	protected function setUp(): void {
		$this->host = new FakeHost();
		$repo = $this->repo = new MemoryRepo();
		$this->client = new FakeClient();
		$module = new CoinsPay_Module($this->host);
		$module->setRepo($repo);
		$module->setClient($this->client);
		$this->webhook = new CoinsPay_Webhook($module);

		$payment = new CoinsPay_Payment($module);
		$payment->start($payment->form(array('invoiceid' => 42, 'amount' => '100.00', 'currency' => 'USD')));
	}

	private function event($type, $test = false, $id = self::ID) {
		return json_encode(array('id' => 'e1', 'type' => $type, 'created_at' => '2026-10-29T00:00:00.000Z', 'shop_id' => 's', 'test' => $test, 'data' => array('invoice' => array('id' => $id, 'status' => 'paid'))));
	}

	private function send($body, $secret = 'whsec_test', $time = null) {
		$time = $time === null ? time() : $time;
		return $this->webhook->handle($body, 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $body, $secret));
	}

	public function test_a_paid_invoice_from_the_api_is_applied_not_the_event() {
		// The event says paid; the API still says open: nothing is added.
		$this->assertSame(array(200, 'none'), $this->send($this->event('invoice.paid')));
		$this->assertSame(array(), $this->host->payments);

		$this->client->invoices[self::ID] = array('status' => 'paid', 'amount_paid' => '100', 'amount_due' => '0') + $this->client->invoices[self::ID];
		$answer = $this->send($this->event('invoice.paid'));
		$this->assertSame(200, $answer[0]);
		$this->assertCount(1, $this->host->payments);
	}

	public function test_bad_signatures_are_refused() {
		$body = $this->event('invoice.paid');
		$this->assertSame(401, $this->webhook->handle($body, '')[0]);
		$this->assertSame(401, $this->send($body, 'whsec_other')[0]);
		$this->assertSame(401, $this->send($body, 'whsec_test', time() - 400)[0]);
		$this->assertSame(401, $this->webhook->handle(str_replace('paid', 'open', $body), 't=' . time() . ',v1=' . hash_hmac('sha256', time() . '.' . $body, 'whsec_test'))[0]);
	}

	public function test_other_events_are_accepted_and_left_alone() {
		$this->assertSame(array(200, 'test event received'), $this->send($this->event('webhook.test')));
		$this->assertSame(array(200, 'not an invoice of this WHMCS'), $this->send($this->event('invoice.paid', false, '00000000-0000-4000-8000-000000000999')));
		$this->assertSame(400, $this->send('[1,2]')[0]);
	}

	public function test_a_switched_key_or_a_404_makes_coinspay_retry() {
		// The invoice was made with the live key; the admin put a test key in since.
		$this->host->settings['api_key'] = 'cpk_test_key';
		$this->webhook = new CoinsPay_Webhook($this->moduleWith($this->host));
		$this->assertSame(503, $this->send($this->event('invoice.paid'))[0]);

		$this->host->settings['api_key'] = 'cpk_live_key';
		$this->webhook = new CoinsPay_Webhook($this->moduleWith($this->host));
		unset($this->client->invoices[self::ID]);
		$this->assertSame(array(503, 'the invoice is not found for this key'), $this->send($this->event('invoice.paid')));
		$this->assertSame(array(), $this->host->payments);
	}

	public function test_a_switched_off_gateway_asks_coinspay_to_retry() {
		$this->host->settings['type'] = '';
		$this->webhook = new CoinsPay_Webhook(new CoinsPay_Module($this->host));
		$this->assertSame(503, $this->send($this->event('invoice.paid'))[0]);
	}
}
