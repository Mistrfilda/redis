<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Bridges\Nette\Caching;

use InvalidArgumentException;
use Mistrfilda\Redis\Connection\ClientValidator;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Redis;
use UnexpectedValueException;

final readonly class RedisStorage implements Storage
{

	private RedisJournal $journal;

	public function __construct(
		private Redis $redis,
		private string $prefix = 'mistrfilda:cache:',
		private Serializer $serializer = new PhpSerializer(),
	)
	{
		if ($prefix === '') {
			throw new InvalidArgumentException('Cache prefix must not be empty.');
		}

		ClientValidator::requireRawConnection($redis);

		$this->journal = new RedisJournal($redis, $prefix . 'journal:');
	}

	public function read(string $key): mixed
	{
		$payload = $this->redis->get($this->entryKey($key));
		$entry = $this->decode($payload);
		if ($entry === null || !is_string($payload)) {
			return null;
		}

		if (!$this->isValid($key, $entry, $payload, [])) {
			$this->journal->remove($this->entryKey($key), expectedPayload: $payload);
			return null;
		}

		return $this->serializer->unserialize($entry->value);
	}

	public function lock(string $key): void
	{
		// Like other remote Nette storages, generation is not locked. Use LockManager when mutual exclusion is needed.
	}

	/** @param array<string, mixed> $dependencies */
	public function write(string $key, mixed $data, array $dependencies): void
	{
		$ttl = $dependencies[Cache::Expire] ?? 0;
		if (!is_int($ttl) && !is_float($ttl)) {
			throw new InvalidArgumentException(
				'Cache expiration must be a number of seconds normalized by Nette Cache.',
			);
		}

		$ttl = (int) $ttl;
		if (array_key_exists(Cache::Expire, $dependencies) && $ttl <= 0) {
			$this->remove($key);
			return;
		}

		$items = [];
		foreach ($this->strings($dependencies[Cache::Items] ?? []) as $item) {
			$items[$item] = $this->decode($this->redis->get($this->entryKey($item)))?->version;
		}

		$callbacks = $dependencies[Cache::Callbacks] ?? [];
		$this->validateCallbacks($callbacks);
		$priority = $dependencies[Cache::Priority] ?? null;
		if ($priority !== null && !is_int($priority)) {
			throw new InvalidArgumentException('Cache priority must be an integer.');
		}

		$entry = new CacheEntry(
			$this->serializer->serialize($data),
			bin2hex(random_bytes(16)),
			$items,
			$callbacks,
			($dependencies[Cache::Sliding] ?? false) === true ? $ttl : 0,
		);
		$this->journal->write(
			$this->entryKey($key),
			serialize($entry),
			$ttl,
			$this->strings($dependencies[Cache::Tags] ?? []),
			$priority,
		);
	}

	public function remove(string $key): void
	{
		$this->journal->remove($this->entryKey($key));
	}

	/** @param array<string, mixed> $conditions */
	public function clean(array $conditions): void
	{
		if (($conditions[Cache::All] ?? false) === true) {
			$this->redis->flushDB();
			return;
		}

		foreach ($this->strings($conditions[Cache::Tags] ?? []) as $tag) {
			$this->journal->cleanTag($tag);
		}

		if (isset($conditions[Cache::Priority])) {
			if (!is_int($conditions[Cache::Priority])) {
				throw new InvalidArgumentException('Cache priority must be an integer.');
			}

			$this->journal->cleanPriority($conditions[Cache::Priority]);
		}

		if ($conditions === []) {
			$this->collectGarbage();
		}
	}

	public function collectGarbage(): GarbageCollectionResult
	{
		return $this->journal->collectGarbage();
	}

	/** @param array<string, string> $visited */
	private function isValid(string $key, CacheEntry $entry, string $payload, array $visited): bool
	{
		$visited[$key] = $entry->version;

		if (!Cache::checkCallbacks($entry->callbacks)) {
			return false;
		}

		foreach ($entry->items as $itemKey => $version) {
			if (isset($visited[$itemKey])) {
				if ($visited[$itemKey] !== $version) {
					return false;
				}

				continue;
			}

			$itemPayload = $this->redis->get($this->entryKey($itemKey));
			$item = $this->decode($itemPayload);
			if ($item?->version !== $version) {
				return false;
			}

			if ($item !== null && is_string($itemPayload) && !$this->isValid($itemKey, $item, $itemPayload, $visited)) {
				return false;
			}
		}

		if ($entry->slidingSeconds > 0) {
			$this->redis->eval(
				"if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('EXPIRE', KEYS[1], ARGV[2]) end return 0",
				[$this->entryKey($key), $payload, $entry->slidingSeconds],
				1,
			);
		}

		return true;
	}

	private function decode(mixed $payload): CacheEntry|null
	{
		if (!is_string($payload)) {
			return null;
		}

		$entry = @unserialize($payload);
		return $entry instanceof CacheEntry ? $entry : null;
	}

	/** @phpstan-assert list<array{0: callable, 1?: mixed, 2?: mixed}> $callbacks */
	private function validateCallbacks(mixed $callbacks): void
	{
		if (!is_array($callbacks) || !array_is_list($callbacks)) {
			throw new InvalidArgumentException('Cache callbacks must be a list.');
		}

		foreach ($callbacks as $callback) {
			if (!is_array($callback) || !array_is_list($callback) || count($callback) < 1 || count($callback) > 3) {
				throw new InvalidArgumentException('Cache callbacks must contain a callable and up to two arguments.');
			}

			// Nette evaluates its private validators inside Cache::checkCallbacks().
			if (
				!is_callable($callback[0])
				&& !in_array($callback[0], [[Cache::class, 'checkFile'], [Cache::class, 'checkConst']], true)
			) {
				throw new InvalidArgumentException('Invalid cache dependency callback.');
			}
		}
	}

	/** @return list<string> */
	private function strings(mixed $values): array
	{
		if (!is_array($values)) {
			throw new InvalidArgumentException('Cache tags and item dependencies must be arrays.');
		}

		$result = [];
		foreach ($values as $value) {
			if (!is_string($value)) {
				throw new UnexpectedValueException('Cache tags and item dependencies must contain strings.');
			}

			$result[] = $value;
		}

		return $result;
	}

	private function entryKey(string $key): string
	{
		return $this->prefix . 'entry:' . hash('sha256', $key);
	}

}
