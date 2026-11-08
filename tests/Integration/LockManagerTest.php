<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Tests\Integration;

use Mistrfilda\Redis\Lock\Lock;
use Mistrfilda\Redis\Lock\LockManager;
use Mistrfilda\Redis\Lock\LockUnavailableException;
use Mistrfilda\Redis\Tests\Fixtures\RedisTestCase;
use Mistrfilda\Redis\Tests\Fixtures\Worker;
use RuntimeException;

final class LockManagerTest extends RedisTestCase
{

	public function testOnlyOwnerCanReleaseAndRefreshTheLock(): void
	{
		$manager = new LockManager($this->redis);
		$lock = $manager->acquire('job');
		self::assertInstanceOf(Lock::class, $lock);
		self::assertNull($manager->acquire('job'));
		self::assertFalse(new Lock($this->redis, 'mistrfilda:lock:' . hash('sha256', 'job'), 'wrong-owner')->release());
		self::assertTrue($lock->refresh(5000));
		self::assertTrue($lock->release());
		self::assertFalse($lock->release());
		self::assertFalse($lock->refresh(5000));
		self::assertInstanceOf(Lock::class, $manager->acquire('job'));
	}

	public function testExpiredOwnerCannotRemoveOrExtendItsSuccessor(): void
	{
		$manager = new LockManager($this->redis);
		$old = $manager->acquire('job');
		self::assertInstanceOf(Lock::class, $old);
		$this->redis->pexpire('mistrfilda:lock:' . hash('sha256', 'job'), 1);
		$new = $manager->acquire('job', waitMilliseconds: 1000);
		self::assertInstanceOf(Lock::class, $new);
		self::assertFalse($old->refresh(5000));
		self::assertFalse($old->release());
		self::assertNull($manager->acquire('job'));
		self::assertTrue($new->release());
	}

	public function testCallbackExceptionReleasesTheLock(): void
	{
		$manager = new LockManager($this->redis);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageIs('Callback failed.');
		try {
			$manager->synchronized('job', static function (): never {
				throw new RuntimeException('Callback failed.');
			});
		} finally {
			self::assertInstanceOf(Lock::class, $manager->acquire('job'));
		}
	}

	public function testCallbackReturnsItsResult(): void
	{
		$this->redis->set('result', '42');
		self::assertSame(
			'42',
			new LockManager($this->redis)->synchronized('other', fn (): mixed => $this->redis->get('result')),
		);
	}

	public function testCallbackDoesNotRunWhenLockIsBusy(): void
	{
		$manager = new LockManager($this->redis);
		$manager->acquire('busy');
		$this->expectException(LockUnavailableException::class);
		$manager->synchronized('busy', static fn () => self::fail('Busy lock must not run its callback.'));
	}

	public function testAnotherProcessIsExcludedUntilRelease(): void
	{
		$worker = new Worker(['lock', 'process:', 'job']);
		try {
			self::assertSame('acquired', $worker->readLine());
			$manager = new LockManager($this->redis, 'process:');
			self::assertNull($manager->acquire('job', waitMilliseconds: 20));
			$worker->proceed();
			self::assertSame(0, $worker->finish());
			self::assertInstanceOf(Lock::class, $manager->acquire('job'));
		} catch (RuntimeException $exception) {
			$worker->kill();

			throw $exception;
		}
	}

	public function testLockExpiresAfterWorkerIsKilled(): void
	{
		$worker = new Worker(['lock', 'process:', 'job', '500']);
		self::assertSame('acquired', $worker->readLine());
		$worker->kill();
		self::assertInstanceOf(
			Lock::class,
			new LockManager($this->redis, 'process:')->acquire('job', waitMilliseconds: 2000),
		);
	}

}
