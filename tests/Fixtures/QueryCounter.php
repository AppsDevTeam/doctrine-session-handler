<?php

declare(strict_types=1);

namespace ADT\DoctrineSessionHandler\Tests\Fixtures;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** Collects SQL sent to the database, so tests can check how many queries a request needs */
final class QueryCounter implements Middleware
{
	/** @var list<string> */
	public array $queries = [];

	public function wrap(Driver $driver): Driver
	{
		$counter = $this;

		return new class ($driver, $counter) extends AbstractDriverMiddleware {
			public function __construct(Driver $driver, private readonly QueryCounter $counter)
			{
				parent::__construct($driver);
			}

			public function connect(array $params): Connection
			{
				return new class (parent::connect($params), $this->counter) extends AbstractConnectionMiddleware {
					public function __construct(Connection $connection, private readonly QueryCounter $counter)
					{
						parent::__construct($connection);
					}

					public function prepare(string $sql): Statement
					{
						$this->counter->queries[] = $sql;
						return parent::prepare($sql);
					}

					public function query(string $sql): Result
					{
						$this->counter->queries[] = $sql;
						return parent::query($sql);
					}

					public function exec(string $sql): int|string
					{
						$this->counter->queries[] = $sql;
						return parent::exec($sql);
					}
				};
			}
		};
	}

	/** @return list<string> UPDATE and INSERT statements */
	public function writes(): array
	{
		return array_values(array_filter($this->queries, static fn (string $sql): bool => (bool) preg_match('/^\s*(UPDATE|INSERT)/i', $sql)));
	}
}
