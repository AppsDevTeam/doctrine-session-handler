<?php

declare(strict_types=1);

namespace ADT\DoctrineSessionHandler\Tests\Fixtures;

use ADT\DoctrineSessionHandler\SessionInterface;
use ADT\DoctrineSessionHandler\SessionTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class SessionStorage implements SessionInterface
{
	use SessionTrait;

	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	public int $id;
}
