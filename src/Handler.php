<?php

namespace ADT\DoctrineSessionHandler;

use ADT\DoctrineSessionHandler\Traits\Session;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;

class Handler implements \SessionHandlerInterface {

	/** @var EntityManagerInterface */
	protected $em;

	/** string */
	protected $entityClass;

	/**
	 * @param EntityManager $em
	 */
	public function __construct($entityClass, EntityManagerInterface $em) {
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
		if ($this->getSession($session_id) !== null) {
			$this->em->createQueryBuilder()
					 ->delete($this->entityClass, "e")
					 ->andWhere('e.sessionId = :id')
					 ->setParameter('id', $session_id)
					 ->getQuery()
					 ->execute();
		}

		return TRUE;
	}

	/**
	 * @inheritDoc
	 */
	public function gc($maxlifetime) {
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

		$lifetime = ini_get("session.gc_maxlifetime");
		$expiration = $lifetime ? ($lifetime / 60) : 15;

		if (!$session) {
			$this->em->getConnection()->createQueryBuilder()
					 ->insert($this->getTableName(), "e")
					 ->values(
						 [
							 'createdAt' => '?',
							 'sessionId' => '?',
							 'expires_at' => '?',
							 'data' => '?'
						 ]
					 )
					 ->setParameter(0, (new \DateTime()), Types::DATETIME_MUTABLE)
					 ->setParameter(1, $session_id)
					 ->setParameter(2, (new \DateTime("+$expiration minutes")), Types::DATETIME_MUTABLE)
					 ->setParameter(3, $session_data)
					 ->execute();
		} else {
			$this->em->createQueryBuilder()
					 ->update($this->entityClass, "e")
					 ->set("e.expiresAt", '?1')
					 ->set("e.data", '?2')
					 ->where("e.sessionId = :sessionId")
					 ->setParameter(1, new \DateTime("+$expiration minutes"))
					 ->setParameter(2, $session_data)
					 ->setParameter("sessionId", $session_id)
					 ->getQuery()
					 ->execute();
		}


		return TRUE;
	}

	/**
	 * @param $session_id
	 * @return Session|NULL
	 * @throws \Doctrine\ORM\NonUniqueResultException
	 */
	protected function getSession($session_id)
	{
		return $this->em->createQueryBuilder()
						->select("e")
						->from($this->entityClass, "e")
						->andWhere("e.sessionId = :id")
						->setParameter('id', $session_id)
						->getQuery()
						->getOneOrNullResult();
	}

	protected function getTableName()
	{
		return $this->em->getClassMetadata($this->entityClass)->getTableName();
	}
}
