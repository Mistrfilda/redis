<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Tests\Fixtures;

use PHPUnit\Framework\TestCase;
use Redis;

abstract class RedisTestCase extends TestCase
{

	protected Redis $redis;

	protected function setUp(): void
	{
		$this->redis = TestServer::connect();
		$this->redis->flushDB();
	}

	protected function tearDown(): void
	{
		$this->redis->flushDB();
		$this->redis->close();
	}

}
