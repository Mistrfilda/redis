# Usage reference

Start with the [README](../README.md) for installation and a minimal setup.

## Connections without a framework

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
$redis->set('example', 'value');
```

Both timeouts are in seconds. Optional `username` and `password` support ACL
authentication; `password` alone supports password authentication. Use
`scheme: 'tls'` for TLS and supply a hostname without a scheme in `host`.

Creating a configuration does not open a connection. `ConnectionFactory::create()`
opens a new, nonpersistent connection and selects the database.

Cache and locks require raw string values and immediate command execution.
Use connections from this factory, or an equivalent unmodified `Redis` object:
no automatic serializer, compression, client prefix, transaction or pipeline.
Pass key prefixes to the cache or lock service instead. Do not subsequently
change the client's options or selected database while these services use it.

## Distributed locks

```php
use Mistrfilda\Redis\Lock\LockManager;

$locks = new LockManager($redis, prefix: 'my-app:production:lock:');
$lock = $locks->acquire(
	'rebuild-product-index',
	ttlMilliseconds: 30000,
	waitMilliseconds: 2000,
);

if ($lock === null) {
	return; // Another process holds the lock.
}

try {
	// Perform the work protected by the lock.
} finally {
	$lock->release();
}
```

The resource name and prefix must be the same in all cooperating processes.
Acquisition uses an atomic `SET NX PX`. Release and refresh compare the random
owner token atomically before changing the key. An expired owner cannot release
or extend a newer owner's lock.

- `acquire()` returns `null` if the lock is busy after the allowed wait.
- The default wait is zero, so acquisition makes one immediate attempt.
- `refresh($ttlMilliseconds)` returns `false` if ownership was lost.
- `release()` returns whether this call removed the owned lock.
- Connection and server errors propagate as exceptions.

The callback helper releases the lock even when the callback throws:

```php
use Mistrfilda\Redis\Lock\Lock;

$result = $locks->synchronized(
	'rebuild-product-index',
	function (Lock $lock): string {
		// For long tasks, call refresh() between bounded steps and check its result.
		return 'finished';
	},
	ttlMilliseconds: 30000,
	waitMilliseconds: 2000,
);
```

The helper throws `LockUnavailableException` when acquisition times out.
It preserves an exception from the callback if release also fails.

Locks expire independently of the PHP process. Finish or refresh before the TTL
elapses. Eviction, restart or failover can also remove a lock. This is a
single-server lease, not an exactly-once execution guarantee; the library does
not implement fencing or automatic background renewal.

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

Configure the handler before starting a session. PhpRedis performs the actual
session reads, writes, expiration and per-session locking. It opens its own
connection using the same connection settings; it does not reuse a `Redis`
service object.

Session lifetime comes from `session.gc_maxlifetime`. Lock TTL is a separate
duration in seconds, and the retry interval is in microseconds. Locking is
enabled by default. Close sessions promptly when the request no longer needs
them. The native handler also supports strict session IDs and lazy timestamp
updates.

## Nette configuration

Register the extension and choose the features to enable. All three features
are disabled by default.

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

Connections may point to the same server/database or different servers.
Connection settings also accept `username`, `password` and `scheme`.
Use application parameters for credentials. The generated session save path
contains authentication details when configured, so do not log it.

The extension registers:

| Service | Purpose |
| --- | --- |
| `redis.connections.<name>.client` | Native `Redis` client; only `defaultConnection` is autowired |
| `redis.cache` | Autowired `Nette\Caching\Storage`, when enabled |
| `redis.locks` | Autowired `LockManager`, when enabled |

Clients connect when their services are first requested, not during DI
compilation. Enabling the cache disables autowiring of the previous cache
storage. Enabling sessions configures the existing `Nette\Http\Session`
service and its native Redis handler. Keep using Nette session sections and
`session.expiration` as usual; do not configure another session handler at the
same time.

## Nette cache behavior

Use `Nette\Caching\Cache` normally, with the injected `Storage`. The adapter
supports:

- PHP values and serializable objects
- Absolute and sliding expiration
- Tags and priorities, including tag entries with different TTLs
- Item dependencies, files, constants and serializable callbacks
- Namespace isolation and Nette's bulk-operation fallbacks
- Custom value serialization

`Cache::All` **runs FLUSHDB on the cache connection's selected database**.
Everything in that database is removed, including sessions and locks if they
share it. This is intentional; select separate connections/databases when those
keys must survive a cache clear. Prefixes do not limit FLUSHDB.

Journal updates are atomic with the entry write. Indexes remain until entries
are removed or garbage-collected, so one short-lived entry cannot expire a tag
shared by long-lived entries. Use the [garbage collection command or PHP API](cache-maintenance.md) periodically
to collect index metadata left by expired or evicted entries.
`$cache->clean()` without conditions remains available for the same cleanup.
This cleanup is not automatic. Without periodically calling `clean()`, journal
metadata for expired entries accumulates even though the cached values expire.
A shared tag cannot inherit one member's TTL: that would lose the tag index for
other members with a longer lifetime or no expiration.

`Storage::lock()` does not serialize cache generation. Use `LockManager`
around a read-and-build operation if exclusive generation is required.
Only `All`, `Tags` and `Priority` cleanup conditions are supported;
namespace isolation is not namespace-based cleanup.

The default serializer uses PHP serialization. Only trusted applications should
write to the cache database. To change value serialization, implement
`Mistrfilda\Redis\Bridges\Nette\Caching\Serializer`, pass it to
`RedisStorage`, or configure `redis.cache.serializer: @yourSerializer`.
Entry metadata is managed internally and is not affected by that serializer.

An ordinary serialized object is restored as a new instance of the same class,
which must be autoloadable in the reading process. Enum cases retain their enum
identity. PHP restores object properties and honors
`__serialize()` / `__unserialize()` hooks. Modifying the original or loaded object
does not update the cache until `save()` is called again. Arrays retain their key
types, value types and nesting; objects within them are serialized too. Resources
and objects with nonserializable state are not suitable cache values.

Nette treats a top-level `null` as removal or a cache miss. A closure passed
directly to `Cache::save()` is a generator whose result is cached; a closure nested
inside an array or object cannot be serialized by the default serializer. Such a
serialization error propagates and leaves an existing cache entry unchanged.
