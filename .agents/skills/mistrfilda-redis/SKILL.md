---
name: mistrfilda-redis
description: Integrate or maintain mistrfilda/redis connections, locks, native sessions, Nette cache and journal cleanup. Use when working with this package or its application configuration.
---

# Mistrfilda Redis

This skill is self-contained and can be copied into a consuming application's
skill directory. Use the host application's existing DI, console and deployment
conventions. Distinguish application integration from development of the library;
only change the application or infrastructure when the task includes that work.

## Dependencies and scope

- Package: `mistrfilda/redis`; namespace: `Mistrfilda\Redis`.
- Core requirements: PHP 8.4.1+ and native `ext-redis` 6.3+ within 6.x. Do not
  introduce Predis. Connections, locks and native sessions need no framework.
- Nette DI bridge: `nette/di:^3.2.4` and `nette/schema:^1.3`.
- Nette cache storage: `nette/caching:^3.4`. Nette sessions: `nette/http:^3.4`.
- Optional cleanup command: `symfony/console:^8.0` and `nette/caching:^3.4`.
- Supported topology: a standalone Redis or Valkey server with Lua scripting.
  Cluster, Sentinel discovery and automatic failover are outside this API.

Install the package and only the optional components the application needs:

```sh
composer require mistrfilda/redis
composer require nette/di:^3.2.4 nette/schema:^1.3 nette/caching:^3.4 nette/http:^3.4
composer require symfony/console:^8.0
```

## Connections

```php
use Mistrfilda\Redis\Connection\ConnectionConfig;
use Mistrfilda\Redis\Connection\ConnectionFactory;

$config = new ConnectionConfig(
	host: '127.0.0.1',
	port: 6379,
	database: 0,
	timeout: 2.0,
	readTimeout: 2.0,
);
$redis = new ConnectionFactory()->create($config);
```

These are also the defaults. Timeouts are finite positive numbers of seconds.
Optional `username` and `password` support ACL authentication; `password` alone
supports password authentication. Use `scheme: 'tls'` for TLS, otherwise `tcp`.
Supply the hostname or IP without a scheme. Database numbers start at zero.

Creating `ConnectionConfig` does not connect. `ConnectionFactory::create()` opens
a nonpersistent connection, authenticates and selects the configured database.
Cache and locks require raw clients: no automatic serializer, compression,
client prefix, transaction or pipeline. Set prefixes on the cache/lock services
and keep the client's options and selected database stable while using them.

## Distributed locks

```php
use Mistrfilda\Redis\Lock\LockManager;

$locks = new LockManager($redis, prefix: 'my-app:production:lock:');
$lock = $locks->acquire('rebuild-index', ttlMilliseconds: 30000, waitMilliseconds: 2000);
if ($lock === null) {
	return; // Another process holds the lock.
}

try {
	// Perform the protected work within the lease lifetime.
} finally {
	$lock->release();
}
```

- All cooperating pods must use the same server/database, prefix and resource name.
- Defaults: TTL 30000 milliseconds, wait 0 milliseconds (one immediate attempt).
- `acquire()` returns `Lock|null`; `null` means contention after the allowed wait.
  Connection and server errors propagate as exceptions.
- `Lock::refresh($ttlMilliseconds)` extends an owned lease and returns `false`
  after ownership is lost. Check the result before continuing protected work.
- `Lock::release()` returns whether this call removed the owned lock. An expired
  owner cannot release or extend a newer owner's lock.
- `LockManager::synchronized()` runs a callback receiving the acquired `Lock`,
  returns its result and releases in `finally`. It throws
  `Mistrfilda\Redis\Lock\LockUnavailableException` when acquisition fails and
  preserves a callback exception if release also fails.

```php
use Mistrfilda\Redis\Lock\Lock;

$result = $locks->synchronized('rebuild-index', function (Lock $lock): string {
	// For long work, refresh between bounded steps and check the result.
	return 'finished';
}, ttlMilliseconds: 30000, waitMilliseconds: 2000);
```

These are single-server leases using atomic `SET NX PX` and owner-token checks.
Expiry, eviction or restart can remove a lock. There is no automatic background
renewal, fencing or exactly-once execution guarantee; choose the TTL accordingly.

## Native PHP sessions

```php
use Mistrfilda\Redis\Session\NativeSessionConfigurator;

new NativeSessionConfigurator(
	connection: $config,
	prefix: 'my-app:production:session:',
	locking: true,
	lockTtl: 60,
	lockRetries: 100,
	lockWaitMicroseconds: 20000,
)->configure();

ini_set('session.gc_maxlifetime', '3600');
session_start();
$_SESSION['example'] = 'value';
session_write_close();
```

Configure before starting a session. PhpRedis performs reads, writes, expiration,
strict session ID validation and lazy timestamp updates. It opens its own native
session connection; it does not reuse a `Redis` service object. The library does
not implement a custom PHP session handler.

