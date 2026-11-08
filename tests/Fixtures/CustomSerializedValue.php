<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Tests\Fixtures;

final class CustomSerializedValue
{

	public bool $restored = false;

	public function __construct(private readonly string $value)
	{
	}

	public function getValue(): string
	{
		return $this->value;
	}

	/** @return array{value: string} */
	public function __serialize(): array
	{
		return ['value' => $this->value];
	}

	/** @param array{value: string} $data */
	public function __unserialize(array $data): void
	{
		$this->value = $data['value'];
		$this->restored = true;
	}

}
