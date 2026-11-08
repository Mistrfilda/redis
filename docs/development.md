# Development

The package targets PHP 8.4.1+ and PhpRedis 6.3.x. Runtime dependencies are PHP and
`ext-redis`; Nette and Symfony Console are optional bridges and development
dependencies. Keep core connections, locks and sessions usable without them.

```sh
composer install
mkdir -p tmp
composer test-unit
```

Use tabs and the package's PHPCS/Slevomat configuration. PHPStan runs at maximum
level with strict, deprecation, Nette and PHPUnit rules. After PHP or NEON changes,
run `composer cs-fix` and `composer build-all` with the test-server variables below.

## Integration tests

Integration tests use real Redis commands, `FLUSHDB`, ACL test users and separate
PHP processes. Point them at a disposable server only. They clear the configured
database and also database 14 or 15 for session-isolation tests. They fail if any
of `REDIS_TEST_HOST`, `REDIS_TEST_PORT` or `REDIS_TEST_DATABASE` is missing.

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

`build-all` runs Composer validation, PHP syntax checks, PHPStan, PHPCS, unit tests
and integration tests. CI runs PHP 8.4 and 8.5 against a disposable Valkey service.
The implementation is also exercised locally against Redis 8.x.

Tests are grouped by behavior:

- `tests/Unit`: connection validation, serialization, DI compilation and command failure handling.
- `tests/Integration/NetteCacheTest.php`: values, objects, cross-process loading and journal cleanup through Nette Cache.
- `tests/Integration/RedisStorageTest.php`: tags, priorities, dependencies, expiration and custom serialization.
- `tests/Integration/CacheGarbageCollectCommandTest.php`: cleanup scope, statistics and repeated execution.
- Other integration tests cover native sessions, Nette wiring, ACL authentication and process-level locking.

## Documentation and skills

Keep the README focused on installation and a working setup. Human-facing details
live in `docs/`. `.agents/skills/mistrfilda-redis/SKILL.md` is self-contained so it
can be copied into another project's skill directory without these documentation
files. Keep its API guidance and examples in sync with the implementation. Preserve
the skill in Composer distributions, but do not assume agents automatically
discover it inside `vendor/`.
