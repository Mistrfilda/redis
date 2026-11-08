<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Tests\Integration;

use InvalidArgumentException;
use Mistrfilda\Redis\Connection\ConnectionConfig;
use Mistrfilda\Redis\Connection\ConnectionFactory;
use Mistrfilda\Redis\Lock\LockManager;
use Mistrfilda\Redis\Tests\Fixtures\RedisTestCase;
use Mistrfilda\Redis\Tests\Fixtures\TestServer;
use PHPUnit\Framework\Attributes\DataProvider;
use Redis;

final class ConnectionFactoryTest extends RedisTestCase
{

	public function testAclAuthenticationAndDatabaseSelection(): void
	{
		$this->redis->acl('SETUSER', 'library-test', 'on', '>test&password?', '~*', '+@all');
		try {
			$server = TestServer::config();
			$client = new ConnectionFactory()->create(new ConnectionConfig(
				host: $server->host,
				port: $server->port,
				database: $server->database,
				username: 'library-test',
				password: 'test&password?',
			));
			try {
				$client->set('authenticated', 'yes');
				self::assertSame('yes', $this->redis->get('authenticated'));
			} finally {
				$client->close();
			}
		} finally {
			$this->redis->acl('DELUSER', 'library-test');
		}
	}

	#[DataProvider('incompatibleOptions')]
	public function testLockManagerRejectsClientOptionsThatWouldBreakOwnership(int $option, string|int $value): void
	{
		$this->redis->setOption($option, $value);
		$this->expectException(InvalidArgumentException::class);
		new LockManager($this->redis);
	}

	/** @return iterable<string, array{int, string|int}> */
	public static function incompatibleOptions(): iterable
	{
		yield 'automatic prefix' => [Redis::OPT_PREFIX, 'automatic:'];
		yield 'automatic serializer' => [Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP];
	}

	public function testLockManagerRejectsQueuedCommands(): void
	{
		$this->redis->multi(Redis::PIPELINE);
		try {
			$this->expectException(InvalidArgumentException::class);
			new LockManager($this->redis);
		} finally {
			$this->redis->discard();
		}
	}

}
