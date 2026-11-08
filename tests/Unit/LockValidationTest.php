<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Tests\Unit;

use InvalidArgumentException;
use Mistrfilda\Redis\Lock\LockManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;

final class LockValidationTest extends TestCase
{

	#[DataProvider('invalidArguments')]
	public function testInvalidArgumentsFailBeforeConnecting(string $resource, int $ttl, int $wait): void
	{
		$manager = new LockManager(new Redis());
		$this->expectException(InvalidArgumentException::class);
		$manager->acquire($resource, $ttl, $wait);
	}

	/** @return iterable<string, array{string, int, int}> */
	public static function invalidArguments(): iterable
	{
		yield 'empty resource' => ['', 100, 0];
		yield 'zero TTL' => ['job', 0, 0];
		yield 'negative TTL' => ['job', -1, 0];
		yield 'negative wait' => ['job', 100, -1];
	}

}
