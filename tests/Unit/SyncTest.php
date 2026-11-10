<?php
/**
 * A payment reaches the WHMCS invoice exactly once, whatever addInvoicePayment does.
 */

use PHPUnit\Framework\TestCase;

final class SyncTest extends TestCase {

	const ID = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

	/** @var FakeHost */
	private $host;

	/** @var MemoryRepo */
	private $repo;

	/** @var CoinsPay_Module */
	private $module;

	protected function setUp(): void {
		$this->host = new FakeHost();
		$this->repo = new MemoryRepo();
		$this->module = new CoinsPay_Module($this->host);
		$this->module->setRepo($this->repo);
		$this->repo->add(array(
			'invoice_id' => self::ID, 'whmcs_invoice' => 42, 'attempt' => 1, 'sent_amount' => '100', 'sent_currency' => 'USD',
			'whmcs_amount' => '100.00', 'whmcs_currency_id' => 1, 'test' => 0, 'test_admin' => 0, 'status' => 'open',
			'pay_url' => '', 'next_poll' => time(), 'poll_until' => time() + 90000,
		));
	}

	private function paid(array $overrides = array()) {
		return $overrides + array(
			'id' => self::ID, 'number' => 12, 'status' => 'paid', 'test' => false, 'currency' => 'USD', 'amount' => '100',
			'amount_paid' => '100', 'amount_pending' => '0', 'amount_due' => '0', 'amount_overpaid' => null,
			'paid_late' => false, 'paid_manually' => false, 'payments' => array(),
		);
	}

	public function test_a_paid_invoice_adds_the_stored_amount_once() {
		$this->expectOutputString('');
		$result = $this->module->sync()->apply($this->paid());
		$this->assertStringStartsWith('apply', $result);
		$this->assertSame(array(self::ID => '100.00'), $this->host->payments);
		$this->assertSame('Paid', $this->host->invoice['status']);
		$this->assertSame('applied', $this->repo->rows[self::ID]['apply_state']);
		$this->assertNull($this->repo->rows[self::ID]['next_poll']);

		$this->assertSame('no change', $this->module->sync()->apply($this->paid()));
		// Another state of the same invoice (a manual mark, say) still adds nothing.
		$this->assertSame('none', $this->module->sync()->apply($this->paid(array('paid_manually' => true))));
		$this->assertCount(1, $this->host->payments);
	}

	public function test_a_failure_before_recording_is_retried() {
		$this->host->fail = 'throw';
		try {
			$this->module->sync()->apply($this->paid());
			$this->fail('no error');
		} catch (RuntimeException $e) {
			$this->assertSame('the invoice could not be saved', $e->getMessage());
		}
		// The claim is released: the next webhook or poll tries again at once.
		$this->assertSame('none', $this->repo->rows[self::ID]['apply_state']);
		$this->assertNull($this->repo->rows[self::ID]['fingerprint']);
		$this->assertSame(array(), $this->host->payments);

		$this->assertStringStartsWith('apply', $this->module->sync()->apply($this->paid()));
		$this->assertCount(1, $this->host->payments);
	}

	public function test_a_failure_after_recording_does_not_add_a_second_payment() {
		$this->expectOutputString('');
		$this->host->fail = 'throw_after';
		try {
			$this->module->sync()->apply($this->paid());
			$this->fail('no error');
		} catch (RuntimeException $e) {
			$this->assertSame('a hook of another module failed', $e->getMessage());
		}
		$this->assertFalse(CoinsPay_Sync::$applying);
		$this->assertCount(1, $this->host->payments);

		$this->assertSame('none', $this->module->sync()->apply($this->paid()));
		$this->assertCount(1, $this->host->payments);
		$this->assertSame('applied', $this->repo->rows[self::ID]['apply_state']);
	}

	public function test_a_payment_whmcs_did_not_record_is_an_error_and_is_retried() {
		$this->host->fail = 'silent';
		try {
			$this->module->sync()->apply($this->paid());
			$this->fail('no error');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('did not record', $e->getMessage());
		}
		$this->assertStringStartsWith('apply', $this->module->sync()->apply($this->paid()));
		$this->assertCount(1, $this->host->payments);
	}

