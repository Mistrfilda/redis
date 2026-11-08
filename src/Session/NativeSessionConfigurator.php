<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Session;

use InvalidArgumentException;
use LogicException;
use Mistrfilda\Redis\Connection\ConnectionConfig;
use RuntimeException;
use const PHP_SESSION_ACTIVE;

final readonly class NativeSessionConfigurator
{

	public function __construct(
		private ConnectionConfig $connection,
		private string $prefix = 'mistrfilda:session:',
		private bool $locking = true,
		private int $lockTtl = 60,
		private int $lockRetries = 100,
		private int $lockWaitMicroseconds = 20000,
	)
	{
		if ($prefix === '' || $lockTtl < 1 || $lockRetries < 0 || $lockWaitMicroseconds < 1) {
			throw new InvalidArgumentException(
				'Session prefix must be nonempty and session lock settings must have valid positive limits.',
			);
		}
	}

	/** @return array{save_handler: string, save_path: string} */
	public function getOptions(): array
	{
		return [
			'save_handler' => 'redis',
			'save_path' => $this->connection->getSessionSavePath($this->prefix),
		];
	}

	public function configure(): void
	{
		if (session_status() === PHP_SESSION_ACTIVE) {
			throw new LogicException('Redis sessions must be configured before the session starts.');
		}

		$options = [
			'session.save_handler' => 'redis',
			'session.save_path' => $this->connection->getSessionSavePath($this->prefix),
			'redis.session.locking_enabled' => $this->locking ? '1' : '0',
			'redis.session.lock_expire' => (string) $this->lockTtl,
			'redis.session.lock_retries' => (string) $this->lockRetries,
			'redis.session.lock_wait_time' => (string) $this->lockWaitMicroseconds,
		];

		foreach ($options as $name => $value) {
			if (ini_set($name, $value) === false) {
				throw new RuntimeException(sprintf('Could not configure PHP option "%s".', $name));
			}
		}
	}

}
