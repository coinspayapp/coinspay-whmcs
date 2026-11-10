<?php
// Texts of the CoinsPay module for WHMCS. The keys are the same in ru.php.
defined('WHMCS') or die('This file cannot be accessed directly');

return array(
	// Gateway log and activity log, in the default language of WHMCS.
	'log_paid' => 'CoinsPay invoice No. %1$s is paid: %2$s (%3$s). The payment is added to the invoice.',
	'log_paid_manually' => 'It was marked as paid manually in the CoinsPay dashboard.',
	'log_paid_late' => 'The payment came after the CoinsPay invoice expired.',
	'log_overpaid' => 'Overpaid by %s: refund the excess from the CoinsPay dashboard or keep it.',
	'log_paid_not_unpaid' => 'CoinsPay invoice No. %1$s is paid (%2$s), but the invoice is "%3$s" now, so the payment is not added. Add it by hand or refund the client from the CoinsPay dashboard.',
	'log_currency_changed' => 'CoinsPay invoice No. %1$s is paid (%2$s), but the currency of the client changed since, so the payment is not added. Add it by hand in the invoice currency.',
	'log_client_changed' => 'CoinsPay invoice No. %1$s is paid (%2$s), but the invoice belongs to another client now, so the payment is not added. Add it by hand or refund the client from the CoinsPay dashboard.',
	'log_invoice_gone' => 'CoinsPay invoice No. %1$s holds %2$s, but WHMCS invoice #%3$d is gone, so the payment is not added. Refund the client from the CoinsPay dashboard or add the payment by hand.',
	'log_test_foreign' => 'CoinsPay invoice No. %s got a test payment, but it was not started by a signed-in admin: no money came and the payment is not added. If clients pay already, replace the test API key with a live one.',
	'log_test_not_applied' => 'CoinsPay invoice No. %s got a test payment: no money came. It is not added to the invoice; turn on "Add test payments" in the gateway settings to test the whole way.',
	'log_partial' => 'CoinsPay invoice No. %1$s is partially paid: %2$s received, %3$s left. The client can pay the rest on the same payment page.',
	'log_pending' => 'CoinsPay invoice No. %1$s: the payment is on its way and waits for network confirmations (%2$s).',
	'log_expired_partial' => 'CoinsPay invoice No. %1$s expired partially paid: %2$s of %3$s. Add the received part by hand or refund the client from the CoinsPay dashboard.',
	'log_cancelled_partial' => 'CoinsPay invoice No. %1$s was cancelled partially paid: %2$s of %3$s. Add the received part by hand or refund the client from the CoinsPay dashboard.',
	'log_expired' => 'CoinsPay invoice No. %s expired unpaid. The client can press "Pay Now" again.',
	'log_cancelled' => 'CoinsPay invoice No. %s was cancelled.',
	'log_no_payments' => 'no payment details',

	// For the client.
	'invoice_description' => 'Invoice #%d',
	'link_unavailable' => 'Payment in cryptocurrency is not available right now. Please choose another payment method or contact us.',
	'error_link' => 'This payment link is out of date. Open the invoice again and press "Pay Now".',
	'error_unavailable' => 'Payment in cryptocurrency is not available right now. Please choose another payment method or contact us.',
	'error_busy' => 'The invoice is being processed. Please try again in a few seconds.',
	'error_currency' => 'Payment in cryptocurrency is not available for prices in %s. Please choose another payment method or contact us.',
	'error_api' => 'The payment page could not be opened. Please try again in a minute.',
	'back_to_invoice' => 'Back to the invoice',

	// Connection check and the invoice panel, for the admin.
	'check_title' => 'CoinsPay: connection check',
	'check_sign_in' => 'Sign in to the WHMCS admin area first, then open this page again.',
	'check_tables' => 'The tables of the module are in place.',
	'check_shop' => 'The API key works: shop "%1$s", %2$s.',
	'check_mode_test' => 'test mode: invoices are paid without money',
	'check_mode_live' => 'live mode',
	'check_full_access' => 'The key has full access: it can also send payouts. WHMCS needs only "Invoices only": issue such a key in the CoinsPay dashboard, put it here and revoke this one.',
	'check_currency_ok' => 'CoinsPay accepts prices in %s.',
	'check_currency_no' => 'CoinsPay does not accept prices in %s: set "Convert To For Processing" of the gateway to an accepted currency.',
	'check_api_error' => 'The CoinsPay API answered with an error: %s',
	'check_webhook' => 'Webhook address for the CoinsPay dashboard: %s',
	'check_webhook_seen' => 'The last test event from the dashboard came at %s.',
	'check_webhook_unseen' => 'No test event came yet: press "Send test event" on the webhook page of the CoinsPay dashboard.',
	'check_ok' => 'Everything is ready.',
	'check_failed' => 'Fix the points marked with a cross.',
	'panel_invoice' => 'CoinsPay invoice',
	'panel_status' => 'Status',
	'panel_amount' => 'Amount',
	'panel_paid' => 'Received',
);
