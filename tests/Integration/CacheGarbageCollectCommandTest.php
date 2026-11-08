<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Tests\Integration;

use Mistrfilda\Redis\Bridges\Nette\Caching\RedisJournal;
use Mistrfilda\Redis\Bridges\Nette\Caching\RedisStorage;
use Mistrfilda\Redis\Bridges\Symfony\Console\CacheGarbageCollectCommand;
use Mistrfilda\Redis\Tests\Fixtures\RedisTestCase;
use Nette\Caching\Cache;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CacheGarbageCollectCommandTest extends RedisTestCase
{

	public function testCommandCollectsExpiredMetadataAndPreservesOtherData(): void
	{
		$storage = new RedisStorage($this->redis, 'gc:');
		$cache = new Cache($storage);
		$other = new Cache(new RedisStorage($this->redis, 'other:'));
		$cache->save(
			'short',
			'expired',
			[Cache::Expire => '1 second', Cache::Tags => ['shared'], Cache::Priority => 1],
		);
		$cache->save('long', 'live', [Cache::Tags => ['shared'], Cache::Priority => 2]);
		$other->save('short', 'other cache', [Cache::Tags => ['shared']]);
		$this->redis->set('session:example', 'session data');
		usleep(1100000);

		$tester = new CommandTester(new CacheGarbageCollectCommand($storage));
		self::assertSame(Command::SUCCESS, $tester->execute([]));
		self::assertStringContainsString('Checked: 2; removed: 1; duration: ', $tester->getDisplay());
		self::assertNull($cache->load('short'));
		self::assertSame('live', $cache->load('long'));
		self::assertSame('other cache', $other->load('short'));
		self::assertSame('session data', $this->redis->get('session:example'));

		self::assertSame(Command::SUCCESS, $tester->execute([]));
		self::assertStringContainsString('Checked: 1; removed: 0; duration: ', $tester->getDisplay());
		$cache->clean([Cache::Tags => ['shared']]);
		self::assertNull($cache->load('long'));
		self::assertSame('other cache', $other->load('short'));
		$other->clean([Cache::Tags => ['shared']]);
		$this->redis->del('session:example');
		self::assertSame(0, $this->redis->dbSize());
	}

	public function testPublicApiReportsAnEmptyJournal(): void
	{
		$result = new RedisStorage($this->redis)->collectGarbage();
		self::assertSame(0, $result->checked);
		self::assertSame(0, $result->removed);
		self::assertGreaterThanOrEqual(0.0, $result->durationSeconds);
	}

	public function testRepeatedRemovalDoesNotCountTheSameMetadataTwice(): void
	{
		$journal = new RedisJournal($this->redis, 'gc:journal:');
		$journal->write('gc:entry', 'value', 0, ['tag'], 1);
		self::assertFalse($journal->remove('gc:entry', onlyExpired: true));
		self::assertSame('value', $this->redis->get('gc:entry'));
		$this->redis->del('gc:entry');
		self::assertTrue($journal->remove('gc:entry', onlyExpired: true));
		self::assertFalse($journal->remove('gc:entry', onlyExpired: true));
		self::assertSame(0, $this->redis->dbSize());
	}

}
