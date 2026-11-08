<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Bridges\Nette\Caching;

use Redis;
use UnexpectedValueException;

/** @internal Updates cache entries and their indexes in a single server operation. */
final readonly class RedisJournal
{

	public function __construct(private Redis $redis, private string $prefix)
	{
	}

	/** @param list<string> $tags */
	public function write(string $key, string $payload, int $ttlSeconds, array $tags, int|null $priority): void
	{
		$script = <<<'LUA'
local previous = redis.call('SMEMBERS', KEYS[2])
for _, tag in ipairs(previous) do
	redis.call('SREM', tag, KEYS[1])
end
redis.call('DEL', KEYS[2])
for i = 4, #ARGV do
	redis.call('SADD', ARGV[i], KEYS[1])
	redis.call('SADD', KEYS[2], ARGV[i])
end
redis.call('SADD', KEYS[3], KEYS[1])
if ARGV[3] == '' then
	redis.call('ZREM', KEYS[4], KEYS[1])
else
	redis.call('ZADD', KEYS[4], ARGV[3], KEYS[1])
end
if tonumber(ARGV[2]) > 0 then
	redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[2])
else
	redis.call('SET', KEYS[1], ARGV[1])
end
return 1
LUA;
		$tagKeys = array_map($this->tagKey(...), array_values(array_unique($tags)));
		$this->redis->eval($script, [
			...$this->keys($key),
			$payload,
			$ttlSeconds,
			$priority === null ? '' : (string) $priority,
			...$tagKeys,
		], 4);
	}

	public function remove(
		string $key,
		string|null $expectedPayload = null,
		string|null $requiredTag = null,
		int|null $maximumPriority = null,
		bool $onlyExpired = false,
	): void
	{
		$script = <<<'LUA'
if ARGV[1] == '1' and redis.call('GET', KEYS[1]) ~= ARGV[2] then return 0 end
if ARGV[3] ~= '' and redis.call('SISMEMBER', KEYS[2], ARGV[3]) == 0 then return 0 end
if ARGV[4] ~= '' then
	local priority = redis.call('ZSCORE', KEYS[4], KEYS[1])
	if not priority or tonumber(priority) > tonumber(ARGV[4]) then return 0 end
end
if ARGV[5] == '1' and redis.call('EXISTS', KEYS[1]) == 1 then return 0 end
for _, tag in ipairs(redis.call('SMEMBERS', KEYS[2])) do
	redis.call('SREM', tag, KEYS[1])
end
redis.call('SREM', KEYS[3], KEYS[1])
redis.call('ZREM', KEYS[4], KEYS[1])
redis.call('DEL', KEYS[1], KEYS[2])
return 1
LUA;
		$this->redis->eval($script, [
			...$this->keys($key),
			$expectedPayload === null ? '0' : '1',
			$expectedPayload ?? '',
			$requiredTag === null ? '' : $this->tagKey($requiredTag),
			$maximumPriority === null ? '' : (string) $maximumPriority,
			$onlyExpired ? '1' : '0',
		], 4);
	}

	public function cleanTag(string $tag): void
	{
		foreach ($this->redis->sMembers($this->tagKey($tag)) as $key) {
			$this->remove($key, requiredTag: $tag);
		}
	}

	public function cleanPriority(int $priority): void
	{
		foreach ($this->redis->zRangeByScore($this->prefix . 'priorities', '-inf', (string) $priority) as $key) {
			if (!is_string($key)) {
				throw new UnexpectedValueException('Redis priority index must contain string keys.');
			}

			$this->remove($key, maximumPriority: $priority);
		}
	}

	public function collectGarbage(): void
	{
		$cursor = null;
		do {
			$keys = $this->redis->sscan($this->prefix . 'entries', $cursor, count: 100);
			if ($keys !== false) {
				foreach ($keys as $key) {
					$this->remove($key, onlyExpired: true);
				}
			}
		} while ($cursor !== 0);
	}

	/** @return list<string> */
	private function keys(string $key): array
	{
		return [$key, $key . ':tags', $this->prefix . 'entries', $this->prefix . 'priorities'];
	}

	private function tagKey(string $tag): string
	{
		return $this->prefix . 'tag:' . hash('sha256', $tag);
	}

}
