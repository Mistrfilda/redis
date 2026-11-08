<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Tests\Integration;

use Mistrfilda\Redis\Bridges\Nette\Caching\RedisStorage;
use Mistrfilda\Redis\Bridges\Nette\DI\RedisExtension;
use Mistrfilda\Redis\Tests\Fixtures\RedisTestCase;
use Mistrfilda\Redis\Tests\Fixtures\TestServer;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Caching\Storages\MemoryStorage;
use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\DI\Definitions\Statement;
use Nette\Http\Request;
use Nette\Http\Response;
use Nette\Http\Session;
use Nette\Http\UrlScript;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Redis;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NetteExtensionTest extends RedisTestCase
{

	public function testNeonWiresCacheAndSessionsToDifferentDatabases(): void
	{
		$server = TestServer::config();
		$sessionDatabase = $server->database === 15 ? 14 : 15;
		$compiler = new Compiler();
		$class = 'RedisTestContainer' . bin2hex(random_bytes(8));
		$compiler->setClassName($class);
		$compiler->addExtension('redis', new RedisExtension());
		$compiler->addConfig([
			'parameters' => [
				'redisHost' => $server->host,
				'redisPort' => $server->port,
				'cacheDatabase' => $server->database,
				'sessionDatabase' => $sessionDatabase,
			],
		]);
		$compiler->loadConfig(__DIR__ . '/../Fixtures/nette.neon');
		$builder = $compiler->getContainerBuilder();
		$builder->addDefinition('previousStorage')->setFactory(MemoryStorage::class);
		$builder->addDefinition('request')->setFactory(
			Request::class,
			[new Statement(UrlScript::class, ['http://localhost/'])],
		);
		$builder->addDefinition('response')->setFactory(Response::class);
		$builder->addDefinition('session')->setFactory(Session::class)->addSetup('setExpiration', ['14 days']);
		$code = $compiler->compile();
		$file = tempnam(sys_get_temp_dir(), 'redis-di-');
		self::assertNotFalse($file);
		file_put_contents($file, "<?php\n" . $code);
		try {
			require $file;
			$container = new $class();
			self::assertInstanceOf(Container::class, $container);
			$storage = $container->getByType(Storage::class);
			self::assertInstanceOf(RedisStorage::class, $storage);
			$cache = new Cache($storage);
			$cache->save('example', 'cached');
			self::assertSame('cached', $cache->load('example'));
			$sessionsClient = $container->getService('redis.connections.sessions.client');
			self::assertInstanceOf(Redis::class, $sessionsClient);
			$sessionsClient->flushDB();
			try {
				$session = $container->getByType(Session::class);
				$session->start();
				$id = session_id();
				$session->getSection('example')->set('value', 'saved');
				$session->close();
				self::assertNotFalse($sessionsClient->get('nette-session:' . $id));
				self::assertGreaterThan(1209500, $sessionsClient->ttl('nette-session:' . $id));
				$cache->clean([Cache::All => true]);
				self::assertNotFalse($sessionsClient->get('nette-session:' . $id));
			} finally {
				$sessionsClient->flushDB();
				$sessionsClient->close();
			}
		} finally {
			unlink($file);
		}
	}

}
