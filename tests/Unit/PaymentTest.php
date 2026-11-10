<?php
/**
 * "Pay Now": the signed form, its checks against the invoice now, reuse and replacement of
 * CoinsPay invoices.
 */

use PHPUnit\Framework\TestCase;

final class PaymentTest extends TestCase {

	/** @var FakeHost */
	private $host;

	/** @var MemoryRepo */
	private $repo;

	/** @var FakeClient */
	private $client;

	/** @var CoinsPay_Payment */
	private $payment;

	protected function setUp(): void {
		$this->host = new FakeHost();
		$this->repo = new MemoryRepo();
		$this->client = new FakeClient();
		$module = new CoinsPay_Module($this->host);
		$module->setRepo($this->repo);
		$module->setClient($this->client);
		$this->payment = new CoinsPay_Payment($module);
	}

	private function form(array $params = array()) {
		return $this->payment->form($params + array('invoiceid' => 42, 'amount' => '100.00', 'currency' => 'USD'));
	}

	private function freshPayment() {
		$module = new CoinsPay_Module($this->host);
		$module->setRepo($this->repo);
		$module->setClient($this->client);
		$this->payment = new CoinsPay_Payment($module);
	}

	public function test_the_form_takes_the_client_to_the_payment_page() {
		$result = $this->payment->start($this->form());
		$this->assertSame('https://pay.example/pay/00000000-0000-4000-8000-000000000001?lang=en', $result['redirect']);
		list($body, $key) = $this->client->created[0];
		$this->assertSame('USD', $body['currency']);
		$this->assertSame('100', $body['amount']);
		$this->assertSame('42', $body['reference']);
		$this->assertSame('Invoice #42', $body['description']);
		$this->assertSame(60, $body['lifetime_minutes']);
		$this->assertTrue($body['auto_return']);
		$this->assertStringStartsWith('https://whmcs.example/modules/gateways/callback/coinspay.php?a=return&invoice=42&cp_sig=', $body['return_url']);
		$this->assertArrayNotHasKey('title', $body);
		$this->assertMatchesRegularExpression('/^whmcs-[0-9a-f]{8}-42-1-[0-9a-f]{12}$/', $key);

		$row = $this->repo->rows['00000000-0000-4000-8000-000000000001'];
		$this->assertSame('100.00', $row['whmcs_amount']);
		$this->assertSame(1, $row['whmcs_currency_id']);
	}

	public function test_a_repeated_pay_now_reuses_the_open_invoice() {
		$this->payment->start($this->form());
		$this->payment->start($this->form());
		$this->assertCount(1, $this->client->created);
	}

	public function test_a_converted_amount_goes_to_coinspay_and_the_balance_to_whmcs() {
		// Invoice in RUB, "Convert To For Processing" set to USD: 9000 / 92.5 = 97.297…
		$this->host->invoice = array('balance' => '9000.00', 'currency_id' => 2, 'currency_code' => 'RUB') + $this->host->invoice;
		$this->host->settings['convertto'] = '1';
		$this->freshPayment();
		$this->payment->start($this->form());
		$this->assertSame('USD', $this->client->created[0][0]['currency']);
		$this->assertSame('97.3', $this->client->created[0][0]['amount']);
		$this->assertSame('9000.00', $this->repo->rows['00000000-0000-4000-8000-000000000001']['whmcs_amount']);
	}

	public function test_the_amount_is_counted_not_taken_from_the_form() {
		// A form kept from the day of a better rate, or edited by hand: its amount means nothing.
		$form = $this->form() + array('amount' => '1.00', 'currency' => 'EUR');
		$this->payment->start($form);
		$this->assertSame('100', $this->client->created[0][0]['amount']);
		$this->assertSame('USD', $this->client->created[0][0]['currency']);
	}

	public function test_a_forged_or_stale_form_goes_nowhere() {
		$form = $this->form();
		$form['balance'] = '1.00';
		$this->assertArrayHasKey('error', $this->payment->start($form));

		$form = $this->form();
		$this->host->invoice['balance'] = '150.00';
		$this->assertSame(array('redirect' => 'https://whmcs.example/viewinvoice.php?id=42'), $this->payment->start($form));

		$form = $this->form();
		$this->host->invoice['status'] = 'Paid';
		$this->assertSame(array('redirect' => 'https://whmcs.example/viewinvoice.php?id=42'), $this->payment->start($form));
		$this->assertCount(0, $this->client->created);
	}

	public function test_a_test_key_works_for_an_admin_only() {
		$this->host->settings['api_key'] = 'cpk_test_key';
		$form = $this->form();
		$this->assertArrayHasKey('error', $this->payment->start($form));
		$this->host->admin = 1;
		$this->assertArrayHasKey('redirect', $this->payment->start($form));
		$this->assertSame(1, $this->repo->rows['00000000-0000-4000-8000-000000000001']['test_admin']);
	}

	public function test_a_new_sum_replaces_the_open_invoice_and_cancels_it() {
		$this->payment->start($this->form());
		$this->host->invoice['balance'] = '120.00';
		$this->payment->start($this->form(array('amount' => '120.00')));
		$this->assertCount(2, $this->client->created);
		$this->assertSame(array('00000000-0000-4000-8000-000000000001'), $this->client->cancelled);
		$this->assertSame(2, $this->repo->rows['00000000-0000-4000-8000-000000000002']['attempt']);
	}

	public function test_a_currency_coinspay_does_not_take_is_explained() {
		$this->host->invoice['currency_code'] = 'XYZ';
		$result = $this->payment->start($this->form());
		$this->assertStringContainsString('XYZ', $result['error']);
	}

	public function test_an_invoice_moved_to_another_gateway_goes_back_to_its_page() {
		$form = $this->form();
		$this->host->invoice['paymentmethod'] = 'banktransfer';
		$this->assertSame(array('redirect' => 'https://whmcs.example/viewinvoice.php?id=42'), $this->payment->start($form));
		$this->assertCount(0, $this->client->created);
	}

	public function test_a_test_invoice_of_an_admin_is_not_handed_to_a_client() {
		$this->host->settings['api_key'] = 'cpk_test_key';
		$this->host->settings['test_admin_only'] = '';
		$this->freshPayment();
		$this->host->admin = 1;
		$this->payment->start($this->form());
		$this->host->admin = 0;
		$result = $this->payment->start($this->form());
		$this->assertSame('https://pay.example/pay/00000000-0000-4000-8000-000000000002?lang=en', $result['redirect']);
		$this->assertSame(0, $this->repo->rows['00000000-0000-4000-8000-000000000002']['test_admin']);
		$this->assertSame(array('00000000-0000-4000-8000-000000000001'), $this->client->cancelled);
	}
}
