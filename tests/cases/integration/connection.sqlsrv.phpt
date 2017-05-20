<?php

/**
 * @testCase
 * @dataProvider? ../../databases.ini sqlsrv
 */

namespace NextrasTests\Dbal;

use Nextras\Dbal\Drivers\Sqlsrv\SqlsrvDriver;
use Nextras\Dbal\QueryException;
use Tester\Assert;


require_once __DIR__ . '/../../bootstrap.php';


class ConnectionSqlsrvTest extends IntegrationTestCase
{
	public function testReconnect()
	{
		$this->connection->query('create table #temp (val int)');
		$this->connection->query('insert into #temp values (1)');
		Assert::same(1, $this->connection->query('SELECT * FROM #temp')->fetchField());
		$this->connection->reconnect();
		Assert::exception(function () {
			$this->connection->query('SELECT * FROM #temp');
		}, QueryException::class);
	}


	public function testLastInsertId()
	{
		$this->initData($this->connection);

		$this->connection->query('INSERT INTO publishers %values', ['name' => 'FOO']);
		Assert::same(2, $this->connection->getLastInsertedId());
	}


	public function testReconnectWithConfig()
	{
		$config = $this->connection->getConfig();
		$this->connection->connect();

		Assert::true($this->connection->getDriver()->isConnected());
		$oldDriver = $this->connection->getDriver();

		$config['driver'] = new SqlsrvDriver($config);
		$this->connection->reconnectWithConfig($config);

		$newDriver = $this->connection->getDriver();
		Assert::notSame($oldDriver, $newDriver);
	}
}


$test = new ConnectionSqlsrvTest();
$test->run();