Locking is enabled by default, with the limits shown above. Session lock TTL is
in seconds; the retry interval is in microseconds. Session data lifetime comes
from `session.gc_maxlifetime`, or Nette's `session.expiration`, independently of
the lock TTL. Close sessions promptly when the request no longer needs them.
The generated session save path includes credentials when configured; do not log it.

## Nette DI and NEON

Register the extension and explicitly enable the desired features. Cache,
sessions and locks are all disabled by default. This example uses separate
cache and session databases; connections may also point to separate servers.

```neon
extensions:
	redis: Mistrfilda\Redis\Bridges\Nette\DI\RedisExtension

redis:
	connections:
		default:
			host: 127.0.0.1
			port: 6379
			database: 0
			timeout: 2.0
			readTimeout: 2.0
		sessions:
			host: 127.0.0.1
			port: 6379
			database: 1
	defaultConnection: default
	cache:
		enabled: true
		connection: default
		prefix: 'my-app:production:cache:'
	sessions:
		enabled: true
		connection: sessions
		prefix: 'my-app:production:session:'
		locking: true
		lockTtl: 60
		lockRetries: 100
		lockWaitMicroseconds: 20000
	locks:
		enabled: true
		connection: default
		prefix: 'my-app:production:lock:'

session:
	expiration: 14 days
```

Connection definitions additionally accept `username`, `password` and `scheme`.
Use the application's existing secret/parameter mechanism for authentication.
Connection names are arbitrary; `defaultConnection` selects which client is
autowired. Cache, sessions and locks each independently select their connection.
Their connection selector defaults to the name `default`, so set it explicitly
when using other names. Without custom prefixes, the respective defaults are
`mistrfilda:cache:`, `mistrfilda:session:` and `mistrfilda:lock:`.

With an extension named `redis`, the service names are:

| Service | Purpose |
| --- | --- |
| `redis.connections.<name>.client` | Native `Redis`; only `defaultConnection` is autowired |
| `redis.cache` | Autowired `Nette\Caching\Storage` when enabled |
| `redis.cacheGc` | `redis:cache:gc` command when cache and `cache.registerGcCommand` are enabled |
| `redis.locks` | Autowired `Mistrfilda\Redis\Lock\LockManager` when enabled |

Clients connect when requested, not during DI compilation. Enabling cache
replaces the previous storage's autowiring; disabling cache leaves it intact.
Sessions require an existing `Nette\Http\Session` service. Keep using Nette
sections and `session.expiration`; do not configure a competing session handler.

## Nette cache behavior

Construct `Nette\Caching\Cache` with the injected `Storage` and an application
namespace. Use the public Nette API so it normalizes expiration and dependencies.
For direct construction, use `Mistrfilda\Redis\Bridges\Nette\Caching\RedisStorage`
with a raw `Redis` connection and the chosen `prefix`.

- Values retain PHP types, array keys and nesting. Serializable objects retain
  their class and state, including private/readonly properties and serialization
  hooks. Their classes must be autoloadable in the reading process.
- Ordinary objects are restored as independent instances; enum cases keep their
  enum identity. Modifications are persisted only by another `save()`.
- Top-level `null` means removal or a miss. A closure passed directly to `save()`
  is a generator; a nested closure cannot be serialized. Serialization failures
  propagate and do not replace an existing entry. Resources and nonserializable
  objects are unsuitable values.
- Supports absolute/sliding expiration, tags, priorities, item dependencies,
  file/constant/callback dependencies, namespaces and Nette bulk-operation fallbacks.
- Only `Cache::All`, `Cache::Tags` and `Cache::Priority` cleanup conditions are
  supported. Priority cleanup removes entries at or below the threshold; entries
  without a priority are unaffected. Namespace isolation is not namespace cleanup.
- `Storage::lock()` does not serialize cache generation. Use `LockManager` around
  read-and-build work when mutually exclusive generation is required.

`Cache::All` deliberately executes `FLUSHDB` on the cache connection's database.
It removes everything there, including sessions and locks sharing the database;
prefixes do not limit it. Sharing is a supported choice. Explain this effect when
configuring it rather than silently changing the policy.

The default value serializer uses PHP serialization and assumes trusted writers.
For custom values implement `Mistrfilda\Redis\Bridges\Nette\Caching\Serializer`
with `serialize(mixed $value): string` and `unserialize(string $value): mixed`.
Pass it as `RedisStorage`'s `serializer` argument or set
`redis.cache.serializer: @yourSerializer` in NEON. Internal metadata serialization
is separate and must retain dependency and version information.

## Journal cleanup and console command

Data expires in Redis independently. Shared tag, priority and entry indexes need
periodic garbage collection. A shared tag cannot inherit one member's TTL, since
that would break invalidation for members with a longer lifetime.

