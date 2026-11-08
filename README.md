# Redis

Redis and Valkey connections, distributed locks and native PHP sessions using
`ext-redis`, with optional Nette cache/DI and Symfony Console integration.

## Installation

Requires PHP 8.4.1+, PhpRedis 6.3+ within 6.x, and a standalone Redis or Valkey
server with Lua scripting. The core has no framework dependencies.

```sh
composer require mistrfilda/redis
```

## Connections and locks

```php
use Mistrfilda\Redis\Connection\ConnectionConfig;
use Mistrfilda\Redis\Connection\ConnectionFactory;
use Mistrfilda\Redis\Lock\LockManager;

$redis = new ConnectionFactory()->create(new ConnectionConfig(host: '127.0.0.1'));
$locks = new LockManager($redis, prefix: 'my-app:lock:');

$locks->synchronized('rebuild-index', function (): void {
	// Rebuild the index.
}, ttlMilliseconds: 30000);
```

Use the same prefix and resource name in all pods. Finish within the lock TTL,
or explicitly refresh the lock during longer work. See [lock usage](docs/usage.md#distributed-locks).

## Nette

Install the components you use: DI/schema for the extension, caching for cache,
and HTTP for sessions.

```sh
composer require nette/di:^3.2.4 nette/schema:^1.3 nette/caching:^3.4 nette/http:^3.4
```

```neon
extensions:
	redis: Mistrfilda\Redis\Bridges\Nette\DI\RedisExtension

redis:
	connections:
		default:
			host: 127.0.0.1
			database: 0
		sessions:
			host: 127.0.0.1
			database: 1
	cache:
		enabled: true
		prefix: 'my-app:cache:'
	sessions:
		enabled: true
		connection: sessions
		prefix: 'my-app:session:'
	locks:
		enabled: true
		prefix: 'my-app:lock:'

session:
	expiration: 14 days
```

Cache, sessions and locks are opt-in. Use Nette Cache and session sections as
usual; the extension supplies the storage and native session configuration.
Connections can point to the same or different servers/databases.

**`Cache::All` flushes the entire cache database**, including sessions and locks
if they share it. Separate databases, as above, keep those values independent.

## Cache maintenance

Schedule journal cleanup to remove metadata left by expired cache values.
For the optional console command, install Symfony Console:

```sh
composer require symfony/console:^8.0
```

For Nette with Contributte Console, enable registration in the Redis extension:

```neon
redis:
	cache:
		enabled: true
		registerGcCommand: true
```

The command is registered only while cache is enabled. A CI override with
`redis.cache.enabled: false` also omits the command. Registration defaults to
`false`; remove an existing manual command service when opting in.

```sh
php bin/console redis:cache:gc --no-interaction
```

Run it from cron or a Kubernetes CronJob, for example every 15 minutes. It reports
checks, removed entries and elapsed time. Scheduling belongs to the application;
see [cache maintenance](docs/cache-maintenance.md) for details and the PHP API.

## More information

- [Usage reference](docs/usage.md): connection options, locks, sessions and Nette cache behavior.
- [Development](docs/development.md): tests and coding checks.
- [Agent skill](.agents/skills/mistrfilda-redis/SKILL.md): guidance for integrating and maintaining this package.
