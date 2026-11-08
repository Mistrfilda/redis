<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Bridges\Nette\Caching;

final readonly class GarbageCollectionResult
{

	public function __construct(
		public int $checked,
		public int $removed,
		public float $durationSeconds,
	)
	{
	}

}