With Symfony Console installed and an existing Nette/Contributte Console setup,
enable command registration in the Redis extension:

```neon
redis:
	cache:
		enabled: true
		registerGcCommand: true
```

`registerGcCommand` defaults to `false` to preserve existing manual registrations.
When opting in, remove the application's manual command service and any CI override
that removes it. The extension registers `redis.cacheGc` only when both cache and
command registration are enabled, so a CI override with `cache.enabled: false`
needs no command-service override. Other enabled Redis features still need their
own configuration. Discovery works regardless of the order of Redis and console
extensions. Explicitly enabling the command without Symfony Console installed
fails compilation with an installation hint; Symfony Console otherwise remains
optional. DI compilation never connects to Redis.

For a standalone Symfony Console application, inject the configured storage:

```php
use Mistrfilda\Redis\Bridges\Symfony\Console\CacheGarbageCollectCommand;

$console->addCommands([new CacheGarbageCollectCommand($storage)]);
```

The package supplies the command, not an application bootstrap or executable.
Run the host application's entry point, for example:

```sh
php bin/console redis:cache:gc --no-interaction
```

It uses the injected storage's connection, database and prefix. Success returns
exit code 0 and prints `Checked: 1200; removed: 180; duration: 0.142 s.` with actual
statistics. Failures propagate to Symfony Console and produce a nonzero exit code.

The same operation is available without a console:

```php
$result = $storage->collectGarbage();
$result->checked;
$result->removed;
$result->durationSeconds;
```

The returned `Mistrfilda\Redis\Bridges\Nette\Caching\GarbageCollectionResult` has
integer `checked` and `removed` fields and float `durationSeconds`. Checks count
entry visits, including any duplicates from `SSCAN`; removals count entries whose
stale metadata this invocation actually removed, not individual Redis keys.
`$cache->clean()` without conditions performs this cleanup without returning statistics.

Cleanup scans one storage prefix across all its Nette namespaces. It completes
the full `SSCAN` iteration with a count hint of 100, not a total limit of 100.
For each candidate, Lua atomically checks that its value is absent before removing
metadata. A value rewritten before that check survives; repeated removal is safe
and is not counted twice. Live data, other prefixes, sessions and locks are preserved.
This operation does not call `FLUSHDB`.

Schedule the command externally, for example every 15 minutes, and adjust the
interval to metadata growth and runtime. A system cron entry can be:

```cron
*/15 * * * * cd /path/to/application && php bin/console redis:cache:gc --no-interaction
```

For Kubernetes, reuse the application's image/configuration in a CronJob and set
`spec.schedule: "*/15 * * * *"` and `spec.concurrencyPolicy: Forbid`. The policy
limits overlapping runs of that CronJob; cleanup also tolerates duplicate or
partially completed runs. No extra distributed lock is required for correctness.
If scheduled cleanup stops, values still expire but journal metadata accumulates.

## Maintaining and validating the library

Apply this section in the library checkout. When integrating the dependency into
another application, use that application's validation commands instead; it may
not have the library's development dependencies, tests or Composer scripts.

Keep optional framework dependencies in their bridges. Preserve atomic value/index
writes, conditional removal and owner-token lock checks. For Nette dependencies,
use `Cache::checkCallbacks()` rather than directly invoking Nette's private file
or constant validators. Exercise Nette-facing changes through `Nette\Caching\Cache`
as well as storage-level tests where appropriate.

Use tabs, the package's PHPCS/Slevomat configuration and `*Test.php` test names.
PHPStan runs at maximum level with strict, deprecation, Nette and PHPUnit rules.
Initial development setup and unit tests:

```sh
composer install
mkdir -p tmp
composer test-unit
```

Integration tests use real Redis commands, `FLUSHDB`, test ACL users and separate
PHP processes. They clear the selected database and also database 14 or 15 for
session-isolation tests. Create a disposable server and explicitly provide
`REDIS_TEST_HOST`, `REDIS_TEST_PORT` and `REDIS_TEST_DATABASE`. Never point this
suite at an application's Redis. If the test container cannot bind its port,
choose another unused port before proceeding.

```sh
docker run --detach --rm --name redis-library-valkey \
	-p 127.0.0.1:16379:6379 valkey/valkey:9.0.7-alpine

composer cs-fix
REDIS_TEST_HOST=127.0.0.1 \
REDIS_TEST_PORT=16379 \
REDIS_TEST_DATABASE=15 \
composer build-all

docker stop redis-library-valkey
```

After PHP or NEON changes, finish with `composer cs-fix` and `composer build-all`
against that disposable server. The build validates Composer metadata, PHP syntax,
PHPStan, PHPCS, unit tests and integration tests. For documentation-only changes,
check the skill's frontmatter and examples without starting integration services.
