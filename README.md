# doctrine-session-handler

```bash
composer require adt/doctrine-session-handler
```

```php
<?php

namespace App\Entity;

use ADT\DoctrineSessionHandler\Traits\Session;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table(
 *		name="session_storage",
 *		uniqueConstraints={
 *			@ORM\UniqueConstraint(name="sessionId", columns={"sessionId"})
 * 		}
 * )
 * @ORM\Entity
 */
class SessionStorage extends BaseEntity {

	use Session;

	/**
	 * @var integer
	 *
	 * @ORM\Column(name="id", type="integer", nullable=false)
	 * @ORM\Id
	 * @ORM\GeneratedValue(strategy="IDENTITY")
	 */
	public $id;

}
```

```neon
services:
	sessionHandler: ADT\DoctrineSessionHandler\Handler(\App\Entity\SessionStorage)

session:
	autoStart: smart
	handler: @sessionHandler
```
