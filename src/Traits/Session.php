<?php

namespace ADT\DoctrineSessionHandler\Traits;

use Doctrine\ORM\Mapping as ORM;

trait Session {

	/**
	 * @var string
	 *
	 * @ORM\Column(type="string", nullable=false)
	 */
	public string $sessionId;

	/**
	 * @var \DateTime
	 *
	 * @ORM\Column(type="datetime", nullable=false)
	 */
	public \DateTime $createdAt;

	/**
	 * @var \DateTime
	 *
	 * @ORM\Column(type="datetime", nullable=false)
	 */
	public \DateTime $expiresAt;

	/**
	 * @var string|null
	 *
	 * @ORM\Column(type="text", nullable=true)
	 */
	public ?string $data;

}
