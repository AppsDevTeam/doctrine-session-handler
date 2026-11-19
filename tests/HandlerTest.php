<?php

declare(strict_types=1);

namespace ADT\DoctrineSessionHandler\Tests;

use ADT\DoctrineSessionHandler\Handler;
use ADT\DoctrineSessionHandler\Tests\Fixtures\QueryCounter;
use ADT\DoctrineSessionHandler\Tests\Fixtures\SessionStorage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * Every new Handler (and EntityManager) stands for a new request; the database is shared.
 *
 * Runs on in-memory SQLite; DATABASE_URL (e.g. mysql://root:secret@127.0.0.1/session_test) runs it
 * on another database - MySQL/MariaDB take a different path when a session is created.
 */
final class HandlerTest extends TestCase
{
	private const ID = 'abcdefghijklmnopqrstuvwxyz012345';

	private Connection $connection;

	private QueryCounter $counter;

	protected function setUp(): void
	{
		$this->counter = new QueryCounter();
		$config = $this->config();
		$url = getenv('DATABASE_URL');
		$params = $url
			? (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql', 'sqlite' => 'pdo_sqlite']))->parse($url)
			: ['driver' => 'pdo_sqlite', 'memory' => true];
		$this->connection = DriverManager::getConnection($params, $config);
		$em = new EntityManager($this->connection, $config);
		$schemaTool = new SchemaTool($em);
		$schemaTool->dropSchema($em->getMetadataFactory()->getAllMetadata());
		$schemaTool->createSchema($em->getMetadataFactory()->getAllMetadata());
	}

	public function testNewSessionIsStoredAndReadBack(): void
	{
		$handler = $this->request();
		$this->assertFalse($handler->validateId(self::ID));
		$this->assertSame('', $handler->read(self::ID));
		$this->assertTrue($handler->write(self::ID, 'user|i:1;'));

		$handler = $this->request();
		$this->assertTrue($handler->validateId(self::ID));
		$this->assertSame('user|i:1;', $handler->read(self::ID));
	}

	public function testUnchangedSessionIsNotWrittenAndNeedsOneQuery(): void
	{
		$this->request()->write(self::ID, 'user|i:1;');

		$handler = $this->request();
		$this->counter->queries = [];
		$handler->validateId(self::ID);
		$handler->read(self::ID);
		$this->assertTrue($handler->updateTimestamp(self::ID, 'user|i:1;'));

		$this->assertCount(1, $this->counter->queries, 'validateId, read and updateTimestamp share one SELECT');
		$this->assertSame([], $this->counter->writes());
	}

	public function testUnchangedSessionMovesExpirationAfterRefreshInterval(): void
	{
		$this->request()->write(self::ID, 'user|i:1;');
		$this->connection->executeStatement("UPDATE session_storage SET expires_at = ?", [(new \DateTime('+5 minutes'))->format('Y-m-d H:i:s')]);

		$handler = $this->request();
		$this->counter->queries = [];
		$handler->read(self::ID);
		$handler->updateTimestamp(self::ID, 'user|i:1;');

		$this->assertCount(1, $this->counter->writes());
		$row = $this->connection->fetchAssociative('SELECT expires_at, data FROM session_storage');
		$this->assertGreaterThan(new \DateTime('+10 minutes'), new \DateTime($row['expires_at']));
		$this->assertSame('user|i:1;', $row['data']);
	}

	public function testSessionWithoutRowGetsNoneWhenNothingIsStored(): void
	{
		$handler = $this->request();
		$handler->read(self::ID);
		$handler->updateTimestamp(self::ID, '');

		$this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM session_storage'));
	}

	public function testExpiredSessionIsInvalidAndItsRowIsReused(): void
	{
		$this->request()->write(self::ID, 'user|i:1;');
		$this->connection->executeStatement("UPDATE session_storage SET expires_at = ?", [(new \DateTime('-1 minute'))->format('Y-m-d H:i:s')]);

		$handler = $this->request();
		$this->assertFalse($handler->validateId(self::ID));
		$this->assertSame('', $handler->read(self::ID));
		$this->assertTrue($handler->write(self::ID, 'user|i:2;'));

		$this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM session_storage'));
		$this->assertSame('user|i:2;', $this->request()->read(self::ID));
	}

	public function testChangedSessionIsWrittenWithoutAnotherSelect(): void
	{
		$this->request()->write(self::ID, 'user|i:1;');

		$handler = $this->request();
		$this->counter->queries = [];
		$handler->read(self::ID);
		$handler->write(self::ID, 'user|i:2;');

		$this->assertCount(2, $this->counter->queries, 'one SELECT and one UPDATE');
		$this->assertSame('user|i:2;', $this->request()->read(self::ID));
	}

	public function testDestroyRemovesTheSession(): void
	{
		$this->request()->write(self::ID, 'user|i:1;');

		$handler = $this->request();
		$handler->read(self::ID);
		$handler->destroy(self::ID);

		$this->assertFalse($this->request()->validateId(self::ID));
	}

	/**
	 * Two requests with the same cookie run in parallel (several tabs restored at once) and both
	 * start without a row. Whichever writes second must not fail on the unique sessionId,
	 * it overwrites the row - the same "last write wins" as with an existing session.
	 */
	public function testConcurrentRequestsCreatingTheSameSessionDoNotFail(): void
	{
		$first = $this->request();
		$second = $this->request();
		$this->assertSame('', $first->read(self::ID));
		$this->assertSame('', $second->read(self::ID));

		$this->assertTrue($first->write(self::ID, 'user|i:1;'));
		$createdAt = '2026-01-01 10:00:00';
		$this->connection->executeStatement('UPDATE session_storage SET created_at = ?', [$createdAt]);

		$this->assertTrue($second->write(self::ID, 'user|i:2;'));

		$this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM session_storage'));
		$this->assertSame('user|i:2;', $this->request()->read(self::ID));
		$row = $this->connection->fetchAssociative('SELECT created_at, expires_at FROM session_storage');
		$this->assertSame($createdAt, $row['created_at'], 'the row keeps when it was created');
		$this->assertGreaterThan(new \DateTime('+10 minutes'), new \DateTime($row['expires_at']));
	}

	private function request(): Handler
	{
		return new Handler(SessionStorage::class, new EntityManager($this->connection, $this->config()));
	}

	private function config(): \Doctrine\ORM\Configuration
	{
		$config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/Fixtures'], true);
		$config->setNamingStrategy(new UnderscoreNamingStrategy());
		if (method_exists($config, 'enableNativeLazyObjects')) {
			$config->enableNativeLazyObjects(true);
		}
		$config->setMiddlewares([$this->counter]);

		return $config;
	}
}
