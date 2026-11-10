<?php
/**
 * Settings that have a default when the admin leaves them empty, and the module listing of WHMCS.
 */

use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	public function test_an_empty_api_address_means_coinspay_app() {
		foreach (array(array(), array('api_url' => ''), array('api_url' => '  ')) as $values) {
			$this->assertSame('https://coinspay.app', (new CoinsPay_Settings($values))->apiUrl());
		}
		$this->assertSame('https://pay.example/api/v1', (new CoinsPay_Settings(array('api_url' => ' https://pay.example/api/v1/ ')))->apiUrl());
	}

	public function test_the_api_client_of_the_default_address_goes_to_api_v1() {
		$this->assertSame('https://coinspay.app/api/v1', CoinsPay_ApiClient::base((new CoinsPay_Settings(array()))->apiUrl()));
	}

	public function test_the_button_text_is_empty_until_the_admin_sets_it() {
		$this->assertSame('', (new CoinsPay_Settings(array()))->buttonText());
		$this->assertSame('Pay with crypto', (new CoinsPay_Settings(array('button_text' => ' Pay with crypto ')))->buttonText());
	}

	public function test_the_settings_list_the_button_text() {
		require_once dirname(dirname(__DIR__)) . '/modules/gateways/coinspay.php';
		$config = coinspay_config();
		$this->assertSame('text', $config['button_text']['Type']);
		$this->assertArrayNotHasKey('Default', $config['api_url']);
	}

	public function test_whmcs_json_describes_the_module_and_its_logo() {
		$dir = dirname(dirname(__DIR__)) . '/modules/gateways/coinspay';
		$json = json_decode((string) file_get_contents($dir . '/whmcs.json'), true);
		$this->assertIsArray($json);
		$this->assertSame('whmcs-gateways', $json['type']);
		$this->assertSame('coinspay', $json['name']);
		$this->assertFileExists($dir . '/' . $json['logo']['filename']);
	}
}
