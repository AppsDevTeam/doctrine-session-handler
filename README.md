# doctrine-session-handler

```bash
composer require adt/doctrine-session-handler
```

```php
<?php

namespace App\Entity;

use ADT\DoctrineSessionHandler\SessionInterface;
use ADT\DoctrineSessionHandler\SessionTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 */
#[ORM\Entity]
class SessionStorage extends BaseEntity implements SessionInterface {

	use SessionTrait;

	/**
	 * @var integer
	 *
	 * @ORM\Id
	 * @ORM\Column(type="integer", nullable=false)
	 * @ORM\GeneratedValue
	 */
	#[ORM\Id]
	#[ORM\Column(nullable: false)]
	#[ORM\GeneratedValue]
	public int $id;

}
```

```neon
services:
	sessionHandler: ADT\DoctrineSessionHandler\Handler(\App\Entity\SessionStorage)

session:
	autoStart: smart
	handler: @sessionHandler
```
