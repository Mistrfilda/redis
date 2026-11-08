<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Tests\Unit;

use Mistrfilda\Redis\Bridges\Nette\Caching\RedisStorage;
use Mistrfilda\Redis\Bridges\Symfony\Console\CacheGarbageCollectCommand;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;

final class CacheGarbageCollectCommandTest extends TestCase
{

	public function testConsoleReportsFailureWhenRedisIsUnavailable(): void
	{
		$redis = $this->createRawClient();
		$redis->method('sscan')->willThrowException(new RedisException('Redis connection failed.'));
		$tester = $this->console($redis);

		self::assertSame(Command::FAILURE, $tester->run(['command' => 'redis:cache:gc'], [
			'interactive' => false,
			'capture_stderr_separately' => true,
		]));
		self::assertStringContainsString('Redis connection failed.', $tester->getErrorOutput());
		self::assertStringNotContainsString('Checked:', $tester->getDisplay());
	}

	public function testConsoleDoesNotReportSuccessForAnInvalidServerResponse(): void
	{
		$redis = $this->createRawClient();
		$redis->method('sscan')->willReturn(['cache:entry']);
		$redis->method('eval')->willReturn(false);
		$tester = $this->console($redis);

		self::assertSame(Command::FAILURE, $tester->run(['command' => 'redis:cache:gc'], [
			'interactive' => false,
			'capture_stderr_separately' => true,
		]));
		self::assertStringContainsString('invalid result', $tester->getErrorOutput());
		self::assertStringNotContainsString('Checked:', $tester->getDisplay());
	}

	private function createRawClient(): Redis&Stub
	{
		$redis = self::createStub(Redis::class);
		$redis->method('getMode')->willReturn(Redis::ATOMIC);
		$redis->method('getOption')->willReturnMap([
			[Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE],
			[Redis::OPT_COMPRESSION, Redis::COMPRESSION_NONE],
			[Redis::OPT_PREFIX, null],
		]);
		return $redis;
	}

	private function console(Redis $redis): ApplicationTester
	{
		$application = new Application();
		$application->setAutoExit(false);
		$application->addCommands([new CacheGarbageCollectCommand(new RedisStorage($redis))]);
		return new ApplicationTester($application);
	}

}
