<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Bridges\Nette\DI;

use LogicException;
use Mistrfilda\Redis\Bridges\Nette\Caching\RedisStorage;
use Mistrfilda\Redis\Bridges\Symfony\Console\CacheGarbageCollectCommand;
use Mistrfilda\Redis\Connection\ConnectionConfig;
use Mistrfilda\Redis\Connection\ConnectionFactory;
use Mistrfilda\Redis\Lock\LockManager;
use Mistrfilda\Redis\Session\NativeSessionConfigurator;
use Nette\Caching\Storage;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\Reference;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\Definitions\Statement;
use Nette\Http\Session;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Redis;
use Symfony\Component\Console\Command\Command;

/**
 * @phpstan-type ConnectionOptions array{
 *     host: string, port: int, database: int, timeout: float, readTimeout: float,
 *     username: string|null, password: string|null, scheme: string
 * }
 *
 * @property-read object{
 *     connections: array<string, ConnectionOptions>,
 *     defaultConnection: string,
 *     cache: object{
 *         enabled: bool, connection: string, prefix: string, serializer: string|null, registerGcCommand: bool
 *     },
 *     sessions: object{
 *         enabled: bool, connection: string, prefix: string, locking: bool,
 *         lockTtl: int, lockRetries: int, lockWaitMicroseconds: int
 *     },
 *     locks: object{enabled: bool, connection: string, prefix: string}
 * } $config
 */
final class RedisExtension extends CompilerExtension
{

	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'connections' => Expect::arrayOf(Expect::structure([
				'host' => Expect::string('127.0.0.1')->dynamic(),
				'port' => Expect::int(6379)->min(1)->max(65535)->dynamic(),
				'database' => Expect::int(0)->min(0)->dynamic(),
				'timeout' => Expect::float(2.0)->min(0.001)->dynamic(),
				'readTimeout' => Expect::float(2.0)->min(0.001)->dynamic(),
				'username' => Expect::string()->nullable()->dynamic(),
				'password' => Expect::string()->nullable()->dynamic(),
				'scheme' => Expect::anyOf('tcp', 'tls')->firstIsDefault(),
			])->castTo('array'), 'string')->default(['default' => []]),
			'defaultConnection' => Expect::string('default'),
			'cache' => Expect::structure([
				'enabled' => Expect::bool(false),
				'connection' => Expect::string('default'),
				'prefix' => Expect::string('mistrfilda:cache:')->min(1),
				'serializer' => Expect::string()->nullable(),
				'registerGcCommand' => Expect::bool(false),
			]),
			'sessions' => Expect::structure([
				'enabled' => Expect::bool(false),
				'connection' => Expect::string('default'),
				'prefix' => Expect::string('mistrfilda:session:')->min(1),
				'locking' => Expect::bool(true),
				'lockTtl' => Expect::int(60)->min(1),
				'lockRetries' => Expect::int(100)->min(0),
				'lockWaitMicroseconds' => Expect::int(20000)->min(1),
			]),
			'locks' => Expect::structure([
				'enabled' => Expect::bool(false),
				'connection' => Expect::string('default'),
				'prefix' => Expect::string('mistrfilda:lock:')->min(1),
			]),
		]);
	}

	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->config;

		if (!isset($config->connections[$config->defaultConnection])) {
			throw new LogicException('The default Redis connection does not exist.');
		}

		$factory = $builder->addDefinition($this->prefix('factory'))
			->setFactory(ConnectionFactory::class)
			->setAutowired(false);

		foreach ($config->connections as $name => $options) {
			$connectionConfig = $builder->addDefinition($this->prefix('connections.' . $name . '.config'))
				->setFactory(ConnectionConfig::class, $options)
				->setAutowired(false);
			$builder->addDefinition($this->prefix('connections.' . $name . '.client'))
				->setType(Redis::class)
				->setFactory([$factory, 'create'], [$connectionConfig])
				->setAutowired($name === $config->defaultConnection);
		}

		if ($config->cache->enabled) {
			if (!interface_exists(Storage::class)) {
				throw new LogicException('Install nette/caching to enable Redis cache storage.');
			}

			if ($config->cache->registerGcCommand) {
				if (!class_exists(Command::class)) {
					throw new LogicException(
						'Install symfony/console to register the Redis cache garbage collection command.',
					);
				}

				$builder->addDefinition($this->prefix('cacheGc'))
					->setFactory(CacheGarbageCollectCommand::class, [new Reference($this->prefix('cache'))]);
			}
		}

		if ($config->locks->enabled) {
			$builder->addDefinition($this->prefix('locks'))
				->setFactory(LockManager::class, [
					$this->client($config->locks->connection),
					$config->locks->prefix,
				]);
		}
	}

	public function beforeCompile(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->config;

		if ($config->cache->enabled) {
			$previous = $builder->getByType(Storage::class);
			if ($previous !== null) {
				$builder->getDefinition($previous)->setAutowired(false);
			}

			$storage = $builder->addDefinition($this->prefix('cache'))
				->setFactory(RedisStorage::class, [
					$this->client($config->cache->connection),
					$config->cache->prefix,
				]);
			if ($config->cache->serializer !== null) {
				$storage->setArgument('serializer', $config->cache->serializer);
			}
		}

		if ($config->sessions->enabled) {
			if (!class_exists(Session::class) || $builder->getByType(Session::class) === null) {
				throw new LogicException('Register Nette HTTP sessions before enabling Redis sessions.');
			}

			$this->client($config->sessions->connection);
			$sessionConfig = $builder->addDefinition($this->prefix('sessions'))
				->setFactory(NativeSessionConfigurator::class, [
					new Reference($this->prefix('connections.' . $config->sessions->connection . '.config')),
					$config->sessions->prefix,
					$config->sessions->locking,
					$config->sessions->lockTtl,
					$config->sessions->lockRetries,
					$config->sessions->lockWaitMicroseconds,
				])
				->setAutowired(false);
			$session = $builder->getDefinitionByType(Session::class);
			if (!$session instanceof ServiceDefinition) {
				throw new LogicException('Nette session must be registered as a service definition.');
			}

			$session->addSetup('setOptions', [new Statement([$sessionConfig, 'getOptions'])]);
			$session->addSetup([$sessionConfig, 'configure']);
		}
	}

	private function client(string $name): Reference
	{
		$service = $this->prefix('connections.' . $name . '.client');
		if (!$this->getContainerBuilder()->hasDefinition($service)) {
			throw new LogicException(sprintf('Redis connection "%s" does not exist.', $name));
		}

		return new Reference($service);
	}

}
