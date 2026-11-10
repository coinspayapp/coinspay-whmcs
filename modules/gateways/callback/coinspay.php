<?php
/**
 * The single entry point of CoinsPay for WHMCS: callback/ is the path of a gateway WHMCS keeps
 * reachable from the web.
 *
 *   POST (no ?a)  webhook of CoinsPay, signed with x-cpay-signature
 *   ?a=pay        the "Pay Now" form: make or reuse a CoinsPay invoice, go to its payment page
 *   ?a=return     the client is back from the payment page
 *   ?a=check      connection check, for a signed-in admin
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../coinspay/loader.php';

$coinspayModule = new CoinsPay_Module(new CoinsPay_WhmcsHost());
$coinspayAction = isset($_GET['a']) && is_string($_GET['a']) ? $_GET['a'] : '';

/**
 * Sends an answer and stops: plain text, no cache.
 *
 * @param int    $status
 * @param string $body
 * @param string $type
 */
function coinspay_answer($status, $body, $type = 'text/plain') {
	http_response_code($status);
	header('Content-Type: ' . $type . '; charset=utf-8');
	header('Cache-Control: no-store');
	echo $body;
	exit;
}

/** A page with a message for the client and a way back to the invoice. */
function coinspay_message($message, $back, $lang) {
	$text = new CoinsPay_Text($lang);
	coinspay_answer(200, CoinsPay_Admin::page('CoinsPay', '<p>' . CoinsPay_Util::html($message) . '</p><p><a href="' . CoinsPay_Util::html($back) . '">' . CoinsPay_Util::html($text->t('back_to_invoice')) . '</a></p>'), 'text/html');
}

try {
	if ($coinspayAction === 'pay') {
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			// A link from an email: a page that posts the form itself, so link scanners make no invoices.
			$fields = '';
			foreach ($_GET as $name => $value) {
				if ($name !== 'a' && is_scalar($value)) {
					$fields .= '<input type="hidden" name="' . CoinsPay_Util::html($name) . '" value="' . CoinsPay_Util::html($value) . '">';
				}
			}
			coinspay_answer(200, CoinsPay_Admin::page('CoinsPay', '<form id="pay" method="post" action="?a=pay">' . $fields . '<noscript><button type="submit">Pay Now</button></noscript></form><script>document.getElementById("pay").submit()</script>'), 'text/html');
		}
		$payment = new CoinsPay_Payment($coinspayModule);
		$result = $payment->start($_POST);
		if (isset($result['redirect']) && $result['redirect'] !== '') {
			header('Location: ' . $result['redirect'], true, 303);
			exit;
		}
		coinspay_message($result['error'], $result['back'], isset($_POST['lang']) && $_POST['lang'] === 'ru' ? 'ru' : 'en');
	}

	if ($coinspayAction === 'return') {
		$invoiceId = isset($_GET['invoice']) ? (int)$_GET['invoice'] : 0;
		$token = isset($_GET[CoinsPay_Tokens::FIELD]) && is_string($_GET[CoinsPay_Tokens::FIELD]) ? $_GET[CoinsPay_Tokens::FIELD] : '';
		$back = $coinspayModule->host()->systemUrl() . 'viewinvoice.php?id=' . $invoiceId;
		if ($invoiceId > 0 && $coinspayModule->tokens()->checkReturn($invoiceId, $token)) {
			$row = $coinspayModule->repo()->current($invoiceId);
			if ($row) {
				try {
					// The webhook may be slower than the client: the state is applied here too.
					$coinspayModule->sync()->apply($coinspayModule->client()->invoice($row['invoice_id']));
				} catch (Exception $e) {
					$coinspayModule->log('return: ' . $e->getMessage(), true);
				}
			}
			$invoice = $coinspayModule->host()->invoice($invoiceId);
			if ($invoice && $invoice['status'] === 'Paid') {
				$back .= '&paymentsuccess=true';
			}
		}
		header('Location: ' . $back, true, 303);
		exit;
	}

	if ($coinspayAction === 'check') {
		$admin = new CoinsPay_Admin($coinspayModule);
		$page = $admin->check();
		coinspay_answer($page[0], $page[1], 'text/html');
	}

	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		coinspay_answer(405, 'POST only');
	}
	$webhook = new CoinsPay_Webhook($coinspayModule);
	$answer = $webhook->handle(
		(string)file_get_contents('php://input'),
		isset($_SERVER['HTTP_X_CPAY_SIGNATURE']) ? (string)$_SERVER['HTTP_X_CPAY_SIGNATURE'] : ''
	);
	coinspay_answer($answer[0], $answer[1]);
} catch (Exception $e) {
	$coinspayModule->log('callback: ' . $e->getMessage(), true);
	coinspay_answer(500, 'error');
}
