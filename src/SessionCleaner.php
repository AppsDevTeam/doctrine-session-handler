<?php

declare(strict_types=1);

namespace ADT\DoctrineSessionHandler;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes expired sessions IN BATCHES.
 *
 * Why batches: a single bulk `DELETE ... WHERE expires_at < NOW()` over a bloated
 * session table holds locks over a wide range and on a Galera cluster it overflows
 * the write-set limit (`1180 Got error 5 'size_exceeded_error' during COMMIT`).
 * The cleanup then keeps failing, expired sessions are never removed, the table
 * grows without bound and session I/O saturates PHP-FPM.
 *
 * Batches are deleted by primary key (`SELECT` ids → `DELETE ... WHERE id IN`),
 * which is portable across MySQL and PostgreSQL (`DELETE ... LIMIT` is MySQL-only).
 *
 * An index on the `expiresAt` column is required for reasonable performance,
 * see README.
 */
class SessionCleaner
{
	public const DEFAULT_BATCH_SIZE = 1000;

	public function __construct(
		private readonly string $entityClass,
		private readonly EntityManagerInterface $em,
	) {
	}

	/**
	 * @param int $batchSize number of rows deleted in a single transaction
	 * @param int|null $maxBatches stop after this many batches (null = until nothing is left)
	 * @param float $sleep pause between batches in seconds (eases replication load)
	 * @param callable(int, int): void|null $onBatch callback (rows in batch, total so far)
	 * @return int number of deleted sessions
	 */
	public function cleanup(
		int $batchSize = self::DEFAULT_BATCH_SIZE,
		?int $maxBatches = null,
		float $sleep = 0.0,
		?callable $onBatch = null,
	): int {
		$batchSize = max(1, $batchSize);

		$metadata = $this->em->getClassMetadata($this->entityClass);
		$connection = $this->em->getConnection();
		$platform = $connection->getDatabasePlatform();

		$table = $platform->quoteIdentifier($metadata->getTableName());
		$idField = $metadata->getSingleIdentifierFieldName();
		$idColumn = $platform->quoteIdentifier($metadata->getColumnName($idField));
		$expiresColumn = $platform->quoteIdentifier($metadata->getColumnName('expiresAt'));

		$idParamType = in_array($metadata->getTypeOfField($idField), ['integer', 'bigint', 'smallint'], true)
			? ArrayParameterType::INTEGER
			: ArrayParameterType::STRING;

		$selectSql = sprintf(
			'SELECT %s FROM %s WHERE %s < ? ORDER BY %s LIMIT %d',
			$idColumn,
			$table,
			$expiresColumn,
			$expiresColumn,
			$batchSize,
		);
		$deleteSql = sprintf('DELETE FROM %s WHERE %s IN (?)', $table, $idColumn);

		// The cut-off time is fixed upfront so that sessions expiring during the run
		// are not picked up; otherwise a long cleanup might never reach the end.
		$now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

		$deleted = 0;
		$batches = 0;

		while ($maxBatches === null || $batches < $maxBatches) {
			$ids = $connection->fetchFirstColumn($selectSql, [$now]);
			if (!$ids) {
				break;
			}

			$deleted += (int) $connection->executeStatement($deleteSql, [$ids], [$idParamType]);
			$batches++;

			if ($onBatch !== null) {
				$onBatch(count($ids), $deleted);
			}

			// A partial batch means there is nothing left to delete.
			if (count($ids) < $batchSize) {
				break;
			}

			if ($sleep > 0) {
				usleep((int) round($sleep * 1000000));
			}
		}

		return $deleted;
	}
}
