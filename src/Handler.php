<?php

namespace ADT\DoctrineSessionHandler;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Session handler storing sessions in a Doctrine entity.
 *
 * Implements SessionUpdateTimestampHandlerInterface, so with `session.lazy_write` (PHP default)
 * an unchanged session is not written on every request: updateTimestamp() only moves the
 * expiration, and at most once per $refreshInterval seconds. A write on every request is
 * expensive (on a Galera cluster each one is replicated to all nodes) and most requests do
 * not change the session at all.
 *
 * The session row loaded by validateId()/read() is remembered for the request, so the check,
 * the read and the write need a single SELECT.
 */
class Handler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
	/** Marks a base64 encoded (binary) payload, see encodeData() */
	private const BINARY_PREFIX = 'b64:';

	protected EntityManagerInterface $em;

	protected string $entityClass;

	protected int $gcBatchSize;

	protected ?int $gcMaxBatches;

	private ?SessionCleaner $cleaner = null;

	/** @var array<string, SessionInterface|null> session ID => its row (null = no row), loaded in this request */
	private array $sessions = [];

	/** @var array<string, \DateTimeInterface|null> session ID => expiration stored in its row (null = no row) */
	private array $loaded = [];

	/**
	 * @param int $gcBatchSize how many sessions inline GC deletes in one batch
	 * @param int|null $gcMaxBatches batch limit for inline GC (null = unlimited)
	 * @param int $refreshInterval seconds - an unchanged session moves its expiration only when it would move by at least this much
	 */
	public function __construct(
		string $entityClass,
		EntityManagerInterface $em,
		int $gcBatchSize = SessionCleaner::DEFAULT_BATCH_SIZE,
		?int $gcMaxBatches = 1,
		protected int $refreshInterval = 60,
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
		unset($this->loaded[$id], $this->sessions[$id]);
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
		$session = $this->loadSession($id);

		if ($session === null || $session->getExpiresAt() < new \DateTimeImmutable()) {
			return "";
		}

		return $this->decodeData($session->getData());
	}

	/**
	 * @inheritDoc
	 */
	public function write(string $id, string $data): bool
	{
		$exists = array_key_exists($id, $this->loaded) ? $this->loaded[$id] !== null : $this->getSession($id) !== null;
		$data = $this->encodeData($data);

		$expiration = $this->getLifetimeMinutes();

		if (!$exists) {
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
		$this->loaded[$id] = new \DateTime("+$expiration minutes");

		return TRUE;
	}

	/**
	 * With strict mode PHP asks before read() whether the ID belongs to an existing session;
	 * an unknown or expired one is replaced by a new ID (protection against session fixation).
	 */
	public function validateId(string $id): bool
	{
		$session = $this->loadSession($id);

		return $session !== null && $session->getExpiresAt() >= new \DateTimeImmutable();
	}

	/**
	 * Called instead of write() when the session data did not change. Only the expiration
	 * moves, and only when it would move by at least $refreshInterval - otherwise nothing
	 * is written. A session without a row (nothing was ever stored in it) gets none.
	 */
	public function updateTimestamp(string $id, string $data): bool
	{
		if (!array_key_exists($id, $this->loaded)) {
			return $this->write($id, $data);
		}
		$expiresAt = $this->loaded[$id];
		if ($expiresAt === null) {
			return TRUE;
		}

		$expiration = $this->getLifetimeMinutes();
		$newExpiresAt = new \DateTime("+$expiration minutes");
		if ($newExpiresAt->getTimestamp() - $expiresAt->getTimestamp() < $this->refreshInterval) {
			return TRUE;
		}

		$this->em->createQueryBuilder()
			->update($this->entityClass, "e")
			->set("e.expiresAt", ':expiresAt')
			->where("e.sessionId = :sessionId")
			->setParameter('expiresAt', $newExpiresAt)
			->setParameter("sessionId", $id)
			->getQuery()
			->execute();
		$this->loaded[$id] = $newExpiresAt;

		return TRUE;
	}

	private function getLifetimeMinutes(): float|int
	{
		$lifetime = ini_get("session.gc_maxlifetime");

		return $lifetime ? ($lifetime / 60) : 15;
	}

	/** The session row, loaded once per request (validateId, read and write share it) */
	private function loadSession(string $id): ?SessionInterface
	{
		if (!array_key_exists($id, $this->sessions)) {
			$this->sessions[$id] = $this->getSession($id);
			$this->loaded[$id] = $this->sessions[$id]?->getExpiresAt();
		}

		return $this->sessions[$id];
	}

	/**
	 * Session data are stored in a text column, but a session may legitimately hold
	 * binary values (a WebAuthn challenge, a raw token). Those bytes are not valid
	 * UTF-8, so MySQL either rejects the write (error 1366) or - without strict mode -
	 * silently truncates it at the first invalid byte. A truncated blob cannot be
	 * decoded, so from then on *every* request carrying that cookie dies with
	 * "Failed to decode session object. Session has been destroyed" until the session
	 * expires - the whole application, not just the feature that wrote the value.
	 *
	 * Binary payloads are therefore stored base64 encoded, marked with a prefix that
	 * no session serializer can produce ("php" starts with a key followed by "|",
	 * "php_serialize" with "a:"). Text payloads - the usual case - are left as they
	 * are, so existing rows keep working and nothing grows by a third for nothing.
	 */
	private function encodeData(string $data): string
	{
		// preg_match instead of mb_check_encoding so the package does not depend on ext-mbstring
		return preg_match('//u', $data) === 1
			? $data
			: self::BINARY_PREFIX . base64_encode($data);
	}

	private function decodeData(string $data): string
	{
		if (!str_starts_with($data, self::BINARY_PREFIX)) {
			return $data;
		}

		// Broken base64 means the session cannot be decoded; an empty string turns it
		// into a fresh empty session instead of a fatal error on every request.
		return base64_decode(substr($data, strlen(self::BINARY_PREFIX)), true) ?: '';
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
