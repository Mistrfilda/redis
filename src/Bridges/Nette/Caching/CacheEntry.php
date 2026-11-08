<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Bridges\Nette\Caching;

/** @internal */
final readonly class CacheEntry
{

	/**
	 * @param array<string, string|null> $items
	 * @param list<array{0: callable, 1?: mixed, 2?: mixed}> $callbacks
	 */
	public function __construct(
		public string $value,
		public string $version,
		public array $items,
		public array $callbacks,
		public int $slidingSeconds,
	)
	{
	}

}
