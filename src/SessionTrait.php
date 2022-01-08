<?php

namespace ADT\DoctrineSessionHandler;

use ADT\DoctrineSessionHandler\SessionInterface;
use Doctrine\ORM\Mapping as ORM;

trait SessionTrait
{
	/**
	 * @ORM\Column(name="sessionId", type="string", nullable=false)
	 */
	public string $sessionId;

	/**
	 * @ORM\Column(name="createdAt", type="datetime", nullable=false)
	 */
	public \DateTime $createdAt;

	/**
	 * @ORM\Column(name="expires_at", type="datetime", nullable=false)
	 */
	public \DateTime $expiresAt;

	/**
	 * @ORM\Column(name="data", type="text", nullable=true)
	 */
	public string $data;

	public function getSessionId(): string
	{
		return $this->sessionId;
	}

	public function setSessionId(string $sessionId): SessionInterface
	{
		$this->sessionId = $sessionId;
		return $this;
	}

	public function getCreatedAt(): \DateTime
	{
		return $this->createdAt;
	}

	public function setCreatedAt(\DateTime $createdAt): SessionInterface
	{
		$this->createdAt = $createdAt;
		return $this;
	}

	public function getExpiresAt(): \DateTime
	{
		return $this->expiresAt;
	}

	public function setExpiresAt(\DateTime $expiresAt): SessionInterface
	{
		$this->expiresAt = $expiresAt;
		return $this;
	}

	public function getData(): string
	{
		return $this->data;
	}

	public function setData(string $data): SessionInterface
	{
		$this->data = $data;
		return $this;
	}
}
