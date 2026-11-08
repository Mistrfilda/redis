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