	public function test_a_cancelled_invoice_is_left_to_the_admin() {
		$this->host->invoice['status'] = 'Cancelled';
		$this->assertStringStartsWith('hold', $this->module->sync()->apply($this->paid()));
		$this->assertSame(array(), $this->host->payments);
		$this->assertCount(1, $this->host->activity);
		$this->assertStringContainsString('#42', $this->host->activity[0]);
		$this->assertStringContainsString('"Cancelled"', $this->repo->rows[self::ID]['attention']);
		// The same state again does not repeat the alarm.
		$this->assertSame('no change', $this->module->sync()->apply($this->paid()));
		$this->assertCount(1, $this->host->activity);
	}

	public function test_a_test_payment_is_added_only_when_allowed_with_its_own_transaction_id() {
		$test = $this->paid(array('test' => true));
		$this->repo->update(self::ID, array('test' => 1, 'test_admin' => 1));
		$this->assertStringStartsWith('log', $this->module->sync()->apply($test));
		$this->assertSame(array(), $this->host->payments);

		// The admin turns "Add test payments" on; the next request reads the new settings.
		$this->host->settings['apply_test'] = 'on';
		$this->repo->update(self::ID, array('fingerprint' => null));
		$module = new CoinsPay_Module($this->host);
		$module->setRepo($this->repo);
		$this->assertStringStartsWith('apply', $module->sync()->apply($test));
		$this->assertSame(array('test-' . self::ID => '100.00'), $this->host->payments);
	}

	public function test_a_payment_claimed_by_another_request_is_not_added_again() {
		// Another request is inside addInvoicePayment right now, whatever the named lock did.
		$this->repo->update(self::ID, array('apply_state' => 'applying', 'claimed' => time()));
		try {
			$this->module->sync()->apply($this->paid());
			$this->fail('no error');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('being added by another request', $e->getMessage());
		}
		$this->assertSame(array(), $this->host->payments);

		// That request died without recording: after CLAIM_STALE the claim is taken over.
		$this->repo->update(self::ID, array('claimed' => time() - CoinsPay_Sync::CLAIM_STALE - 1));
		$this->assertStringStartsWith('apply', $this->module->sync()->apply($this->paid()));
		$this->assertCount(1, $this->host->payments);
		$this->assertSame('applied', $this->repo->rows[self::ID]['apply_state']);
		$this->assertNull($this->repo->rows[self::ID]['claimed']);
	}

	public function test_an_invoice_of_another_client_now_is_left_to_the_admin() {
		// Deleted, and its number given to an invoice of another client.
		$this->repo->update(self::ID, array('whmcs_userid' => 8));
		$this->assertStringStartsWith('hold', $this->module->sync()->apply($this->paid()));
		$this->assertSame(array(), $this->host->payments);
		$this->assertStringContainsString('another client', $this->repo->rows[self::ID]['attention']);
	}

	public function test_an_overpayment_adds_the_balance_and_tells_the_admin() {
		$this->assertStringStartsWith('apply', $this->module->sync()->apply($this->paid(array('amount_paid' => '105', 'amount_overpaid' => '5'))));
		$this->assertSame(array(self::ID => '100.00'), $this->host->payments);
		$this->assertCount(1, $this->host->activity);
		$this->assertStringContainsString('Overpaid by 5 USD', $this->repo->rows[self::ID]['attention']);
	}

	public function test_money_for_a_deleted_invoice_is_reported_once() {
		$this->repo->update(self::ID, array('whmcs_invoice' => 43));
		$this->assertSame('the WHMCS invoice is gone', $this->module->sync()->apply($this->paid()));
		$this->assertSame('the WHMCS invoice is gone', $this->module->sync()->apply($this->paid()));
		$this->assertSame(array(), $this->host->payments);
		$this->assertCount(1, $this->host->activity);
		$this->assertStringContainsString('#43 is gone', $this->repo->rows[self::ID]['attention']);
		$this->assertNull($this->repo->rows[self::ID]['next_poll']);
	}

	public function test_an_unknown_invoice_is_not_touched() {
		$this->assertSame('not an invoice of this WHMCS', $this->module->sync()->apply($this->paid(array('id' => '00000000-0000-4000-8000-000000000001'))));
	}
}
