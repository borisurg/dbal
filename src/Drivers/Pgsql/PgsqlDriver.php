<?php declare(strict_types=1);

/**
 * This file is part of the Nextras\Dbal library.
 * @license    MIT
 * @link       https://github.com/nextras/dbal
 */

namespace Nextras\Dbal\Drivers\Pgsql;

use DateInterval;
use DateTimeZone;
use Nextras\Dbal\Connection;
use Nextras\Dbal\ConnectionException;
use Nextras\Dbal\DriverException;
use Nextras\Dbal\Drivers\IDriver;
use Nextras\Dbal\ForeignKeyConstraintViolationException;
use Nextras\Dbal\InvalidArgumentException;
use Nextras\Dbal\NotNullConstraintViolationException;
use Nextras\Dbal\NotSupportedException;
use Nextras\Dbal\Platforms\IPlatform;
use Nextras\Dbal\Platforms\PostgreSqlPlatform;
use Nextras\Dbal\QueryException;
use Nextras\Dbal\Result\Result;
use Nextras\Dbal\UniqueConstraintViolationException;


class PgsqlDriver implements IDriver
{
	/** @var resource */
	private $connection;

	/** @var DateTimeZone Timezone for database connection. */
	private $connectionTz;

	/** @var callable */
	private $loggedQueryCallback;

	/** @var int */
	private $affectedRows = 0;

	/** @var float */
	private $timeTaken = 0.0;


	public function __destruct()
	{
		$this->disconnect();
	}


	public function connect(array $params, callable $loggedQueryCallback): void
	{
		static $knownKeys = [
			'host', 'hostaddr', 'port', 'dbname', 'user', 'password',
			'connect_timeout', 'options', 'sslmode', 'service',
		];

		$this->loggedQueryCallback = $loggedQueryCallback;

		$params = $this->processConfig($params);
		$connectionString = '';
		foreach ($knownKeys as $key) {
			if (isset($params[$key])) {
				$connectionString .= $key . '=' . $params[$key] . ' ';
			}
		}

		set_error_handler(function($code, $message) {
			restore_error_handler();
			throw $this->createException($message, $code, NULL);
		}, E_ALL);

		$this->connection = pg_connect($connectionString, PGSQL_CONNECT_FORCE_NEW);

		restore_error_handler();

		$this->connectionTz = new DateTimeZone($params['connectionTz']);
		$this->loggedQuery('SET TIME ZONE ' . pg_escape_literal($this->connectionTz->getName()));
	}


	public function disconnect(): void
	{
		if ($this->connection) {
			pg_close($this->connection);
			$this->connection = NULL;
		}
	}


	public function isConnected(): bool
	{
		return $this->connection !== NULL;
	}


	public function getResourceHandle()
	{
		return $this->connection;
	}


	public function query(string $query): ?Result
	{
		if (!pg_send_query($this->connection, $query)) {
			throw $this->createException(pg_last_error($this->connection), 0, NULL);
		}

		$time = microtime(TRUE);
		$resource = pg_get_result($this->connection);
		$this->timeTaken = microtime(TRUE) - $time;

		if ($resource === FALSE) {
			throw $this->createException(pg_last_error($this->connection), 0, NULL);
		}

		$state = pg_result_error_field($resource, PGSQL_DIAG_SQLSTATE);
		if ($state !== NULL) {
			throw $this->createException(pg_result_error($resource), 0, $state, $query);
		}

		$this->affectedRows = pg_affected_rows($resource);
		return new Result(new PgsqlResultAdapter($resource), $this);
	}


	public function getLastInsertedId(string $sequenceName = NULL)
	{
		if (empty($sequenceName)) {
			throw new InvalidArgumentException('PgsqlDriver require to pass sequence name for getLastInsertedId() method.');
		}
		$sql = 'SELECT CURRVAL(' . pg_escape_literal($this->connection, $sequenceName) . ')';
		return $this->loggedQuery($sql)->fetchField();
	}


	public function getAffectedRows(): int
	{
		return $this->affectedRows;
	}


	public function getQueryElapsedTime(): float
	{
		return $this->timeTaken;
	}


	public function createPlatform(Connection $connection): IPlatform
	{
		return new PostgreSqlPlatform($connection);
	}


	public function getServerVersion(): string
	{
		return pg_version($this->connection)['server'];
	}


	public function ping(): bool
	{
		return pg_ping($this->connection);
	}


	public function setTransactionIsolationLevel(int $level): void
	{
		static $levels = [
			Connection::TRANSACTION_READ_UNCOMMITTED => 'READ UNCOMMITTED',
			Connection::TRANSACTION_READ_COMMITTED => 'READ COMMITTED',
			Connection::TRANSACTION_REPEATABLE_READ => 'REPEATABLE READ',
			Connection::TRANSACTION_SERIALIZABLE => 'SERIALIZABLE',
		];
		if (isset($levels[$level])) {
			throw new NotSupportedException("Unsupported transation level $level");
		}
		$this->loggedQuery("SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL {$levels[$level]}");
	}


	public function beginTransaction(): void
	{
		$this->loggedQuery('START TRANSACTION');
	}


	public function commitTransaction(): void
	{
		$this->loggedQuery('COMMIT');
	}


	public function rollbackTransaction(): void
	{
		$this->loggedQuery('ROLLBACK');
	}


	public function createSavepoint(string $name): void
	{
		$this->loggedQuery('SAVEPOINT ' . $this->convertIdentifierToSql($name));
	}


