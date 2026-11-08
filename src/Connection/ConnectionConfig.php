<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Connection;

use InvalidArgumentException;
use SensitiveParameter;
use const PHP_QUERY_RFC3986;

final readonly class ConnectionConfig
{

	public function __construct(
		public string $host = '127.0.0.1',
		public int $port = 6379,
		public int $database = 0,
		public float $timeout = 2.0,
		public float $readTimeout = 2.0,
		public string|null $username = null,
		#[SensitiveParameter]
		public string|null $password = null,
		public string $scheme = 'tcp',
	)
	{
		if ($host === '' || strpbrk($host, "/?#@&, \r\n") !== false) {
			throw new InvalidArgumentException('Redis host must be a hostname or IP address without a scheme.');
		}

		if ($port < 1 || $port > 65535 || $database < 0) {
			throw new InvalidArgumentException(
				'Redis port must be between 1 and 65535 and database must not be negative.',
			);
		}

		if (!is_finite($timeout) || !is_finite($readTimeout) || $timeout <= 0 || $readTimeout <= 0) {
			throw new InvalidArgumentException('Redis connection and read timeouts must be finite positive numbers.');
		}

		if (!in_array($scheme, ['tcp', 'tls'], true)) {
			throw new InvalidArgumentException('Redis scheme must be tcp or tls.');
		}

		if ($username !== null && ($username === '' || $password === null)) {
			throw new InvalidArgumentException('Redis ACL authentication requires a nonempty username and a password.');
		}
	}

	public function getHost(): string
	{
		$host = str_contains($this->host, ':') && !str_starts_with($this->host, '[')
			? '[' . $this->host . ']'
			: $this->host;

		return $this->scheme === 'tls' ? 'tls://' . $host : $host;
	}

	public function getSessionSavePath(string $prefix): string
	{
		$host = str_contains($this->host, ':') && !str_starts_with($this->host, '[')
			? '[' . $this->host . ']'
			: $this->host;
		$options = [
			'database' => $this->database,
			'timeout' => $this->timeout,
			'read_timeout' => $this->readTimeout,
			'prefix' => $prefix,
		];

		if ($this->password !== null) {
			$options['auth'] = $this->username === null ? $this->password : [$this->username, $this->password];
		}

		return sprintf(
			'%s://%s:%d?%s',
			$this->scheme,
			$host,
			$this->port,
			http_build_query($options, '', '&', PHP_QUERY_RFC3986),
		);
	}

}
