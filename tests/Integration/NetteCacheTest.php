<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Tests\Integration;

use DateTimeImmutable;
use Mistrfilda\Redis\Bridges\Nette\Caching\RedisStorage;
use Mistrfilda\Redis\Tests\Fixtures\CachedProfile;
use Mistrfilda\Redis\Tests\Fixtures\CacheStatus;
use Mistrfilda\Redis\Tests\Fixtures\CustomSerializedValue;
use Mistrfilda\Redis\Tests\Fixtures\RedisTestCase;
use Mistrfilda\Redis\Tests\Fixtures\Worker;
use Nette\Caching\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Throwable;

final class NetteCacheTest extends RedisTestCase
{

	#[DataProvider('scalarAndArrayValues')]
	public function testValuesKeepTheirTypesAndStructure(mixed $value): void
	{
		$writer = new Cache(new RedisStorage($this->redis), 'values');
		$reader = new Cache(new RedisStorage($this->redis), 'values');
		$writer->save('value', $value);

		self::assertSame($value, $reader->load('value', static function (): never {
			self::fail('A cached value must not invoke the generator.');
		}));
	}

	public function testObjectIsRestoredWithPrivateAndReadonlyPropertiesAsAnIndependentInstance(): void
	{
		$cache = new Cache(new RedisStorage($this->redis));
		$date = new DateTimeImmutable('2026-01-01T10:30:00+01:00');
		$original = new CachedProfile('original', 7, $date);
		$cache->save('profile', $original);
		$original->name = 'changed after saving';

		$restored = $cache->load('profile');
		self::assertInstanceOf(CachedProfile::class, $restored);
		self::assertNotSame($original, $restored);
		self::assertSame('original', $restored->name);
		self::assertSame(7, $restored->getVersion());
		self::assertEquals($date, $restored->createdAt);
		self::assertNotSame($date, $restored->createdAt);
		$restored->name = 'changed after loading';

		$anotherRead = $cache->load('profile');
		self::assertInstanceOf(CachedProfile::class, $anotherRead);
		self::assertSame('original', $anotherRead->name);
		$cache->save('profile', $restored);
		$saved = $cache->load('profile');
		self::assertInstanceOf(CachedProfile::class, $saved);
		self::assertSame('changed after loading', $saved->name);
	}

	public function testNestedObjectsAndSharedReferencesArePreserved(): void
	{
		$cache = new Cache(new RedisStorage($this->redis));
		$object = new stdClass();
		$object->name = 'shared';
		$object->parent = $object;
		$value = ['first' => $object, 'nested' => ['second' => $object]];
		$cache->save('graph', $value);

		$restored = $cache->load('graph');
		self::assertIsArray($restored);
		self::assertArrayHasKey('first', $restored);
		self::assertInstanceOf(stdClass::class, $restored['first']);
		self::assertArrayHasKey('nested', $restored);
		self::assertIsArray($restored['nested']);
		self::assertArrayHasKey('second', $restored['nested']);
		self::assertNotSame($object, $restored['first']);
		self::assertSame($restored['first'], $restored['nested']['second']);
		self::assertSame($restored['first'], get_object_vars($restored['first'])['parent']);
		self::assertSame('shared', get_object_vars($restored['first'])['name']);
	}

	public function testDateTimeAndEnumRetainTheirTypesAndValues(): void
	{
		$cache = new Cache(new RedisStorage($this->redis));
		$date = new DateTimeImmutable('2026-03-20 12:34:56.123456 Europe/Prague');
		$cache->save('date', $date);
		$restored = $cache->load('date');
		self::assertInstanceOf(DateTimeImmutable::class, $restored);
		self::assertNotSame($date, $restored);
		self::assertSame($date->format('Y-m-d H:i:s.u e'), $restored->format('Y-m-d H:i:s.u e'));

		$cache->save('status', CacheStatus::Ready);
		self::assertSame(CacheStatus::Ready, $cache->load('status'));
	}

	public function testObjectSerializationHooksAreUsed(): void
	{
		$cache = new Cache(new RedisStorage($this->redis));
		$value = new CustomSerializedValue('payload');
		$cache->save('custom', $value);
		$restored = $cache->load('custom');
		self::assertInstanceOf(CustomSerializedValue::class, $restored);
		self::assertNotSame($value, $restored);
		self::assertSame('payload', $restored->getValue());
		self::assertTrue($restored->restored);
		self::assertFalse($value->restored);
	}

