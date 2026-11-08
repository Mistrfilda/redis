<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Tests\Fixtures;

use RuntimeException;
use const PHP_BINARY;

final class Worker
{

	/** @var resource */
	private $process;

	/** @var array<int, resource> */
	private array $pipes;

	private bool $closed = false;

	/** @param list<string> $arguments */
	public function __construct(array $arguments)
	{
		$pipes = [];
		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/redis-worker.php', ...$arguments],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
		);
		if ($process === false) {
			throw new RuntimeException('Could not start the integration test worker.');
		}

		$this->process = $process;
		$this->pipes = $pipes;
		stream_set_timeout($this->pipes[1], 5);
		stream_set_timeout($this->pipes[2], 5);
	}

	public function readLine(): string
	{
		$line = fgets($this->pipes[1]);
		if ($line === false) {
			throw new RuntimeException('Worker did not respond: ' . stream_get_contents($this->pipes[2]));
		}

		return trim($line);
	}

	public function isWaiting(): bool
	{
		$read = [$this->pipes[1]];
		$write = [];
		$except = [];
		return stream_select($read, $write, $except, 0, 100000) === 0;
	}

	public function proceed(): void
	{
		fwrite($this->pipes[0], "continue\n");
		fflush($this->pipes[0]);
	}

	public function finish(): int
	{
		foreach ($this->pipes as $pipe) {
			fclose($pipe);
		}

		$this->closed = true;
		return proc_close($this->process);
	}

	public function kill(): void
	{
		if (!$this->closed) {
			proc_terminate($this->process, 9);
			$this->finish();
		}
	}

	public function __destruct()
	{
		$this->kill();
	}

}
