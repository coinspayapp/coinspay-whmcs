<?php
/**
 * Signed values of the module's links and forms, and the invoice body.
 */

use PHPUnit\Framework\TestCase;

final class TokensTest extends TestCase {

	private function form(array $overrides = array()) {
		return $overrides + array('invoice' => 42, 'balance' => '100.00', 'currency_id' => 1, 'lang' => 'en');
	}

	public function test_the_form_signature_covers_every_field() {
		$tokens = new CoinsPay_Tokens('secret');
		$token = $tokens->form($this->form());
		$this->assertTrue($tokens->checkForm($this->form(), $token));
		foreach (array('invoice' => 43, 'balance' => '1.00', 'currency_id' => 2, 'lang' => 'ru') as $field => $value) {
			$this->assertFalse($tokens->checkForm($this->form(array($field => $value)), $token), $field);
		}
		$this->assertFalse((new CoinsPay_Tokens(''))->checkForm($this->form(), $token));
		$this->assertFalse((new CoinsPay_Tokens('other'))->checkForm($this->form(), $token));
	}

	public function test_the_return_token_is_the_same_every_time() {
		$tokens = new CoinsPay_Tokens('secret');
		$this->assertSame($tokens->returnToken(42), $tokens->returnToken(42));
		$this->assertTrue($tokens->checkReturn(42, $tokens->returnToken(42)));
		$this->assertFalse($tokens->checkReturn(43, $tokens->returnToken(42)));
	}

	public function test_the_token_secret_needs_both_secrets() {
		$this->assertSame('', (new CoinsPay_Settings(array('api_key' => 'cpk_x')))->tokenSecret());
		$a = (new CoinsPay_Settings(array('api_key' => 'cpk_x', 'webhook_secret' => 'whsec_a')))->tokenSecret();
		$b = (new CoinsPay_Settings(array('api_key' => 'cpk_x', 'webhook_secret' => 'whsec_b')))->tokenSecret();
		$this->assertNotSame($a, $b);
	}

	public function test_settings_read_whmcs_values() {
		$settings = new CoinsPay_Settings(array('lifetime' => '5', 'auto_return' => '', 'debug' => 'on'));
		$this->assertSame(15, $settings->lifetime());
		$this->assertFalse($settings->flag('auto_return'));
		$this->assertTrue($settings->flag('debug'));
		$this->assertTrue($settings->flag('test_admin_only'), 'default until the admin saves');
		$this->assertFalse((new CoinsPay_Settings(array()))->flag('apply_test'));
	}

	public function test_the_idempotency_key_follows_the_body() {
		$a = CoinsPay_InvoiceRequest::idempotencyKey('https://whmcs.example/', 42, 1, array('amount' => '100'));
		$this->assertSame($a, CoinsPay_InvoiceRequest::idempotencyKey('https://whmcs.example/', 42, 1, array('amount' => '100')));
		$this->assertNotSame($a, CoinsPay_InvoiceRequest::idempotencyKey('https://whmcs.example/', 42, 1, array('amount' => '101')));
		$this->assertNotSame($a, CoinsPay_InvoiceRequest::idempotencyKey('https://staging.example/', 42, 1, array('amount' => '100')));
		$this->assertLessThanOrEqual(128, strlen($a));
	}

	public function test_the_api_address_is_one_the_key_may_go_to() {
		$this->assertSame('https://pay.example/api/v1', CoinsPay_ApiClient::base('https://pay.example/api/v1/'));
		$this->assertSame('https://pay.example/api/v1', CoinsPay_ApiClient::base('pay.example'));
		$this->assertSame('https://pay.example/api/v1', CoinsPay_ApiClient::base(' https://pay.example/ '));
		$this->assertSame('http://host.docker.internal:3400/api/v1', CoinsPay_ApiClient::base('http://host.docker.internal:3400/api/v1'));
		$this->assertSame('http://127.0.0.1:3400/api/v1', CoinsPay_ApiClient::base('http://127.0.0.1:3400'));
		$this->assertSame('', CoinsPay_ApiClient::base('http://pay.example/api/v1'), 'the key would go in clear text');
		$this->assertSame('', CoinsPay_ApiClient::base('ftp://pay.example'));
		$this->assertSame('', CoinsPay_ApiClient::base('https://user:pw@pay.example'));
		$this->assertSame('', CoinsPay_ApiClient::base('https://pay.example/api/v1?x=1'));
		$this->assertSame('', CoinsPay_ApiClient::base(''));
		$this->assertTrue((new CoinsPay_ApiError(404, 'NOT_FOUND', ''))->isNotFound());
		$this->assertFalse((new CoinsPay_ApiError(404, 'HTTP_404', ''))->isNotFound(), 'a 404 of a proxy or a wrong address');
	}

	public function test_long_texts_are_cut() {
		$this->assertSame('ab…', CoinsPay_Util::truncate('abcdef', 3));
		$this->assertSame('Счё…', CoinsPay_Util::truncate('Счёт № 12', 4));
		$this->assertSame('short', CoinsPay_Util::truncate('short', 10));
	}
}
