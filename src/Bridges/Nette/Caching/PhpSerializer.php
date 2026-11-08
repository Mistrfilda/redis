<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Bridges\Nette\Caching;

use UnexpectedValueException;

final class PhpSerializer implements Serializer
{

	public function serialize(mixed $value): string
	{
		return serialize($value);
	}

	public function unserialize(string $value): mixed
	{
		$result = @unserialize($value);
		if ($result === false && $value !== 'b:0;') {
			throw new UnexpectedValueException('Invalid serialized cache value.');
		}

		return $result;
	}

}
