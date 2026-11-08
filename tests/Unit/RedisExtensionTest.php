<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Tests\Unit;

use LogicException;
use Mistrfilda\Redis\Bridges\Nette\DI\RedisExtension;
use Mistrfilda\Redis\Bridges\Symfony\Console\CacheGarbageCollectCommand;
use Nette\Caching\Storage;
use Nette\Caching\Storages\MemoryStorage;
use Nette\DI\Compiler;
use Nette\DI\CompilerExtension;
use Nette\DI\Container;
use Nette\DI\Definitions\Reference;
use Nette\DI\Definitions\Statement;
use Nette\DI\InvalidConfigurationException;
use Nette\Schema\Helpers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;

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
		self::assertStringNotContainsString('CacheGarbageCollectCommand', $code);
	}

	#[DataProvider('cacheCommandRegistrationModes')]
	public function testCacheCommandRequiresEnabledCacheAndOptIn(
		bool $cacheEnabled,
		bool $registerCommand,
		bool $expected,
	): void
	{
		$compiler = new Compiler();
		$compiler->addExtension('redis', new RedisExtension());
		$compiler->addConfig(['redis' => [
			'connections' => ['default' => ['port' => 1]],
			'cache' => ['enabled' => $cacheEnabled, 'registerGcCommand' => $registerCommand],
		]]);
		$compiler->compile();
		self::assertSame(
			$expected ? ['redis.cacheGc'] : [],
			array_keys($compiler->getContainerBuilder()->findByType(CacheGarbageCollectCommand::class)),
		);
	}

	#[DataProvider('consoleExtensionOrder')]
	public function testConsoleDiscoversCommandRegardlessOfExtensionOrder(bool $consoleFirst): void
	{
		$compiler = new Compiler();
		$console = new class extends CompilerExtension {

			/** @var list<string> */
			public array $commands = [];

			public function beforeCompile(): void
			{
				$this->commands = array_keys($this->getContainerBuilder()->findByType(Command::class));
			}

		};
		$redis = new RedisExtension();
		$extensions = $consoleFirst
			? ['console' => $console, 'backend' => $redis]
			: ['backend' => $redis, 'console' => $console];
		foreach ($extensions as $name => $extension) {
			$compiler->addExtension($name, $extension);
		}

		$compiler->addConfig(['backend' => [
			'connections' => ['default' => ['port' => 1]],
			'cache' => ['enabled' => true, 'registerGcCommand' => true],
		]]);
		$compiler->compile();
		self::assertSame(['backend.cacheGc'], $console->commands);
	}

	public function testDisabledCacheOverrideAllowsConsoleStartupWithoutRedis(): void
	{
		$compiler = new Compiler();
		$class = 'RedisDisabledCacheContainer' . bin2hex(random_bytes(8));
		$compiler->setClassName($class);
		$compiler->addExtension('redis', new RedisExtension());
		$compiler->addConfig(['redis' => [
			'connections' => ['default' => ['port' => 1]],
			'cache' => ['enabled' => true, 'registerGcCommand' => true],
		]]);
		$compiler->addConfig(['redis' => ['cache' => ['enabled' => false]]]);
		$compiler->getContainerBuilder()->addDefinition('originalStorage')->setFactory(MemoryStorage::class);
		$compiler->getContainerBuilder()->addDefinition('console')
			->setFactory(Application::class)
			->addSetup('setAutoExit', [false])
			->addSetup('addCommands', [new Statement('typed', [Command::class])]);
		$file = tempnam(sys_get_temp_dir(), 'redis-di-');
		self::assertNotFalse($file);
		try {
			file_put_contents($file, "<?php\n" . $compiler->compile());
			require $file;
			$container = new $class();
			self::assertInstanceOf(Container::class, $container);
			$container->initialize();
			self::assertInstanceOf(MemoryStorage::class, $container->getByType(Storage::class));
			self::assertSame([], $container->findByType(Command::class));
			$application = $container->getByType(Application::class);
			$tester = new ApplicationTester($application);
			self::assertSame(Command::SUCCESS, $tester->run(['command' => 'list']));
			self::assertStringNotContainsString('redis:cache:gc', $tester->getDisplay());
			self::assertFalse($container->isCreated('redis.connections.default.client'));
		} finally {
			unlink($file);
		}
	}

	public function testManualCommandRegistrationStillWorksByDefault(): void
	{
		$compiler = new Compiler();
		$compiler->addExtension('redis', new RedisExtension());
		$compiler->addConfig(['redis' => [
			'connections' => ['default' => ['port' => 1]],
			'cache' => ['enabled' => true],
		]]);
		$builder = $compiler->getContainerBuilder();
		$builder->addDefinition('redisGc')
			->setFactory(CacheGarbageCollectCommand::class, [new Reference('redis.cache')]);
		$compiler->compile();
		self::assertSame(['redisGc'], array_keys($builder->findByType(CacheGarbageCollectCommand::class)));
	}

	#[DataProvider('cacheCommandRegistrationModes')]
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testSymfonyConsoleIsRequiredOnlyForEnabledCommand(
		bool $cacheEnabled,
		bool $registerCommand,
		bool $requiresConsole,
	): void
	{
		self::assertFalse(class_exists(Command::class, false));
		$autoloaders = spl_autoload_functions();
		foreach ($autoloaders as $autoloader) {
			spl_autoload_unregister($autoloader);
		}

		$withoutConsole = static function (string $class) use ($autoloaders): void {
			if (str_starts_with($class, 'Symfony\\Component\\Console\\')) {
				return;
			}

			foreach ($autoloaders as $autoloader) {
				$autoloader($class);
			}
		};
		spl_autoload_register($withoutConsole);
		try {
			$compiler = new Compiler();
			$compiler->addExtension('redis', new RedisExtension());
			$compiler->addConfig(['redis' => [
				'connections' => ['default' => ['port' => 1]],
				'cache' => ['enabled' => $cacheEnabled, 'registerGcCommand' => $registerCommand],
			]]);
			if ($requiresConsole) {
				$this->expectException(LogicException::class);
				$this->expectExceptionMessageIs(
					'Install symfony/console to register the Redis cache garbage collection command.',
				);
			}

			self::assertStringNotContainsString('CacheGarbageCollectCommand', $compiler->compile());
		} finally {
			spl_autoload_unregister($withoutConsole);
			foreach ($autoloaders as $autoloader) {
				spl_autoload_register($autoloader);
			}
		}
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

	/** @return array<string, array{bool, bool, bool}> */
	public static function cacheCommandRegistrationModes(): array
	{
		return [
			'cache and command disabled' => [false, false, false],
			'cache disabled, command requested' => [false, true, false],
			'cache enabled, command disabled' => [true, false, false],
			'cache and command enabled' => [true, true, true],
		];
	}

	/** @return array<string, array{bool}> */
	public static function consoleExtensionOrder(): array
	{
		return ['console first' => [true], 'redis first' => [false]];
	}

}
