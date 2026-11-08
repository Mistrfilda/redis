<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Connection;

use InvalidArgumentException;
use Redis;

/** @internal */
final class ClientValidator
{

	public static function requireRawConnection(Redis $redis): void
	{
		if (
			$redis->getMode() !== Redis::ATOMIC
			|| $redis->getOption(Redis::OPT_SERIALIZER) !== Redis::SERIALIZER_NONE
			|| $redis->getOption(Redis::OPT_COMPRESSION) !== Redis::COMPRESSION_NONE
			|| !in_array($redis->getOption(Redis::OPT_PREFIX), [null, ''], true)
		) {
			throw new InvalidArgumentException(
				'Use a Redis connection in atomic mode without automatic serialization, compression or a client prefix.',
			);
		}
	}

}
