<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Connection;

use Redis;
use RedisException;

final class ConnectionFactory
{

	public function create(ConnectionConfig $config): Redis
	{
		$redis = new Redis();

		try {
			if (!$redis->connect($config->getHost(), $config->port, $config->timeout, null, 0, $config->readTimeout)) {
				throw new RedisException('Could not connect to Redis.');
			}

			if ($config->password !== null) {
				$authentication = $config->username === null
					? $config->password
					: [$config->username, $config->password];
				if (!$redis->auth($authentication)) {
					throw new RedisException('Redis authentication failed.');
				}
			}

			if (!$redis->select($config->database)) {
				throw new RedisException('Could not select the Redis database.');
			}

			$redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
			$redis->setOption(Redis::OPT_PREFIX, '');

			return $redis;
		} catch (RedisException $exception) {
			if ($redis->isConnected()) {
				$redis->close();
			}

			throw $exception;
		}
	}

}
