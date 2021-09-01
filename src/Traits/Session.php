<?php

namespace ADT\DoctrineSessionHandler\Traits;

use Doctrine\ORM\Mapping as ORM;

trait Session {

	/**
	 * @var string
	 *
	 * @ORM\Column(name="sessionId", type="string", nullable=false)
	 */
	public $sessionId;

	/**
	 * @var \DateTime
	 *
	 * @ORM\Column(name="createdAt", type="datetime", nullable=false)
	 */
	public $createdAt;

	/**
	 * @var \DateTime
	 *
	 * @ORM\Column(name="expires_at", type="datetime", nullable=false)
	 */
	public $expiresAt;

	/**
	 * @var string
	 *
	 * @ORM\Column(name="data", type="text", nullable=true)
	 */
	public $data;

}
