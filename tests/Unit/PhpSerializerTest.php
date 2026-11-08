<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Tests\Unit;

use DateTimeImmutable;
use Mistrfilda\Redis\Bridges\Nette\Caching\PhpSerializer;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class PhpSerializerTest extends TestCase
{

	public function testRoundTripPreservesValuesAndObjects(): void
	{
		$serializer = new PhpSerializer();
		$values = [false, 0, '', null, ['date' => new DateTimeImmutable('2026-01-01T00:00:00+00:00')]];
		foreach ($values as $value) {
			self::assertEquals($value, $serializer->unserialize($serializer->serialize($value)));
		}
	}

	public function testRejectsInvalidPayload(): void
	{
		$this->expectException(UnexpectedValueException::class);
		new PhpSerializer()->unserialize('invalid');
	}

}
