<?php

namespace ADT\DoctrineSessionHandler;

use Doctrine\ORM\Mapping as ORM;

interface SessionInterface 
{
	public function getSessionId(): string;
	public function setSessionId(string $sessionId): SessionInterface;
	public function getCreatedAt(): \DateTime;
	public function setCreatedAt(\DateTime $createdAt): SessionInterface;
	public function getExpiresAt(): \DateTime;
	public function setExpiresAt(\DateTime $expiresAt): SessionInterface;
	public function getData(): string;
	public function setData(string $data): SessionInterface;
}
