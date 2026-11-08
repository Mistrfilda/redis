<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Lock;

use InvalidArgumentException;
use Redis;

final class Lock
{

	private bool $released = false;

	/** @internal Locks are created by LockManager. */
	public function __construct(
		private readonly Redis $redis,
		private readonly string $key,
		private readonly string $owner,
	)
	{
	}

	public function release(): bool
	{
		if ($this->released) {
			return false;
		}

		$result = $this->redis->eval(
			"if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) end return 0",
			[$this->key, $this->owner],
			1,
		);
		$this->released = true;

		return $result === 1;
	}

	public function refresh(int $ttlMilliseconds): bool
	{
		if ($ttlMilliseconds < 1) {
			throw new InvalidArgumentException('Lock TTL must be a positive number of milliseconds.');
		}

		if ($this->released) {
			return false;
		}

		return $this->redis->eval(
			"if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('PEXPIRE', KEYS[1], ARGV[2]) end return 0",
			[$this->key, $this->owner, $ttlMilliseconds],
			1,
		) === 1;
	}

}
