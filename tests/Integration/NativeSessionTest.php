<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Tests\Integration;

use LogicException;
use Mistrfilda\Redis\Session\NativeSessionConfigurator;
use Mistrfilda\Redis\Tests\Fixtures\RedisTestCase;
use Mistrfilda\Redis\Tests\Fixtures\TestServer;
use Mistrfilda\Redis\Tests\Fixtures\Worker;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NativeSessionTest extends RedisTestCase
{

	public function testNativeHandlerWritesReadsRenewsAndDestroysSession(): void
	{
		$this->configureSession();
		session_id('native-session-test');
		self::assertTrue(session_start());
		$_SESSION['value'] = 'stored';
		session_write_close();
		self::assertSame('a:1:{s:5:"value";s:6:"stored";}', $this->redis->get('session:native-session-test'));
		self::assertGreaterThan(110, $this->redis->ttl('session:native-session-test'));

		$this->redis->expire('session:native-session-test', 5);
		self::assertTrue(session_start());
		self::assertSame('stored', $_SESSION['value']);
		session_write_close();
		self::assertGreaterThan(110, $this->redis->ttl('session:native-session-test'));

		self::assertTrue(session_start());
		self::assertTrue(session_regenerate_id(true));
		$newId = session_id();
		self::assertNotSame('native-session-test', $newId);
		session_write_close();
		self::assertSame(0, $this->redis->exists('session:native-session-test'));
		self::assertNotFalse($this->redis->get('session:' . $newId));
		self::assertTrue(session_start());
		self::assertTrue(session_destroy());
		self::assertFalse($this->redis->get('session:' . $newId));
	}

	public function testStrictModeRejectsAnUnknownSessionId(): void
	{
		$this->configureSession();
		ini_set('session.use_strict_mode', '1');
		session_id('nonexistent-session-id');
		self::assertTrue(session_start());
		self::assertNotSame('nonexistent-session-id', session_id());
		session_write_close();
	}

	public function testConfigurationCannotChangeAnActiveSession(): void
	{
		$this->configureSession();
		self::assertTrue(session_start());
		try {
			$this->expectException(LogicException::class);
			new NativeSessionConfigurator(TestServer::config())->configure();
		} finally {
			session_write_close();
		}
	}

	public function testConcurrentRequestsDoNotLoseSessionUpdates(): void
	{
		$first = new Worker(['session', 'session:', 'concurrent-session']);
		self::assertSame('opened', $first->readLine());
		$second = new Worker(['session', 'session:', 'concurrent-session']);
		self::assertTrue($second->isWaiting());
		$first->proceed();
		self::assertSame(0, $first->finish());
		self::assertSame('opened', $second->readLine());
		$second->proceed();
		self::assertSame(0, $second->finish());
		self::assertSame('a:1:{s:5:"count";i:2;}', $this->redis->get('session:concurrent-session'));
	}

	public function testElevenConcurrentRequestsPreserveEverySessionUpdate(): void
	{
		$workers = [];
		try {
			for ($i = 0; $i < 11; $i++) {
				$worker = new Worker(['session-burst', 'session:', 'concurrent-burst']);
				$workers[] = $worker;
				self::assertSame('ready', $worker->readLine());
			}

			foreach ($workers as $worker) {
				$worker->proceed();
			}

			foreach ($workers as $worker) {
				self::assertSame('closed', $worker->readLine());
				self::assertSame(0, $worker->finish());
			}

			self::assertSame('a:1:{s:5:"count";i:11;}', $this->redis->get('session:concurrent-burst'));
			self::assertSame(0, $this->redis->exists('session:concurrent-burst_LOCK'));
		} finally {
			foreach ($workers as $worker) {
				$worker->kill();
			}
		}
	}

	public function testWaitTimeoutPreservesTheOwnersLockAndStoredSession(): void
	{
		$initialData = 'a:1:{s:5:"count";i:0;}';
		$this->redis->set('session:timeout-session', $initialData);
		$owner = new Worker(['session', 'session:', 'timeout-session']);
		try {
			self::assertSame('opened', $owner->readLine());
			$lockOwner = $this->redis->get('session:timeout-session_LOCK');
			self::assertIsString($lockOwner);

			$waiting = new Worker(['session-timeout', 'session:', 'timeout-session']);
			try {
				self::assertSame('timed-out', $waiting->readLine());
				self::assertSame(0, $waiting->finish());
			} finally {
				$waiting->kill();
			}

			self::assertSame($lockOwner, $this->redis->get('session:timeout-session_LOCK'));
			self::assertGreaterThan(0, $this->redis->pttl('session:timeout-session_LOCK'));
			self::assertSame($initialData, $this->redis->get('session:timeout-session'));

			$owner->proceed();
			self::assertSame(0, $owner->finish());
			self::assertSame('a:1:{s:5:"count";i:1;}', $this->redis->get('session:timeout-session'));
			self::assertSame(0, $this->redis->exists('session:timeout-session_LOCK'));
		} finally {
			$owner->kill();
		}
	}

	public function testDifferentSessionsDoNotBlockEachOther(): void
	{
		$first = new Worker(['session', 'session:', 'first-session']);
		$second = new Worker(['session', 'session:', 'second-session']);
		try {
			self::assertSame('opened', $first->readLine());
			self::assertSame('opened', $second->readLine());
			self::assertSame(1, $this->redis->exists('session:first-session_LOCK'));
			self::assertSame(1, $this->redis->exists('session:second-session_LOCK'));

			$first->proceed();
			$second->proceed();
			self::assertSame(0, $first->finish());
			self::assertSame(0, $second->finish());
			self::assertSame('a:1:{s:5:"count";i:1;}', $this->redis->get('session:first-session'));
			self::assertSame('a:1:{s:5:"count";i:1;}', $this->redis->get('session:second-session'));
		} finally {
			$first->kill();
			$second->kill();
		}
	}

	public function testClosingSessionReleasesItsLockBeforeTheRequestFinishes(): void
	{
		$first = new Worker(['session-closed', 'session:', 'closed-session']);
		try {
			self::assertSame('closed', $first->readLine());
			self::assertSame('a:1:{s:5:"count";i:1;}', $this->redis->get('session:closed-session'));
			self::assertSame(0, $this->redis->exists('session:closed-session_LOCK'));

			$second = new Worker(['session', 'session:', 'closed-session']);
			try {
				self::assertSame('opened', $second->readLine());
				$second->proceed();
				self::assertSame(0, $second->finish());
			} finally {
				$second->kill();
			}

			self::assertSame('a:1:{s:5:"count";i:2;}', $this->redis->get('session:closed-session'));
			$first->proceed();
			self::assertSame(0, $first->finish());
			self::assertSame('a:1:{s:5:"count";i:2;}', $this->redis->get('session:closed-session'));
		} finally {
			$first->kill();
		}
	}

	private function configureSession(): void
	{
		new NativeSessionConfigurator(TestServer::config(), 'session:')->configure();
		ini_set('session.use_cookies', '0');
		ini_set('session.use_strict_mode', '0');
		ini_set('session.cache_limiter', '');
		ini_set('session.serialize_handler', 'php_serialize');
		ini_set('session.gc_maxlifetime', '120');
	}

}