	public function releaseSavepoint(string $name): void
	{
		$this->loggedQuery('RELEASE SAVEPOINT ' . $this->convertIdentifierToSql($name));
	}


	public function rollbackSavepoint(string $name): void
	{
		$this->loggedQuery('ROLLBACK TO SAVEPOINT ' . $this->convertIdentifierToSql($name));
	}


	public function convertToPhp(string $value, $nativeType)
	{
		static $trues = ['true', 't', 'yes', 'y', 'on', '1'];

		if ($nativeType === 'bool') {
			return in_array(strtolower($value), $trues, TRUE);

		} elseif ($nativeType === 'int8') {
			// called only on 32bit
			return is_float($tmp = $value * 1) ? $value : $tmp;

		} elseif ($nativeType === 'interval') {
			return DateInterval::createFromDateString($value);

		} elseif ($nativeType === 'bit' || $nativeType === 'varbit') {
			return bindec($value);

		} elseif ($nativeType === 'bytea') {
			return pg_unescape_bytea($value);

		} else {
			throw new NotSupportedException("PgsqlDriver does not support '{$nativeType}' type conversion.");
		}
	}


	public function convertStringToSql(string $value): string
	{
		return pg_escape_literal($this->connection, $value);
	}


	public function convertJsonToSql($value): string
	{
		$encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
		if (json_last_error()) {
			throw new InvalidArgumentException('JSON Encode Error: ' . json_last_error_msg());
		}
		return $this->convertStringToSql($encoded);
	}


	public function convertLikeToSql(string $value, int $mode)
	{
		$value = strtr($value, [
			"'" => "''",
			'\\' => '\\\\',
			'%' => '\\%',
			'_' => '\\_',
		]);
		return ($mode <= 0 ? "'%" : "'") . $value . ($mode >= 0 ? "%'" : "'");
	}


	public function convertBoolToSql(bool $value): string
	{
		return $value ? 'TRUE' : 'FALSE';
	}


	public function convertIdentifierToSql(string $value): string
	{
		$parts = explode('.', $value);
		foreach ($parts as &$part) {
			if ($part !== '*') {
				$part = pg_escape_identifier($this->connection, $part);
			}
		}
		return implode('.', $parts);
	}


	public function convertDateTimeToSql(\DateTimeInterface $value): string
	{
		assert($value instanceof \DateTime || $value instanceof \DateTimeImmutable);
		if ($value->getTimezone()->getName() !== $this->connectionTz->getName()) {
			if ($value instanceof \DateTimeImmutable) {
				$value = $value->setTimezone($this->connectionTz);
			} else {
				$value = clone $value;
				$value->setTimezone($this->connectionTz);
			}
		}
		return "'" . $value->format('Y-m-d H:i:s') . "'::timestamptz";
	}


	public function convertDateTimeSimpleToSql(\DateTimeInterface $value): string
	{
		return "'" . $value->format('Y-m-d H:i:s') . "'::timestamp";
	}


	public function convertDateIntervalToSql(\DateInterval $value): string
	{
		return $value->format('P%yY%mM%dDT%hH%iM%sS');
	}


	public function convertBlobToSql(string $value): string
	{
		return "'" . pg_escape_bytea($this->connection, $value) . "'";
	}


	public function modifyLimitQuery(string $query, ?int $limit, ?int $offset): string
	{
		if ($limit !== NULL) {
			$query .= ' LIMIT ' . (int) $limit;
		}
		if ($offset !== NULL) {
			$query .= ' OFFSET ' . (int) $offset;
		}
		return $query;
	}


	/**
	 * This method is based on Doctrine\DBAL project.
	 * @link www.doctrine-project.org
	 */
	protected function createException($error, $errorNo, $sqlState, $query = NULL)
	{
		// see codes at http://www.postgresql.org/docs/9.4/static/errcodes-appendix.html
		if ($sqlState === '0A000' && strpos($error, 'truncate') !== FALSE) {
			// Foreign key constraint violations during a TRUNCATE operation
			// are considered "feature not supported" in PostgreSQL.
			return new ForeignKeyConstraintViolationException($error, $errorNo, $sqlState, NULL, $query);

		} elseif ($sqlState === '23502') {
			return new NotNullConstraintViolationException($error, $errorNo, $sqlState, NULL, $query);

		} elseif ($sqlState === '23503') {
			return new ForeignKeyConstraintViolationException($error, $errorNo, $sqlState, NULL, $query);

		} elseif ($sqlState === '23505') {
			return new UniqueConstraintViolationException($error, $errorNo, $sqlState, NULL, $query);

		} elseif ($sqlState === NULL && stripos($error, 'pg_connect()') !== FALSE) {
			return new ConnectionException($error, $errorNo, $sqlState);

		} elseif ($query !== NULL) {
			return new QueryException($error, $errorNo, $sqlState, NULL, $query);

		} else {
			return new DriverException($error, $errorNo, $sqlState);
		}
	}


	protected function loggedQuery(string $sql)
	{
		return ($this->loggedQueryCallback)($sql);
	}


	private function processConfig(array $params): array
	{
		$params['dbname'] = $params['database'] ?? null;
		$params['user'] = $params['username'] ?? null;
		unset($params['database'], $params['username']);
		if (!isset($params['connectionTz']) || $params['connectionTz'] === IDriver::TIMEZONE_AUTO_PHP_NAME) {
			$params['connectionTz'] = date_default_timezone_get();
		} elseif ($params['connectionTz'] === IDriver::TIMEZONE_AUTO_PHP_OFFSET) {
			$params['connectionTz'] = date('P');
		}
		return $params;
	}
}
