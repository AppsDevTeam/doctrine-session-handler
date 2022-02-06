# doctrine-session-handler

```bash
composer require adt/doctrine-session-handler
```

Use `columns={<column_name>}` according to your naming strategy in `uniqueConstraints` definition.

```php
<?php

namespace App\Entity;

use ADT\DoctrineSessionHandler\SessionInterface;
use ADT\DoctrineSessionHandler\SessionTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table(
 *		uniqueConstraints={
 *			@ORM\UniqueConstraint(columns={"sessionId"})
 * 		}
 * )
 * @ORM\Entity
 */
class SessionStorage extends BaseEntity implements SessionInterface {

	use SessionTrait;

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
