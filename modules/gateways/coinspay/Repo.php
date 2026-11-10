<?php
/**
 * Tables of the module: CoinsPay invoices of WHMCS invoices, and a small key-value store.
 * Plain SQL through the PDO of WHMCS: the same on WHMCS 7.0 and 9.
 */
defined('WHMCS') or die('This file cannot be accessed directly');

class CoinsPay_Repo {

	const INVOICES = 'mod_coinspay_invoices';
	const KV = 'mod_coinspay_kv';

	/** @var CoinsPay_Host */
	private $host;

	/** @var bool|null */
	private $ready;

	public function __construct(CoinsPay_Host $host) {
		$this->host = $host;
	}

	/**
	 * Creates the tables. Only from ?a=pay and ?a=check: CREATE TABLE commits the connection WHMCS
	 * shares with us, so never inside a hook or a webhook.
	 */
	public function install() {
		$this->exec(
			'CREATE TABLE IF NOT EXISTS `' . self::INVOICES . '` (
				`invoice_id` CHAR(36) NOT NULL,
				`whmcs_invoice` INT UNSIGNED NOT NULL,
				`whmcs_userid` INT UNSIGNED NOT NULL DEFAULT 0,
				`attempt` INT UNSIGNED NOT NULL,
				`sent_amount` VARCHAR(40) NOT NULL,
				`sent_currency` VARCHAR(10) NOT NULL,
				`whmcs_amount` VARCHAR(40) NOT NULL,
				`whmcs_currency_id` INT UNSIGNED NOT NULL,
				`test` TINYINT(1) NOT NULL DEFAULT 0,
				`test_admin` TINYINT(1) NOT NULL DEFAULT 0,
				`status` VARCHAR(16) NOT NULL DEFAULT \'open\',
				`pay_url` VARCHAR(500) NOT NULL DEFAULT \'\',
				`snapshot` TEXT NULL,
				`fingerprint` CHAR(32) NULL,
				`apply_state` VARCHAR(10) NOT NULL DEFAULT \'none\',
				`claimed` INT UNSIGNED NULL,
				`attention` TEXT NULL,
				`next_poll` INT UNSIGNED NULL,
				`poll_until` INT UNSIGNED NOT NULL DEFAULT 0,
				`created` INT UNSIGNED NOT NULL,
				`updated` INT UNSIGNED NOT NULL,
				PRIMARY KEY (`invoice_id`),
				KEY `whmcs_invoice` (`whmcs_invoice`),
				KEY `next_poll` (`next_poll`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8',
			array()
		);
		$this->exec(
			'CREATE TABLE IF NOT EXISTS `' . self::KV . '` (
				`k` VARCHAR(64) NOT NULL,
				`v` TEXT NOT NULL,
				`updated` INT UNSIGNED NOT NULL,
				PRIMARY KEY (`k`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8',
			array()
		);
		$this->ready = true;
	}

	/** Whether the tables exist: before the first payment they may not. */
	public function ready() {
		if ($this->ready === null) {
			// Not SHOW TABLES LIKE ?: the PDO of WHMCS (Laravel) prepares on the server, and MySQL
			// takes no placeholders in SHOW.
			$statement = $this->host->pdo()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
			CoinsPay_Repo::run($statement, array(self::INVOICES));
			$this->ready = (bool)$statement->fetch(PDO::FETCH_NUM);
			$statement->closeCursor();
		}
		return $this->ready;
	}

	/**
	 * @param array $row Columns of a new CoinsPay invoice.
	 */
	public function add($row) {
		$now = time();
		$row += array('created' => $now, 'updated' => $now, 'apply_state' => 'none');
		$columns = array_keys($row);
		$this->exec(
			'INSERT INTO `' . self::INVOICES . '` (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
			array_values($row)
		);
	}

	public function find($invoiceId) {
		if (!$this->ready()) {
			return null;
		}
		return $this->one('SELECT * FROM `' . self::INVOICES . '` WHERE invoice_id = ?', array(strtolower((string)$invoiceId)));
	}

	/** CoinsPay invoices of a WHMCS invoice, the newest first. */
	public function forInvoice($whmcsInvoice) {
		if (!$this->ready()) {
			return array();
		}
		return $this->all('SELECT * FROM `' . self::INVOICES . '` WHERE whmcs_invoice = ? ORDER BY attempt DESC', array((int)$whmcsInvoice));
	}

	/** The newest CoinsPay invoice of a WHMCS invoice: the one the buyer pays now. */
	public function current($whmcsInvoice) {
		$rows = $this->forInvoice($whmcsInvoice);
		return $rows ? $rows[0] : null;
	}

	public function update($invoiceId, $columns) {
		$columns['updated'] = time();
		$sets = array();
		foreach (array_keys($columns) as $column) {
			$sets[] = '`' . $column . '` = ?';
		}
		$args = array_values($columns);
		$args[] = strtolower((string)$invoiceId);
		$this->exec('UPDATE `' . self::INVOICES . '` SET ' . implode(', ', $sets) . ' WHERE invoice_id = ?', $args);
	}

	/** Invoices to poll now: open ones, and closed ones a little longer for a late payment. */
	public function due($limit) {
		if (!$this->ready()) {
			return array();
		}
		$now = time();
		return $this->all(
			'SELECT * FROM `' . self::INVOICES . '` WHERE next_poll IS NOT NULL AND next_poll <= ? AND poll_until > ? ORDER BY next_poll LIMIT ' . (int)$limit,
			array($now, $now)
		);
	}

	/** CoinsPay invoices with money that need a decision of the admin. */
	public function attention($whmcsInvoice) {
		if (!$this->ready()) {
			return array();
		}
		return $this->all('SELECT * FROM `' . self::INVOICES . '` WHERE whmcs_invoice = ? AND attention IS NOT NULL ORDER BY attempt DESC', array((int)$whmcsInvoice));
	}

	public function kvGet($key, $maxAge = 0) {
		if (!$this->ready()) {
			return null;
		}
		$row = $this->one('SELECT v, updated FROM `' . self::KV . '` WHERE k = ?', array((string)$key));
		if (!$row || ($maxAge > 0 && (int)$row['updated'] < time() - $maxAge)) {
			return null;
		}
		return (string)$row['v'];
	}

	public function kvSet($key, $value) {
		if (!$this->ready()) {
			return;
		}
		$this->exec(
			'INSERT INTO `' . self::KV . '` (k, v, updated) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated = VALUES(updated)',
			array((string)$key, (string)$value, time())
		);
	}

	public function kvDelete($key) {
		if ($this->ready()) {
			$this->exec('DELETE FROM `' . self::KV . '` WHERE k = ?', array((string)$key));
		}
	}

	/**
	 * Whether this request may do a periodic job now: one request takes it, the others skip.
	 *
	 * @param string $key
	 * @param int    $every Seconds.
	 * @return bool
	 */
	public function takeTurn($key, $every) {
		if (!$this->ready()) {
			return false;
		}
		$now = time();
		$this->exec('INSERT IGNORE INTO `' . self::KV . '` (k, v, updated) VALUES (?, \'\', 0)', array((string)$key));
		$statement = $this->host->pdo()->prepare('UPDATE `' . self::KV . '` SET updated = ? WHERE k = ? AND updated <= ?');
		CoinsPay_Repo::run($statement, array($now, (string)$key, $now - $every));
		return $statement->rowCount() === 1;
	}

	/**
	 * Claims the payment of a CoinsPay invoice for this request: one row update, so of two
	 * requests at once only one gets it, whatever the named lock did. A claim left by a request
	 * that died is taken over after $stale seconds.
	 *
	 * @param string $invoiceId
	 * @param int    $stale
	 * @return bool
	 */
	public function claim($invoiceId, $stale) {
		$now = time();
		$statement = $this->host->pdo()->prepare(
			'UPDATE `' . self::INVOICES . '` SET apply_state = \'applying\', claimed = ?, updated = ?
			WHERE invoice_id = ? AND (apply_state = \'none\' OR (apply_state = \'applying\' AND (claimed IS NULL OR claimed < ?)))'
		);
		CoinsPay_Repo::run($statement, array($now, $now, strtolower((string)$invoiceId), $now - (int)$stale));
		return $statement->rowCount() === 1;
	}

	/**
	 * Takes the lock of a WHMCS invoice. It keeps requests of one invoice in turn, but is not the
	 * guard of the payment: MySQL before 5.7.5 keeps one named lock per connection, so a nested
	 * lock drops ours, and Galera keeps them per node. claim() is the guard.
	 *
	 * @return bool False when another request held it for 10 seconds.
	 */
	public function lock($whmcsInvoice) {
		$row = $this->one('SELECT GET_LOCK(?, 10) AS locked', array($this->lockName($whmcsInvoice)));
		return $row && (string)$row['locked'] === '1';
	}

	public function unlock($whmcsInvoice) {
		$this->one('SELECT RELEASE_LOCK(?) AS released', array($this->lockName($whmcsInvoice)));
	}

	/**
	 * Runs a prepared statement. WHMCS may keep PDO in the silent error mode: a failed query
	 * must still stop the module.
	 *
	 * @param PDOStatement $statement
	 * @param array        $args
	 */
	public static function run($statement, $args) {
		if ($statement->execute($args) === false) {
			$info = $statement->errorInfo();
			throw new RuntimeException('Database query failed: ' . (isset($info[2]) ? $info[2] : 'unknown error'));
		}
	}

	private function lockName($whmcsInvoice) {
		return 'coinspay_whmcs_' . substr(md5($this->host->systemUrl()), 0, 12) . '_' . (int)$whmcsInvoice;
	}

	private function exec($sql, $args) {
		$statement = $this->host->pdo()->prepare($sql);
		CoinsPay_Repo::run($statement, $args);
		$statement->closeCursor();
	}

	private function one($sql, $args) {
		$statement = $this->host->pdo()->prepare($sql);
		CoinsPay_Repo::run($statement, $args);
		$row = $statement->fetch(PDO::FETCH_ASSOC);
		$statement->closeCursor();
		return $row ? $row : null;
	}

	private function all($sql, $args) {
		$statement = $this->host->pdo()->prepare($sql);
		CoinsPay_Repo::run($statement, $args);
		$rows = $statement->fetchAll(PDO::FETCH_ASSOC);
		$statement->closeCursor();
		return $rows;
	}
}
