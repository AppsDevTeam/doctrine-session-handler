<?php

namespace ADT\DoctrineSessionHandler;

use ADT\DoctrineSessionHandler\Traits\Session;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

class Handler implements \SessionHandlerInterface 
{
	protected EntityManagerInterface $em;

	protected string $entityClass;

	public function __construct(string $entityClass, EntityManagerInterface $em)
	{
		$this->entityClass = $entityClass;
		$this->em = $em;
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
	public function gc(int $max_lifetime): int|false
	{
		$this->em->createQueryBuilder()
			->delete($this->entityClass, "e")
			->andWhere("e.expiresAt < :now")
			->setParameter('now', new \DateTime)
			->getQuery()
			->execute();

		return TRUE;
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
	public function read(string $id): string|false
	{
		$session = $this->getSession($id);
		return $session->data ?? "";
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
}
