<?php
/**
 * CoinsPay invoice state → what happens to the WHMCS invoice.
 */

use PHPUnit\Framework\TestCase;

final class DecisionTest extends TestCase {

	private function invoice(array $overrides = array()) {
		return $overrides + array(
			'id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7', 'number' => 12, 'status' => 'open', 'test' => false,
			'currency' => 'USD', 'amount' => '100', 'amount_paid' => '0', 'amount_pending' => '0', 'amount_due' => '100',
			'amount_overpaid' => null, 'paid_late' => false, 'paid_manually' => false, 'payments' => array(),
		);
	}

	private function paid(array $overrides = array()) {
		return $this->invoice($overrides + array('status' => 'paid', 'amount_paid' => '100', 'amount_due' => '0',
			'payments' => array(array('deposit_id' => 'd1', 'status' => 'confirmed', 'chain' => 'tron', 'asset' => 'USDT', 'amount' => '100', 'tx_hash' => 'abcdef1234567890abcdef'))));
	}

	private function decide($invoice, array $state = array()) {
		return CoinsPay_Decision::decide($invoice, $state + array('whmcs_status' => 'Unpaid', 'currency_changed' => false, 'applied' => false, 'test_admin' => false, 'apply_test' => false), new CoinsPay_Text('en'));
	}

	public function test_a_paid_invoice_adds_the_payment_to_an_unpaid_one() {
		$decision = $this->decide($this->paid());
		$this->assertSame(CoinsPay_Decision::APPLY, $decision['action']);
		$this->assertTrue($decision['final']);
		$this->assertStringContainsString('100 USD', $decision['note']);
		$this->assertStringContainsString('tron', $decision['note']);
	}

	public function test_late_manual_and_overpaid_are_noted() {
		$decision = $this->decide($this->paid(array('paid_late' => true, 'paid_manually' => true, 'amount_overpaid' => '5')));
		$this->assertSame(CoinsPay_Decision::APPLY, $decision['action']);
		$this->assertStringContainsString('after the CoinsPay invoice expired', $decision['note']);
		$this->assertStringContainsString('manually', $decision['note']);
		$this->assertStringContainsString('Overpaid by 5 USD', $decision['note']);
	}

	public function test_a_payment_is_added_once() {
		$this->assertSame(CoinsPay_Decision::NONE, $this->decide($this->paid(), array('applied' => true))['action']);
	}

	public function test_an_invoice_that_is_no_longer_unpaid_needs_the_admin() {
		foreach (array('Paid', 'Cancelled', 'Refunded', 'Collections', 'Draft', 'Payment Pending') as $status) {
			$decision = $this->decide($this->paid(), array('whmcs_status' => $status));
			$this->assertSame(CoinsPay_Decision::HOLD, $decision['action'], $status);
			$this->assertStringContainsString('"' . $status . '"', $decision['note']);
		}
	}

	public function test_a_changed_client_currency_needs_the_admin() {
		$this->assertSame(CoinsPay_Decision::HOLD, $this->decide($this->paid(), array('currency_changed' => true))['action']);
	}

	public function test_a_test_payment_counts_only_for_an_admin_and_when_allowed() {
		$test = $this->paid(array('test' => true));
		$this->assertSame(CoinsPay_Decision::HOLD, $this->decide($test)['action']);
		$this->assertSame(CoinsPay_Decision::HOLD, $this->decide($test, array('apply_test' => true))['action']);
		$this->assertSame(CoinsPay_Decision::LOG, $this->decide($test, array('test_admin' => true))['action']);
		$this->assertSame(CoinsPay_Decision::APPLY, $this->decide($test, array('test_admin' => true, 'apply_test' => true))['action']);
	}

	public function test_money_on_its_way_and_a_part_are_logged_and_polled_further() {
		$pending = $this->decide($this->invoice(array('amount_pending' => '100')));
		$this->assertSame(CoinsPay_Decision::LOG, $pending['action']);
		$this->assertFalse($pending['final']);
		$partial = $this->decide($this->invoice(array('amount_paid' => '40', 'amount_due' => '60')));
		$this->assertSame(CoinsPay_Decision::LOG, $partial['action']);
		$this->assertStringContainsString('40 USD received, 60 USD left', $partial['note']);
		$this->assertSame(CoinsPay_Decision::NONE, $this->decide($this->invoice())['action']);
	}

	public function test_expired_or_cancelled_with_money_needs_the_admin() {
		foreach (array('expired', 'cancelled') as $status) {
			$decision = $this->decide($this->invoice(array('status' => $status, 'amount_paid' => '30', 'amount_pending' => '10')));
			$this->assertSame(CoinsPay_Decision::HOLD, $decision['action'], $status);
			$this->assertStringContainsString('40 USD of 100 USD', $decision['note']);
			$this->assertSame(CoinsPay_Decision::LOG, $this->decide($this->invoice(array('status' => $status)))['action']);
		}
	}

	public function test_the_fingerprint_follows_the_state_that_matters() {
		$a = CoinsPay_Decision::fingerprint($this->paid());
		$this->assertSame($a, CoinsPay_Decision::fingerprint($this->paid(array('pay_url' => 'changed'))));
		$this->assertNotSame($a, CoinsPay_Decision::fingerprint($this->paid(array('paid_manually' => true))));
		$this->assertNotSame($a, CoinsPay_Decision::fingerprint($this->invoice()));
	}
}
