<?php

namespace ADT\DoctrineSessionHandler;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

class Handler implements \SessionHandlerInterface
{
	protected EntityManagerInterface $em;

	protected string $entityClass;

	protected int $gcBatchSize;

	protected ?int $gcMaxBatches;

	private ?SessionCleaner $cleaner = null;

	/**
	 * @param int $gcBatchSize how many sessions inline GC deletes in one batch
	 * @param int|null $gcMaxBatches batch limit for inline GC (null = unlimited)
	 */
	public function __construct(
		string $entityClass,
		EntityManagerInterface $em,
		int $gcBatchSize = SessionCleaner::DEFAULT_BATCH_SIZE,
		?int $gcMaxBatches = 1
	) {
		$this->entityClass = $entityClass;
		$this->em = $em;
		$this->gcBatchSize = $gcBatchSize;
		$this->gcMaxBatches = $gcMaxBatches;
	}

	/**
	 * @inheritDoc
	 */
	public function close(): bool
	{
		return TRUE;
	}

	/**
	 * @inheritDoc
	 */
	public function destroy(string $id): bool
	{
		if ($this->getSession($id) !== null) {
			$this->em->createQueryBuilder()
				->delete($this->entityClass, "e")
				->andWhere('e.sessionId = :id')
				->setParameter('id', $id)
				->getQuery()
				->execute();
		}

		return TRUE;
	}

	/**
	 * @inheritDoc
	 */
	/**
	 * Inline garbage collection (runs in the middle of a request whenever PHP triggers
	 * it based on `session.gc_probability`). Deletes only a LIMITED number of batches -
	 * deleting all expired sessions at once would hold locks and overflow the write-set
	 * on Galera (`1180 size_exceeded`), so the cleanup would keep failing.
	 *
	 * Recommended setup: `session.gc_probability = 0` and cleanup from cron via the
	 * `session:cleanup` command ({@see CleanupCommand}).
	 *
	 * @inheritDoc
	 */
	public function gc(int $max_lifetime): int|false
	{
		return $this->getCleaner()->cleanup($this->gcBatchSize, $this->gcMaxBatches);
	}

	/**
	 * @inheritDoc
	 */
	public function open(string $path, string $name): bool
	{
		return TRUE;
	}

	/**
	 * @inheritDoc
	 */
	/**
	 * Expired sessions must not be readable.
	 *
	 * PHP does not enforce `session.gc_maxlifetime` on its own - with a custom save
	 * handler the session stays valid for as long as its row exists. Without this
	 * check the expiry would be enforced only by garbage collection, so how long an
	 * expired session keeps working would depend on how often GC runs.
	 *
	 * The row itself is left in place; `write()` reuses it (as a fresh, empty session)
	 * and garbage collection removes it later.
	 *
	 * @inheritDoc
	 */
	public function read(string $id): string|false
	{
		$session = $this->getSession($id);

		if ($session === null || $session->getExpiresAt() < new \DateTime()) {
			return "";
		}

		return $session->getData();
	}

	/**
	 * @inheritDoc
	 */
	public function write(string $id, string $data): bool
	{
		$session = $this->getSession($id);

		$lifetime = ini_get("session.gc_maxlifetime");
		$expiration = $lifetime ? ($lifetime / 60) : 15;

		if (!$session) {
			$metadata = $this->em->getClassMetadata($this->entityClass);

			$this->em->getConnection()->createQueryBuilder()
				->insert($this->getTableName(), "e")
				->values(
					[
						$metadata->getColumnName('createdAt') => '?',
						$metadata->getColumnName('expiresAt') => '?',
						$metadata->getColumnName('sessionId') => '?',
						$metadata->getColumnName('data') => '?'
					]
				)
				->setParameter(0, (new \DateTime()), Types::DATETIME_MUTABLE)
				->setParameter(1, (new \DateTime("+$expiration minutes")), Types::DATETIME_MUTABLE)
				->setParameter(2, $id)
				->setParameter(3, $data)
				->executeStatement();
		} else {
			$this->em->createQueryBuilder()
				->update($this->entityClass, "e")
				->set("e.expiresAt", '?1')
				->set("e.data", '?2')
				->where("e.sessionId = :sessionId")
				->setParameter(1, new \DateTime("+$expiration minutes"))
				->setParameter(2, $data)
				->setParameter("sessionId", $id)
				->getQuery()
				->execute();
		}

		return TRUE;
	}

	/**
	 * @throws \Doctrine\ORM\NonUniqueResultException
	 */
	protected function getSession(string $id): ?SessionInterface
	{
		return $this->em->createQueryBuilder()
			->select("e")
			->from($this->entityClass, "e")
			->andWhere("e.sessionId = :id")
			->setParameter('id', $id)
			->getQuery()
			->getOneOrNullResult();
	}

	private function getTableName(): string
	{
		return $this->em->getClassMetadata($this->entityClass)->getTableName();
	}

	private function getCleaner(): SessionCleaner
	{
		return $this->cleaner ??= new SessionCleaner($this->entityClass, $this->em);
	}
}
