<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Tests\Fixtures;

use DateTimeImmutable;

final class CachedProfile
{

	public function __construct(
		public string $name,
		private int $version,
		public readonly DateTimeImmutable $createdAt,
	)
	{
	}

	public function getVersion(): int
	{
		return $this->version;
	}

}