	public function testObjectCanBeAutoloadedAndReadByAnotherPhpProcess(): void
	{
		$prefix = 'object-process:';
		$cache = new Cache(new RedisStorage($this->redis, $prefix));
		$cache->save('profile', new CachedProfile('original', 7, new DateTimeImmutable('2026-01-01')));
		$worker = new Worker(['cache-object', $prefix, 'profile']);
		self::assertSame('original:7', $worker->readLine());
		self::assertSame(0, $worker->finish());
	}

	public function testNullRemovesAnExistingEntryAndNullGeneratorsAreNotCached(): void
	{
		$cache = new Cache(new RedisStorage($this->redis));
		$cache->save('value', ['cached']);
		$cache->save('value', null);
		self::assertNull($cache->load('value'));
		self::assertSame(0, $this->redis->dbSize());

		$calls = 0;
		$generator = static function () use (&$calls): null {
			$calls++;
			return null;
		};
		self::assertNull($cache->load('missing', $generator));
		self::assertNull($cache->load('missing', $generator));
		self::assertSame(2, $calls);
		self::assertSame(0, $this->redis->dbSize());
	}

	public function testClosurePassedToSaveGeneratesTheStoredValue(): void
	{
		$cache = new Cache(new RedisStorage($this->redis));
		$calls = 0;
		$cache->save('generated', static function () use (&$calls): array {
			$calls++;
			return ['generated' => false];
		});
		self::assertSame(['generated' => false], $cache->load('generated'));
		self::assertSame(['generated' => false], $cache->load('generated'));
		self::assertSame(1, $calls);
	}

	public function testUnserializableValueDoesNotReplaceExistingDataOrItsTags(): void
	{
		$cache = new Cache(new RedisStorage($this->redis));
		$cache->save('value', ['original'], [Cache::Tags => ['old']]);
		$failure = null;
		try {
			$cache->save('value', ['closure' => static fn (): string => 'unsupported'], [Cache::Tags => ['new']]);
		} catch (Throwable $exception) {
			$failure = $exception;
		}

		self::assertInstanceOf(Throwable::class, $failure);
		self::assertStringContainsString('Closure', $failure->getMessage());
		self::assertSame(['original'], $cache->load('value'));
		$cache->clean([Cache::Tags => ['new']]);
		self::assertSame(['original'], $cache->load('value'));
		$cache->clean([Cache::Tags => ['old']]);
		self::assertNull($cache->load('value'));
		self::assertSame(0, $this->redis->dbSize());
	}

	public function testExpiredDataLeavesJournalMetadataUntilExplicitCleanup(): void
	{
		$cache = new Cache(new RedisStorage($this->redis));
		$cache->save(
			'short',
			'short',
			[Cache::Expire => '1 second', Cache::Tags => ['one', 'two'], Cache::Priority => 10],
		);
		usleep(1100000);
		self::assertNull($cache->load('short'));
		self::assertGreaterThan(0, $this->redis->dbSize());

		$cache->clean();
		self::assertSame(0, $this->redis->dbSize());
	}

	public function testJournalCleanupPreservesLiveMembersOfSharedTags(): void
	{
		$cache = new Cache(new RedisStorage($this->redis));
		$cache->save('long', 'long', [Cache::Expire => '1 hour', Cache::Tags => ['shared'], Cache::Priority => 20]);
		$cache->save(
			'short',
			'short',
			[Cache::Expire => '1 second', Cache::Tags => ['shared', 'short'], Cache::Priority => 10],
		);
		usleep(1100000);
		$cache->clean();
		self::assertNull($cache->load('short'));
		self::assertSame('long', $cache->load('long'));
		$cache->clean([Cache::Tags => ['shared']]);
		self::assertNull($cache->load('long'));
		self::assertSame(0, $this->redis->dbSize());
	}

	/** @return array<string, array{mixed}> */
	public static function scalarAndArrayValues(): array
	{
		return [
			'false' => [false],
			'true' => [true],
			'zero integer' => [0],
			'negative integer' => [-42],
			'zero float' => [0.0],
			'fraction' => [12.5],
			'empty string' => [''],
			'numeric string' => ['0'],
			'unicode string' => ['Příliš žluťoučký kůň'],
			'binary string' => ["zero\0byte\xff"],
			'empty array' => [[]],
			'list' => [[1, '1', false, null, 2.5]],
			'associative array' => [['enabled' => true, 'count' => 0, 'missing' => null]],
			'nonsequential keys' => [[4 => 'four', 9 => 'nine', '09' => 'string key']],
			'nested arrays' => [['rows' => [['id' => 1, 'tags' => ['a', 'b']], ['id' => 2, 'tags' => []]]]],
		];
	}

}
