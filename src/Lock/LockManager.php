<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Lock;

use InvalidArgumentException;
use Mistrfilda\Redis\Connection\ClientValidator;
use Redis;
use Throwable;

final readonly class LockManager
{

	public function __construct(private Redis $redis, private string $prefix = 'mistrfilda:lock:')
	{
		ClientValidator::requireRawConnection($redis);
		if ($prefix === '') {
			throw new InvalidArgumentException('Lock prefix must not be empty.');
		}
	}

	/** @phpstan-impure */
	public function acquire(string $resource, int $ttlMilliseconds = 30000, int $waitMilliseconds = 0): Lock|null
	{
		if ($resource === '' || $ttlMilliseconds < 1 || $waitMilliseconds < 0) {
			throw new InvalidArgumentException(
				'Lock resource must be nonempty, TTL positive and wait timeout nonnegative.',
			);
		}

		$key = $this->prefix . hash('sha256', $resource);
		$owner = bin2hex(random_bytes(16));
		$deadline = hrtime(true) + $waitMilliseconds * 1000000;

		do {
			$started = hrtime(true);
			if ($this->redis->set($key, $owner, ['nx', 'px' => $ttlMilliseconds]) === true) {
				$lock = new Lock($this->redis, $key, $owner);
				if (hrtime(true) - $started < $ttlMilliseconds * 1000000) {
					return $lock;
				}

				$lock->release();
			}

			$remaining = $deadline - hrtime(true);
			if ($remaining <= 0) {
				return null;
			}

			usleep((int) min(random_int(1000, 10000), ceil($remaining / 1000)));
		} while (hrtime(true) < $deadline);

		return null;
	}

	/**
	 * @template T
	 * @param callable(Lock): T $callback
	 * @return T
	 */
	public function synchronized(
		string $resource,
		callable $callback,
		int $ttlMilliseconds = 30000,
		int $waitMilliseconds = 0,
	): mixed
	{
		$lock = $this->acquire($resource, $ttlMilliseconds, $waitMilliseconds);
		if ($lock === null) {
			throw new LockUnavailableException(sprintf('Could not acquire lock "%s".', $resource));
		}

		$failure = null;
		try {
			return $callback($lock);
		} catch (Throwable $exception) {
			$failure = $exception;

			throw $exception;
		} finally {
			try {
				$lock->release();
			} catch (Throwable $exception) {
				if ($failure === null) {
					throw $exception;
				}
			}
		}
	}

}
