<?php
/**
 * Loads the classes of the module. Plain requires: the autoloader of WHMCS knows nothing of them.
 */
defined('WHMCS') or die('This file cannot be accessed directly');

foreach (array(
	'Util', 'ApiError', 'ApiClient', 'Signature', 'Host', 'WhmcsHost', 'Settings', 'Tokens', 'Text',
	'InvoiceRequest', 'Repo', 'Currencies', 'Decision', 'Module', 'Sync', 'Payment', 'Webhook',
	'Poller', 'Hooks', 'Admin',
) as $coinspayClass) {
	require_once __DIR__ . '/' . $coinspayClass . '.php';
}
unset($coinspayClass);
