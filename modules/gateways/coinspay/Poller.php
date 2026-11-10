<?php
/**
 * Polling of CoinsPay invoices when a webhook did not come: from the cron of WHMCS, and for one
 * invoice when its page is opened.
 */
defined('WHMCS') or die('This file cannot be accessed directly');

class CoinsPay_Poller {

	const BATCH = 25;
	const BUDGET = 20;

	/** @var CoinsPay_Module */
	private $module;

	public function __construct(CoinsPay_Module $module) {
		$this->module = $module;
	}

	/**
	 * Checks the invoices that are due, within a limit of count and time: the cron of WHMCS runs
	 * other jobs too.
	 *
	 * @return int How many were checked.
	 */
	public function run() {
		$started = time();
		$failures = 0;
		$checked = 0;
		foreach ($this->module->repo()->due(self::BATCH) as $row) {
			if (time() - $started >= self::BUDGET || $failures >= 3) {
				break;
			}
			if ($this->check($row)) {
				$checked++;
			} else {
				$failures++;
			}
		}
		return $checked;
	}

	/**
	 * The newest CoinsPay invoice of a WHMCS invoice, at most once a minute: for the invoice page.
	 *
	 * @param int $whmcsInvoice
	 */
	public function checkInvoice($whmcsInvoice) {
		$row = $this->module->repo()->current($whmcsInvoice);
		// A paid invoice is done once settled (no next poll); one whose payment failed halfway is not.
		$settled = $row && $row['status'] === 'paid' && $row['next_poll'] === null;
		if ($row && !$settled && $this->module->repo()->takeTurn('view_' . (int)$whmcsInvoice, 60)) {
			$this->check($row);
		}
	}

	/** @return bool False when the API failed. */
	private function check($row) {
		// Made with a key of the other mode: the admin switched keys, and this one does not know
		// the invoice. Asked again later, when the key of its mode may be back.
		if ((bool)$row['test'] !== $this->module->settings()->isTestMode()) {
			$this->postpone($row);
			return true;
		}
		try {
			$this->module->sync()->apply($this->module->client()->invoice($row['invoice_id']));
			return true;
		} catch (CoinsPay_ApiError $e) {
			$this->module->log('polling ' . $row['invoice_id'] . ': ' . $e->summary(), true);
			if ($e->isNotFound()) {
				// Unknown to this key: a key of another shop now. Not dropped: the old key may come back.
				$this->postpone($row);
				return true;
			}
			return false;
		} catch (RuntimeException $e) {
			// A busy invoice or a payment WHMCS did not record: the next run tries again.
			$this->module->log('polling ' . $row['invoice_id'] . ': ' . $e->getMessage(), true);
			return true;
		}
	}

	/** The next poll in 30 minutes, within the polling time of the invoice. */
	private function postpone($row) {
		$next = time() + CoinsPay_Sync::POLL_CLOSED;
		$this->module->repo()->update($row['invoice_id'], array('next_poll' => $next < (int)$row['poll_until'] ? $next : null));
	}
}
