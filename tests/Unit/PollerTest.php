<?php
/**
 * The invoice page checks its CoinsPay invoice when the webhook and the cron did not bring it:
 * a paid invoice whose payment failed halfway too, a settled one never.
 */

use PHPUnit\Framework\TestCase;

final class PollerTest extends TestCase {

	const ID = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

	/** @var FakeHost */
	private $host;

	/** @var MemoryRepo */
	private $repo;

	/** @var FakeClient */
	private $client;

	/** @var CoinsPay_Module */
	private $module;

	protected function setUp(): void {
		$this->host = new FakeHost();
		$this->repo = new MemoryRepo();
		$this->client = new FakeClient();
		$this->module = new CoinsPay_Module($this->host);
		$this->module->setRepo($this->repo);
		$this->module->setClient($this->client);
		$this->client->invoices[self::ID] = array(
			'id' => self::ID, 'number' => 12, 'status' => 'paid', 'test' => false, 'currency' => 'USD', 'amount' => '100',
			'amount_paid' => '100', 'amount_pending' => '0', 'amount_due' => '0', 'amount_overpaid' => null,
			'paid_late' => false, 'paid_manually' => false, 'payments' => array(),
		);
	}

	private function row(array $columns) {
		$this->repo->add($columns + array(
			'invoice_id' => self::ID, 'whmcs_invoice' => 42, 'attempt' => 1, 'sent_amount' => '100', 'sent_currency' => 'USD',
			'whmcs_amount' => '100.00', 'whmcs_currency_id' => 1, 'test' => 0, 'test_admin' => 0, 'status' => 'open',
			'pay_url' => '', 'next_poll' => time(), 'poll_until' => time() + 90000,
		));
	}

	public function test_a_paid_invoice_whose_payment_failed_is_checked_again() {
		// The webhook got "paid" in, then addInvoicePayment failed: the row still waits for a poll.
		$this->row(array('status' => 'paid', 'apply_state' => 'applying'));
		$poller = new CoinsPay_Poller($this->module);
		$poller->checkInvoice(42);
		$this->assertSame(1, $this->client->reads);
		$this->assertSame(array(self::ID => '100.00'), $this->host->payments);
		$this->assertNull($this->repo->rows[self::ID]['next_poll']);
	}

	public function test_a_settled_paid_invoice_is_not_checked() {
		$this->row(array('status' => 'paid', 'apply_state' => 'applied', 'next_poll' => null));
		$poller = new CoinsPay_Poller($this->module);
		$poller->checkInvoice(42);
		$this->assertSame(0, $this->client->reads);
	}

	public function test_an_invoice_the_key_cannot_see_stays_in_polling() {
		// Made with the test key; the live key is in now. Not asked, not dropped.
		$this->row(array('test' => 1, 'next_poll' => time() - 10));
		$poller = new CoinsPay_Poller($this->module);
		$this->assertSame(1, $poller->run());
		$this->assertSame(0, $this->client->reads);
		$this->assertGreaterThan(time() + 1000, $this->repo->rows[self::ID]['next_poll']);

		// Of this mode, but unknown to the key (another shop now): asked later again.
		$this->repo->update(self::ID, array('test' => 0, 'next_poll' => time() - 10));
		unset($this->client->invoices[self::ID]);
		$poller->run();
		$this->assertSame(1, $this->client->reads);
		$this->assertGreaterThan(time() + 1000, $this->repo->rows[self::ID]['next_poll']);
	}

	public function test_an_open_invoice_is_checked_at_most_once_a_minute() {
		$this->row(array());
		$this->client->invoices[self::ID]['status'] = 'open';
		$poller = new CoinsPay_Poller($this->module);
		$poller->checkInvoice(42);
		$poller->checkInvoice(42);
		$this->assertSame(1, $this->client->reads);
	}
}
