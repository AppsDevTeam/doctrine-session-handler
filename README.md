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

## Index on `expiresAt` (required)

Add an index on the `expiresAt` column. Cleaning up expired sessions filters by it,
and without the index it degrades into a full table scan over the whole (typically
large) session table:

```php
#[ORM\Table(name: 'session_storage')]
#[ORM\Index(columns: ['expires_at'])]
#[ORM\Entity]
class SessionStorage extends BaseEntity implements SessionInterface
```

An index cannot be declared in a trait (it is a class-level attribute), so it has to
be added to your entity.

## Cleaning up expired sessions

PHP's inline garbage collection (`session.gc_probability`) deletes **all** expired
sessions in a single statement in the middle of a request. On a large session table
that holds locks over a wide range and on a **Galera cluster** it overflows the
write-set limit:

```
SQLSTATE[HY000]: General error: 1180 Got error 5 - 'size_exceeded_error' during COMMIT
SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded
```

The cleanup then keeps failing, expired sessions are never removed, the table grows
without bound and session I/O saturates PHP-FPM.

Therefore: **turn off inline GC and clean up from cron in batches.**

```neon
services:
	sessionCleaner: ADT\DoctrineSessionHandler\SessionCleaner(\App\Entity\SessionStorage)
	- ADT\DoctrineSessionHandler\CleanupCommand
```

```ini
; php.ini / pool config
session.gc_probability = 0
```

```bash
# cron, e.g. every 10 minutes
php bin/console session:cleanup

# options
php bin/console session:cleanup --batch-size=5000 --sleep=0.2 -v
php bin/console session:cleanup --max-batches=10      # bounded run
```

`Handler::gc()` (inline GC) is batched too and by default deletes **at most one
batch**, so even when it does run it cannot overflow the write-set. You can tune it
via the constructor:

```neon
services:
	sessionHandler: ADT\DoctrineSessionHandler\Handler(\App\Entity\SessionStorage, gcBatchSize: 1000, gcMaxBatches: 1)
```

### Keep the session table small

Sessions are created for every visitor without a cookie (including bots), so also
consider a short `session.expiration` and avoid touching the session on requests
that do not need it — anything writing to the session (flash messages,
`storeRequest()`, CSRF tokens) creates a row.
