<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Tests\Unit;

use LogicException;
use Mistrfilda\Redis\Bridges\Nette\DI\RedisExtension;
use Nette\Caching\Storage;
use Nette\Caching\Storages\MemoryStorage;
use Nette\DI\Compiler;
use Nette\DI\InvalidConfigurationException;
use Nette\Schema\Helpers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedisExtensionTest extends TestCase
{

	public function testCompilesWithoutOptionalFeaturesOrConnectingToRedis(): void
	{
		$compiler = new Compiler();
		$compiler->addExtension('redis', new RedisExtension());
		$compiler->addConfig(['redis' => ['connections' => ['default' => ['port' => 1]]]]);
		$code = $compiler->compile();
		self::assertStringContainsString('Mistrfilda\\Redis\\Connection\\ConnectionConfig', $code);
		self::assertStringNotContainsString('RedisStorage', $code);
		self::assertStringNotContainsString('NativeSessionConfigurator', $code);
	}

	public function testUnknownConnectionFailsDuringCompilation(): void
	{
		$compiler = new Compiler();
		$compiler->addExtension('redis', new RedisExtension());
		$compiler->addConfig(['redis' => ['locks' => ['enabled' => true, 'connection' => 'missing']]]);
		$this->expectException(LogicException::class);
		$this->expectExceptionMessageIs('Redis connection "missing" does not exist.');
		$compiler->compile();
	}

	#[DataProvider('cacheAutowiringModes')]
	public function testNamedConnectionOnlyReplacesStorageWhenCacheIsEnabled(bool $enabled): void
	{
		$compiler = new Compiler();
		$compiler->addExtension('redis', new RedisExtension());
		$compiler->addConfig(['redis' => [
			'connections' => [Helpers::PreventMerging => true, 'backend' => ['port' => 1]],
			'defaultConnection' => 'backend',
			'cache' => ['enabled' => $enabled, 'connection' => 'backend'],
			'locks' => ['enabled' => true, 'connection' => 'backend'],
		]]);
		$builder = $compiler->getContainerBuilder();
		$original = $builder->addDefinition('originalStorage')->setFactory(MemoryStorage::class);
		$compiler->compile();

		self::assertFalse($builder->hasDefinition('redis.connections.default.client'));
		self::assertSame($enabled ? 'redis.cache' : 'originalStorage', $builder->getByType(Storage::class));
		self::assertSame(!$enabled, $original->getAutowired());
	}

	public function testSessionIntegrationRequiresNetteSessionService(): void
	{
		$compiler = new Compiler();
		$compiler->addExtension('redis', new RedisExtension());
		$compiler->addConfig(['redis' => ['sessions' => ['enabled' => true]]]);
		$this->expectException(LogicException::class);
		$compiler->compile();
	}

	public function testInvalidOptionsFailDuringCompilation(): void
	{
		$compiler = new Compiler();
		$compiler->addExtension('redis', new RedisExtension());
		$compiler->addConfig(['redis' => ['sessions' => ['lockTtl' => 0]]]);
		$this->expectException(InvalidConfigurationException::class);
		$compiler->compile();
	}

	/** @return array<string, array{bool}> */
	public static function cacheAutowiringModes(): array
	{
		return ['cache disabled' => [false], 'cache enabled' => [true]];
	}

}
