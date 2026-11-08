<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Tests\Unit;

use InvalidArgumentException;
use Mistrfilda\Redis\Connection\ConnectionConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use const INF;
use const PHP_URL_QUERY;

/**
 * @phpstan-type Options array{
 *     host?: string, port?: int, database?: int, timeout?: float, readTimeout?: float, scheme?: string, username?: string
 * }
 */
final class ConnectionConfigTest extends TestCase
{

	public function testSessionPathEncodesAuthenticationAndPrefix(): void
	{
		$config = new ConnectionConfig(
			host: '::1',
			database: 3,
			username: 'test-user',
			password: 'test&password?',
			scheme: 'tls',
		);
		$path = $config->getSessionSavePath('test:session:');
		self::assertStringStartsWith('tls://[::1]:6379?', $path);
		parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
		self::assertSame('3', $query['database']);
		self::assertSame('test:session:', $query['prefix']);
		self::assertSame(['test-user', 'test&password?'], $query['auth']);
		self::assertSame('tls://[::1]', $config->getHost());
	}

	/** @param Options $options */
	#[DataProvider('invalidOptions')]
	public function testRejectsInvalidConfiguration(array $options): void
	{
		$this->expectException(InvalidArgumentException::class);
		new ConnectionConfig(...$options);
	}

	/** @return iterable<string, array{Options}> */
	public static function invalidOptions(): iterable
	{
		yield 'empty host' => [['host' => '']];
		yield 'URI as host' => [['host' => 'tcp://localhost']];
		yield 'invalid port' => [['port' => 65536]];
		yield 'negative database' => [['database' => -1]];
		yield 'unbounded timeout' => [['timeout' => 0.0]];
		yield 'infinite timeout' => [['readTimeout' => INF]];
		yield 'unsupported scheme' => [['scheme' => 'http']];
		yield 'missing password' => [['username' => 'test-user']];
	}

}
