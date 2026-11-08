# Cache maintenance

Redis expires cached values automatically. The journal's shared tag, priority
and entry indexes need periodic cleanup. Run garbage collection for each cache
storage prefix you use. It covers all Nette namespaces within that storage.

## Console command

The optional command requires `symfony/console:^8.0` and `nette/caching:^3.4`.
The library does not provide an application bootstrap or a `bin/console` executable;
use the host application's existing console integration.

With Nette and Contributte Console, let `RedisExtension` register the command:

```neon
redis:
	cache:
		enabled: true
		registerGcCommand: true
```

`registerGcCommand` defaults to `false` for compatibility with applications that
already register the command manually. When opting in, remove that manual service
definition. With an extension named `redis`, the new service is `redis.cacheGc`
and uses `redis.cache`. It is available to console discovery regardless of the
order of the Redis and console extensions.

Both `cache.enabled` and `cache.registerGcCommand` must be `true`. When cache is
disabled, no command service or reference to the cache service is registered.
For CI, override only the features that need Redis, such as `cache.enabled: false`;
no `services.redisGc` removal is needed after migrating to extension registration.
Other enabled Redis features still require Redis when their services are used.

Symfony Console remains optional. If command registration and cache are enabled
without Symfony Console installed, compilation fails with an installation hint.
Compilation itself never connects to Redis.

For another Symfony Console application, inject your configured `RedisStorage` directly:

```php
use Mistrfilda\Redis\Bridges\Symfony\Console\CacheGarbageCollectCommand;

$console->addCommands([new CacheGarbageCollectCommand($storage)]);
```

Run the application entry point, for example:

```sh
php bin/console redis:cache:gc --no-interaction
```

Example output:

```text
Checked: 1200; removed: 180; duration: 0.142 s.
```

The command uses the injected storage's connection, database and prefix. It does
not accept separate connection credentials. A successful run returns exit code
0. Failures propagate to Symfony Console's exception handling and result in a
nonzero exit code; a failed run does not print a success summary.

## PHP API

```php
$result = $storage->collectGarbage();

$result->checked;
$result->removed;
$result->durationSeconds;
```

`RedisStorage::collectGarbage()` returns `GarbageCollectionResult`:

- `checked`: journal entry visits, including any repeats returned by `SSCAN`.
- `removed`: entries whose stale metadata this run actually removed, not the
  number of individual Redis keys or set members deleted.
- `durationSeconds`: elapsed time measured with a monotonic clock.

`$cache->clean()` without conditions performs the same cleanup and discards the
statistics, preserving the Nette `Storage` contract.

## Scheduling

A starting interval is every 15 minutes; adjust it to journal growth and runtime.
For a system cron entry:

```cron
*/15 * * * * cd /path/to/application && php bin/console redis:cache:gc --no-interaction
```

For Kubernetes, use the application's image and configuration in the job template,
with these `CronJob.spec` settings:

```yaml
schedule: "*/15 * * * *"
concurrencyPolicy: Forbid
```

`Forbid` limits overlapping runs from that CronJob. Repeating the cleanup is safe,
including after a partially completed run. A separate distributed lock is not
required for correctness. Kubernetes can still duplicate or miss a scheduled
execution; see the [CronJob documentation](https://kubernetes.io/docs/concepts/workloads/controllers/cron-jobs/).

## Cleanup behavior

The collector scans the storage's entry index using `SSCAN` with a count hint of
100 and completes the full cursor iteration. This is not a 100-entry limit for
the entire run. Memory usage is limited to scan responses; total runtime depends
on the number of indexed entries and Redis latency.

For each candidate, a Lua operation checks that the cached value is absent before
removing its metadata from tag sets, the priority index and the entry index.
A value rewritten before this check is preserved. Repeated removal of metadata
already collected by another invocation does not increase the removal count.

Live values, other storage prefixes, sessions and locks remain intact. Garbage
collection does not call `FLUSHDB`. Shared tag indexes have no per-entry TTL:
expiring a shared index with its shortest-lived member would break invalidation
of longer-lived members.

Redis continues expiring data independently of this command. If scheduled cleanup
stops, expired values remain unavailable but their metadata can accumulate.
