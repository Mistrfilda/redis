<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Bridges\Nette\Caching;

interface Serializer
{

	public function serialize(mixed $value): string;

	public function unserialize(string $value): mixed;

}
