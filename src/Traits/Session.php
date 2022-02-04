<?php

namespace ADT\DoctrineSessionHandler\Traits;

use Doctrine\ORM\Mapping as ORM;

trait Session {

	/**
	 * @var string
	 *
	 * @ORM\Column(type="string", nullable=false)
	 */
	public $sessionId;

	/**
	 * @var \DateTime
	 *
	 * @ORM\Column(type="datetime", nullable=false)
	 */
	public $createdAt;

	/**
	 * @var \DateTime
	 *
	 * @ORM\Column(type="datetime", nullable=false)
	 */
	public $expiresAt;

	/**
	 * @var string
	 *
	 * @ORM\Column(type="text", nullable=true)
	 */
	public $data;

}
