<?php
/**
 * What a state of a CoinsPay invoice means for its WHMCS invoice. A pure function: unit tests
 * check every rule without WHMCS.
 *
 * WHMCS keeps partial payments and credit itself, so a paid invoice adds the amount it stood
 * for; the rules from the WooCommerce and OpenCart plugins decide when that is safe.
 */
defined('WHMCS') or die('This file cannot be accessed directly');

class CoinsPay_Decision {

	/** Add the payment to the WHMCS invoice. */
	const APPLY = 'apply';
	/** Money came, but it needs a decision of the admin: logged where the admin sees it. */
	const HOLD = 'hold';
	/** Only a line of the gateway log. */
	const LOG = 'log';
	const NONE = 'none';

	/**
	 * @param array         $invoice CoinsPay invoice from the API.
	 * @param array         $state   {whmcs_status, currency_changed, client_changed, applied, test_admin, apply_test}:
	 *                               the WHMCS invoice now and what the module knows of this CoinsPay invoice.
	 * @param CoinsPay_Text $text    Language of the admin.
	 * @return array {action, note, final}: final — the state no longer changes, polling may stop.
	 */
	public static function decide($invoice, $state, CoinsPay_Text $text) {
		$status = CoinsPay_Util::value($invoice, 'status');
		$number = CoinsPay_Util::value($invoice, 'number');
		$paid = CoinsPay_Util::value($invoice, 'amount_paid');
		$pending = CoinsPay_Util::value($invoice, 'amount_pending');
		$currency = CoinsPay_Util::value($invoice, 'currency');
		$test = !empty($invoice['test']);
		$hasMoney = CoinsPay_Util::positive($paid) || CoinsPay_Util::positive($pending);

		if ($status === 'paid') {
			if (!empty($state['applied'])) {
				return self::result(self::NONE, '', true);
			}
			$amount = CoinsPay_Util::value($invoice, 'amount') . ' ' . $currency;
			if ($test && empty($state['test_admin'])) {
				return self::result(self::HOLD, $text->t('log_test_foreign', $number), true);
			}
			if ($test && empty($state['apply_test'])) {
				return self::result(self::LOG, $text->t('log_test_not_applied', $number), true);
			}
			if (!empty($state['currency_changed'])) {
				return self::result(self::HOLD, $text->t('log_currency_changed', $number, $amount), true);
			}
			if (!empty($state['client_changed'])) {
				return self::result(self::HOLD, $text->t('log_client_changed', $number, $amount), true);
			}
			if ((string)$state['whmcs_status'] !== 'Unpaid') {
				return self::result(self::HOLD, $text->t('log_paid_not_unpaid', $number, $amount, (string)$state['whmcs_status']), true);
			}
			$note = $text->t('log_paid', $number, $amount, self::payments($invoice, $text));
			if (!empty($invoice['paid_manually'])) {
				$note .= ' ' . $text->t('log_paid_manually');
			}
			if (!empty($invoice['paid_late'])) {
				$note .= ' ' . $text->t('log_paid_late');
			}
			if (CoinsPay_Util::positive(CoinsPay_Util::value($invoice, 'amount_overpaid'))) {
				$note .= ' ' . $text->t('log_overpaid', CoinsPay_Util::value($invoice, 'amount_overpaid') . ' ' . $currency);
			}
			return self::result(self::APPLY, $note, true);
		}

		if ($status === 'open') {
			if (CoinsPay_Util::positive($paid)) {
				return self::result(self::LOG, $text->t('log_partial', $number, $paid . ' ' . $currency, CoinsPay_Util::value($invoice, 'amount_due') . ' ' . $currency), false);
			}
			if (CoinsPay_Util::positive($pending)) {
				return self::result(self::LOG, $text->t('log_pending', $number, self::payments($invoice, $text)), false);
			}
			return self::result(self::NONE, '', false);
		}

		if ($status === 'expired' || $status === 'cancelled') {
			if ($hasMoney) {
				$received = CoinsPay_Util::normalize((string)((float)$paid + (float)$pending)) . ' ' . $currency;
				$key = $status === 'expired' ? 'log_expired_partial' : 'log_cancelled_partial';
				return self::result(self::HOLD, $text->t($key, $number, $received, CoinsPay_Util::value($invoice, 'amount') . ' ' . $currency), true);
			}
			return self::result(self::LOG, $text->t($status === 'expired' ? 'log_expired' : 'log_cancelled', $number), true);
		}

		return self::result(self::NONE, '', false);
	}

	/**
	 * What of the invoice changes the decision: equal for repeated webhooks of one state.
	 *
	 * @param array $invoice
	 * @return string
	 */
	public static function fingerprint($invoice) {
		$parts = array();
		foreach (array('status', 'amount_paid', 'amount_pending', 'paid_late', 'paid_manually') as $field) {
			$parts[] = isset($invoice[$field]) ? CoinsPay_Util::json($invoice[$field]) : 'null';
		}
		if (!empty($invoice['payments']) && is_array($invoice['payments'])) {
			foreach ($invoice['payments'] as $payment) {
				$parts[] = CoinsPay_Util::value($payment, 'deposit_id') . ':' . CoinsPay_Util::value($payment, 'status');
			}
		}
		return md5(implode('|', $parts));
	}

	/** Payments in one line: "49.9 USDT (tron, abc…123)". */
	private static function payments($invoice, CoinsPay_Text $text) {
		$lines = array();
		if (!empty($invoice['payments']) && is_array($invoice['payments'])) {
			foreach ($invoice['payments'] as $payment) {
				$hash = CoinsPay_Util::value($payment, 'tx_hash');
				$lines[] = sprintf(
					'%s %s (%s, %s)',
					CoinsPay_Util::value($payment, 'amount'),
					CoinsPay_Util::value($payment, 'asset'),
					CoinsPay_Util::value($payment, 'chain'),
					strlen($hash) > 16 ? substr($hash, 0, 8) . '…' . substr($hash, -6) : $hash
				);
			}
		}
		return $lines ? implode(', ', $lines) : $text->t('log_no_payments');
	}

	private static function result($action, $note, $final) {
		return array('action' => $action, 'note' => $note, 'final' => $final);
	}
}
