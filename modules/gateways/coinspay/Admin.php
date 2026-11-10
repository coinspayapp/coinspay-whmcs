<?php
/**
 * Pages for the admin: the connection check (?a=check) and the panel on an invoice page.
 */
defined('WHMCS') or die('This file cannot be accessed directly');

class CoinsPay_Admin {

	/** @var CoinsPay_Module */
	private $module;

	public function __construct(CoinsPay_Module $module) {
		$this->module = $module;
	}

	/**
	 * ?a=check: the key, its access, the currencies of WHMCS, the webhook.
	 *
	 * @return array {0: HTTP status, 1: HTML}.
	 */
	public function check() {
		$host = $this->module->host();
		$text = $this->module->adminText();
		if ($host->adminId() <= 0) {
			return array(403, self::page($text->t('check_title'), '<p>' . CoinsPay_Util::html($text->t('check_sign_in')) . '</p>'));
		}

		$lines = array();
		$ok = true;
		$this->module->repo()->install();
		$lines[] = array(true, $text->t('check_tables'));

		$settings = $this->module->settings();
		try {
			$shop = $this->module->client()->shop();
			$lines[] = array(true, $text->t('check_shop', CoinsPay_Util::value($shop['shop'], 'name'), $settings->isTestMode() ? $text->t('check_mode_test') : $text->t('check_mode_live')));
			if (isset($shop['key']['access']) && $shop['key']['access'] === 'full') {
				$ok = false;
				$lines[] = array(false, $text->t('check_full_access'));
			}
			$accepted = $this->module->currencies()->load();
			$statement = $host->pdo()->prepare('SELECT code FROM tblcurrencies ORDER BY id');
			CoinsPay_Repo::run($statement, array());
			foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $code) {
				$supported = isset($accepted[strtoupper((string)$code)]);
				$lines[] = array($supported, $text->t($supported ? 'check_currency_ok' : 'check_currency_no', $code));
			}
		} catch (CoinsPay_ApiError $e) {
			$ok = false;
			$lines[] = array(false, $text->t('check_api_error', $e->summary()));
		}

		$test = $this->module->repo()->kvGet('webhook_test');
		$lines[] = array(true, $text->t('check_webhook', $this->module->callbackUrl()));
		$lines[] = array($test !== null, $test !== null ? $text->t('check_webhook_seen', gmdate('Y-m-d H:i', (int)$test) . ' UTC') : $text->t('check_webhook_unseen'));

		$html = '<ul>';
		foreach ($lines as $line) {
			$html .= '<li>' . ($line[0] ? '&#10004; ' : '&#10008; ') . CoinsPay_Util::html($line[1]) . '</li>';
		}
		$html .= '</ul><p><strong>' . CoinsPay_Util::html($ok ? $text->t('check_ok') : $text->t('check_failed')) . '</strong></p>';
		return array(200, self::page($text->t('check_title'), $html));
	}

	/**
	 * Panel on the admin page of an invoice.
	 *
	 * @param int $whmcsInvoice
	 * @return string HTML; empty when the invoice has no CoinsPay invoices.
	 */
	public function panel($whmcsInvoice) {
		$rows = $this->module->repo()->forInvoice($whmcsInvoice);
		if (!$rows) {
			return '';
		}
		$text = $this->module->adminText();
		$html = '<div class="panel panel-default"><div class="panel-heading">CoinsPay</div><div class="panel-body"><table class="table table-condensed">';
		$html .= '<tr><th>' . CoinsPay_Util::html($text->t('panel_invoice')) . '</th><th>' . CoinsPay_Util::html($text->t('panel_status')) . '</th><th>' . CoinsPay_Util::html($text->t('panel_amount')) . '</th><th>' . CoinsPay_Util::html($text->t('panel_paid')) . '</th></tr>';
		foreach ($rows as $row) {
			$snapshot = json_decode((string)$row['snapshot'], true);
			$html .= '<tr><td><code>' . CoinsPay_Util::html($row['invoice_id']) . '</code>' . (!empty($row['test']) ? ' (test)' : '') . '</td>'
				. '<td>' . CoinsPay_Util::html($row['status']) . ($row['apply_state'] === 'applied' ? ' &#10004;' : '') . '</td>'
				. '<td>' . CoinsPay_Util::html($row['sent_amount'] . ' ' . $row['sent_currency']) . '</td>'
				. '<td>' . CoinsPay_Util::html(CoinsPay_Util::value($snapshot, 'amount_paid')) . '</td></tr>';
			if ((string)$row['attention'] !== '') {
				$html .= '<tr><td colspan="4" class="text-danger">' . CoinsPay_Util::html($row['attention']) . '</td></tr>';
			}
		}
		return $html . '</table></div></div>';
	}

	/** A plain page of its own: ?a=check is not a page of the WHMCS admin area. */
	public static function page($title, $body) {
		return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . CoinsPay_Util::html($title) . '</title>'
			. '<style>body{font:15px/1.5 system-ui,sans-serif;max-width:720px;margin:40px auto;padding:0 16px}li{margin:4px 0}</style></head>'
			. '<body><h1>' . CoinsPay_Util::html($title) . '</h1>' . $body . '</body></html>';
	}
}
