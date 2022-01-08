<?php

namespace ADT\DoctrineSessionHandler;

use ADT\DoctrineSessionHandler\Traits\Session;
use Doctrine\ORM\EntityManager;

class Handler implements \SessionHandlerInterface 
{
	protected EntityManager $em;

	protected string $entityClass;

	public function __construct(string $entityClass, EntityManager $em) 
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
		if ($session = $this->getSession($id)) {
			$this->em->remove($session);
			$this->em->flush($session);
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
			->andWhere("e.expiresAt < :now", new \DateTime)
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
			$session = new $this->entityClass;
			$session->createdAt = new \DateTime;
			$session->sessionId = $id;

			$this->em->persist($session);
		}

		$session->expiresAt = new \DateTime("+$expiration minutes");
		$session->data = $data;

		$this->em->flush($session);

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
}
