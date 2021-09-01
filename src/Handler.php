<?php

namespace ADT\DoctrineSessionHandler;

use ADT\DoctrineSessionHandler\Traits\Session;
use Doctrine\ORM\EntityManager;

class Handler implements \SessionHandlerInterface {

	/** @var EntityManager */
	protected $em;

	/** string */
	protected $entityClass;

	/**
	 * @param EntityManager $em
	 */
	public function __construct($entityClass, EntityManager $em) {
		$this->entityClass = $entityClass;
		$this->em = $em;
	}

	/**
	 * @inheritDoc
	 */
	public function close() {
		return TRUE;
	}

	/**
	 * @inheritDoc
	 */
	public function destroy($session_id) {
		if ($session = $this->getSession($session_id)) {
			$this->em->remove($session);
			$this->em->flush($session);
		}

		return TRUE;
	}

	/**
	 * @inheritDoc
	 */
	public function gc($maxlifetime) {
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
	public function open($save_path, $name)
	{
		return TRUE;
	}

	/**
	 * @inheritDoc
	 */
	public function read($session_id)
	{
		$session = $this->getSession($session_id);
		return $session->data ?? "";
	}

	/**
	 * @inheritDoc
	 */
	public function write($session_id, $session_data)
	{
		$session = $this->getSession($session_id);

		if (!$session) {
			$lifetime = ini_get("session.gc_maxlifetime");
			$expiration = $lifetime ? ($lifetime / 60) : 15;

			$session = new $this->entityClass;
			$session->createdAt = new \DateTime;
			$session->expiresAt = new \DateTime("+$expiration minutes");
			$session->sessionId = $session_id;

			$this->em->persist($session);
		}

		$session->data = $session_data;

		$this->em->flush($session);

		return TRUE;
	}

	/**
	 * @param $session_id
	 * @return Session|NULL
	 * @throws \Doctrine\ORM\NonUniqueResultException
	 */
	protected function getSession($session_id) {
		return $this->em->createQueryBuilder()
			->select("e")
			->from($this->entityClass, "e")
			->andWhere("e.sessionId = :id", $session_id)
			->getQuery()
			->getOneOrNullResult();
	}
}
