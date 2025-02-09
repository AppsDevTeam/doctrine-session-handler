<?php

namespace ADT\DoctrineSessionHandler;

use Doctrine\ORM\Mapping as ORM;

trait SessionTrait
{
	#[ORM\Column(unique: true)]
	public string $sessionId;

	#[ORM\Column]
	public \DateTime $createdAt;

	#[ORM\Column]
	public \DateTime $expiresAt;

	#[ORM\Column(type: 'text')]
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
