<?php

declare(strict_types = 1);

use Mistrfilda\Redis\Lock\LockManager;
use Mistrfilda\Redis\Session\NativeSessionConfigurator;
use Mistrfilda\Redis\Tests\Fixtures\TestServer;

require __DIR__ . '/../../vendor/autoload.php';

$mode = $argv[1] ?? '';
$prefix = $argv[2] ?? '';
$identifier = $argv[3] ?? '';

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

if ($mode === 'session') {
	new NativeSessionConfigurator(TestServer::config(), $prefix)->configure();
	ini_set('session.use_cookies', '0');
	ini_set('session.use_strict_mode', '0');
	ini_set('session.cache_limiter', '');
	ini_set('session.serialize_handler', 'php_serialize');
	ini_set('session.gc_maxlifetime', '120');
	session_id($identifier);
	if (!session_start()) {
		throw new RuntimeException('Worker could not open its session.');
	}

	$count = $_SESSION['count'] ?? 0;
	if (!is_int($count)) {
		throw new RuntimeException('Unexpected session counter.');
	}

	$_SESSION['count'] = $count + 1;
	echo "opened\n";
	flush();
	fgets(STDIN);
	session_write_close();
	exit(0);
}

throw new RuntimeException('Unknown test worker mode.');
