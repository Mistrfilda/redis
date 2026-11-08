<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Tests\Fixtures;

use Mistrfilda\Redis\Connection\ConnectionConfig;
use Mistrfilda\Redis\Connection\ConnectionFactory;
use Redis;
use RuntimeException;

final class TestServer
{

	public static function config(): ConnectionConfig
	{
		$host = getenv('REDIS_TEST_HOST');
		$port = getenv('REDIS_TEST_PORT');
		$database = getenv('REDIS_TEST_DATABASE');
		if (
			$host === false
			|| $port === false
			|| $database === false
			|| !ctype_digit($port)
			|| !ctype_digit($database)
		) {
			throw new RuntimeException(
				'Set REDIS_TEST_HOST, REDIS_TEST_PORT and REDIS_TEST_DATABASE to a disposable server. Tests flush this database.',
			);
		}

		return new ConnectionConfig(host: $host, port: (int) $port, database: (int) $database);
	}

	public static function connect(): Redis
	{
		return new ConnectionFactory()->create(self::config());
	}

}
