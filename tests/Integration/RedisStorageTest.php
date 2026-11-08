<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Tests\Integration;

use DateTimeImmutable;
use Mistrfilda\Redis\Bridges\Nette\Caching\PhpSerializer;
use Mistrfilda\Redis\Bridges\Nette\Caching\RedisStorage;
use Mistrfilda\Redis\Bridges\Nette\Caching\Serializer;
use Mistrfilda\Redis\Tests\Fixtures\RedisTestCase;
use Nette\Caching\Cache;

final class RedisStorageTest extends RedisTestCase
{

	public function testCachePreservesValuesAndSeparatesNamespaces(): void
	{
		$storage = new RedisStorage($this->redis);
		$cache = new Cache($storage, 'first');
		$other = new Cache($storage, 'second');
		$values = [false, 0, '', ['date' => new DateTimeImmutable('2026-01-01')]];
		foreach ($values as $key => $value) {
			$cache->save($key, $value);
			self::assertEquals($value, $cache->load($key));
			self::assertNull($other->load($key));
		}

		$cache->bulkSave(['a' => 1, 'b' => 2]);
		self::assertSame(['a' => 1, 'b' => 2, 'c' => null], $cache->bulkLoad(['a', 'b', 'c']));
		$cache->remove('a');
		self::assertNull($cache->load('a'));
	}

	public function testTagsSurviveDifferentEntryExpirationsAndReplacement(): void
	{
		$storage = new RedisStorage($this->redis);
		$storage->write('long', 'long', [Cache::Tags => ['shared'], Cache::Expire => 100]);
		$storage->write('short', 'short', [Cache::Tags => ['shared'], Cache::Expire => 1]);
		usleep(1100000);
		self::assertNull($storage->read('short'));
		self::assertSame('long', $storage->read('long'));
		$storage->clean([]);
		$storage->clean([Cache::Tags => ['shared']]);
		self::assertNull($storage->read('long'));

		$storage->write('replace', 'before', [Cache::Tags => ['old']]);
		$storage->write('replace', 'after', [Cache::Tags => ['new']]);
		$storage->clean([Cache::Tags => ['old']]);
		self::assertSame('after', $storage->read('replace'));
		$storage->clean([Cache::Tags => ['new']]);
		self::assertNull($storage->read('replace'));
	}

	public function testPriorityIncludesNegativeValuesAndDoesNotRemoveHigherPriorities(): void
	{
		$storage = new RedisStorage($this->redis);
		$storage->write('low', 1, [Cache::Priority => -1]);
		$storage->write('medium', 2, [Cache::Priority => 10]);
		$storage->write('high', 3, [Cache::Priority => 20]);
		$storage->write('none', 4, []);
		$storage->clean([Cache::Priority => 10]);
		self::assertNull($storage->read('low'));
		self::assertNull($storage->read('medium'));
		self::assertSame(3, $storage->read('high'));
		self::assertSame(4, $storage->read('none'));
	}

	public function testNestedItemDependenciesInvalidateOnChangeAndDeletion(): void
	{
		$cache = new Cache(new RedisStorage($this->redis));
		$cache->save('root', 1);
		$cache->save('middle', 2, [Cache::Items => ['root']]);
		$cache->save('leaf', 3, [Cache::Items => ['middle']]);
		self::assertSame(3, $cache->load('leaf'));
		$cache->save('root', 10);
		self::assertNull($cache->load('leaf'));

		$cache->save('middle', 2, [Cache::Items => ['root']]);
		$cache->remove('root');
		self::assertNull($cache->load('middle'));
	}

	public function testCallbackDependenciesAreCheckedBeforeReturningData(): void
	{
		$storage = new RedisStorage($this->redis);
		$storage->write('valid', 1, [Cache::Callbacks => [[self::class . '::validDependency', true]]]);
		$storage->write('invalid', 2, [Cache::Callbacks => [[self::class . '::validDependency', false]]]);
		self::assertSame(1, $storage->read('valid'));
		self::assertNull($storage->read('invalid'));
	}

	public function testExpirationSlidingAndGarbageCollection(): void
	{
		$storage = new RedisStorage($this->redis);
		$storage->write('sliding', 'value', [Cache::Expire => 5, Cache::Sliding => true, Cache::Tags => ['tag']]);
		$key = 'mistrfilda:cache:entry:' . hash('sha256', 'sliding');
		$this->redis->pexpire($key, 1000);
		self::assertSame('value', $storage->read('sliding'));
		self::assertGreaterThan(4000, $this->redis->pttl($key));
		$this->redis->del($key);
		$storage->clean([]);
		self::assertSame(0, $this->redis->dbSize());

		$storage->write('expired', 'value', [Cache::Expire => -1]);
		self::assertNull($storage->read('expired'));
	}

	public function testCleanAllFlushesTheSelectedDatabase(): void
	{
		$cache = new Cache(new RedisStorage($this->redis));
		$cache->save('entry', 'value');
		$this->redis->set('unrelated', 'also removed by the documented FLUSHDB policy');
		$cache->clean([Cache::All => true]);
		self::assertSame(0, $this->redis->dbSize());
	}

	public function testReadingDependentEntryRefreshesSlidingExpiration(): void
	{
		$storage = new RedisStorage($this->redis);
		$storage->write('parent', 'parent', [Cache::Expire => 5, Cache::Sliding => true]);
		$storage->write('child', 'child', [Cache::Items => ['parent']]);
		$key = 'mistrfilda:cache:entry:' . hash('sha256', 'parent');
		$this->redis->pexpire($key, 1000);

		self::assertSame('child', $storage->read('child'));
		self::assertGreaterThan(4000, $this->redis->pttl($key));
	}

	public function testFileDependenciesInvalidateWhenTheFileChanges(): void
	{
		$file = tempnam(sys_get_temp_dir(), 'redis-cache-');
		self::assertNotFalse($file);
		try {
			file_put_contents($file, 'first');
			$cache = new Cache(new RedisStorage($this->redis));
			$cache->save('file', 'cached', [Cache::Files => [$file]]);
			self::assertSame('cached', $cache->load('file'));
			touch($file, time() + 10);
			clearstatcache(true, $file);
			self::assertNull($cache->load('file'));
		} finally {
			unlink($file);
		}
	}

	public function testCustomSerializerOnlyTransformsCachedValues(): void
	{
		$serializer = new class implements Serializer {

			public function serialize(mixed $value): string
			{
				return strrev(new PhpSerializer()->serialize($value));
			}

			public function unserialize(string $value): mixed
			{
				return new PhpSerializer()->unserialize(strrev($value));
			}

		};
		$storage = new RedisStorage($this->redis, serializer: $serializer);
		$storage->write('custom', ['value' => false], [Cache::Tags => ['custom']]);
		self::assertSame(['value' => false], $storage->read('custom'));
		$storage->clean([Cache::Tags => ['custom']]);
		self::assertNull($storage->read('custom'));
	}

	public static function validDependency(bool $valid): bool
	{
		return $valid;
	}

}
