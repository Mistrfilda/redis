<?php

declare(strict_types = 1);

use Mistrfilda\Redis\Bridges\Nette\Caching\RedisStorage;
use Mistrfilda\Redis\Lock\LockManager;
use Mistrfilda\Redis\Session\NativeSessionConfigurator;
use Mistrfilda\Redis\Tests\Fixtures\CachedProfile;
use Mistrfilda\Redis\Tests\Fixtures\TestServer;
use Nette\Caching\Cache;

require __DIR__ . '/../../vendor/autoload.php';

$mode = $argv[1] ?? '';
$prefix = $argv[2] ?? '';
$identifier = $argv[3] ?? '';

if ($mode === 'cache-object') {
	$cache = new Cache(new RedisStorage(TestServer::connect(), $prefix));
	$value = $cache->load($identifier);
	if (!$value instanceof CachedProfile) {
		throw new RuntimeException('Worker did not restore the cached object.');
	}

	echo $value->name . ':' . $value->getVersion() . "\n";
	exit(0);
}

if ($mode === 'lock') {
	$lock = new LockManager(TestServer::connect(), $prefix)->acquire($identifier, (int) ($argv[4] ?? '30000'));
	if ($lock === null) {
		throw new RuntimeException('Worker could not acquire its lock.');
	}

	echo "acquired\n";
	flush();
	fgets(STDIN);
	$lock->release();
	exit(0);
}

if (in_array($mode, ['session', 'session-burst', 'session-timeout', 'session-closed'], true)) {
	new NativeSessionConfigurator(
		TestServer::config(),
		$prefix,
		lockRetries: $mode === 'session-timeout' ? 2 : 100,
		lockWaitMicroseconds: $mode === 'session-timeout' ? 1000 : 20000,
	)->configure();
	ini_set('session.use_cookies', '0');
	ini_set('session.use_strict_mode', '0');
	ini_set('session.cache_limiter', '');
	ini_set('session.serialize_handler', 'php_serialize');
	ini_set('session.gc_maxlifetime', '120');
	session_id($identifier);
	if ($mode === 'session-burst') {
		fwrite(STDOUT, "ready\n");
		fflush(STDOUT);
		fgets(STDIN);
	}

	if ($mode === 'session-timeout') {
		$warnings = [];
		set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
			$warnings[] = $message;
			return true;
		});
		try {
			$opened = session_start();
		} finally {
			restore_error_handler();
		}

		if (
			$opened
			|| !array_any(
				$warnings,
				static fn (string $message): bool => str_contains($message, 'Failed to acquire session lock'),
			)
		) {
			throw new RuntimeException('Expected a session lock acquisition timeout.');
		}

		echo "timed-out\n";
		exit(0);
	}

	if (!session_start()) {
		throw new RuntimeException('Worker could not open its session.');
	}

	$count = $_SESSION['count'] ?? 0;
	if (!is_int($count)) {
		throw new RuntimeException('Unexpected session counter.');
	}

	$_SESSION['count'] = $count + 1;
	if ($mode === 'session-burst' || $mode === 'session-closed') {
		session_write_close();
		echo "closed\n";
		flush();
		if ($mode === 'session-closed') {
			fgets(STDIN);
		}

		exit(0);
	}

	echo "opened\n";
	flush();
	fgets(STDIN);
	session_write_close();
	exit(0);
}

throw new RuntimeException('Unknown test worker mode.');
