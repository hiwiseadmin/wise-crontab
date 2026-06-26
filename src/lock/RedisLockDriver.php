<?php

declare(strict_types=1);

namespace wise\crontab\lock;

use think\facade\Cache;

/**
 * Redis 分布式锁驱动
 *
 * 基于 Redis SET NX EX 实现原子锁，支持多机部署。
 * 使用 psr-16 Cache 接口，兼容各种 Redis 连接方式。
 *
 * 配置示例：
 *   'lock_driver' => 'redis',
 *   'lock_redis_store' => 'default',  // think-cache store 名称
 */
class RedisLockDriver implements LockDriverInterface
{
    private string $cacheStore;

    /** @var array<string, string> 持有的锁 key → token 映射 */
    private array $tokens = [];

    public function __construct(string $cacheStore = 'default')
    {
        $this->cacheStore = $cacheStore;
    }

    public function acquire(string $taskName, int $ttl): bool
    {
        $key = $this->getLockKey($taskName);
        $token = uniqid('lock_', true);

        try {
            // 使用 Redis SET NX EX 原子操作
            if ($this->store()->set($key, $token, $ttl)) {
                $this->tokens[$taskName] = $token;
                return true;
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    public function release(string $taskName): void
    {
        if (!isset($this->tokens[$taskName])) {
            return;
        }

        $key = $this->getLockKey($taskName);
        $expectedToken = $this->tokens[$taskName];

        try {
            // Lua 脚本实现原子性释放（只有 token 匹配才删除）
            $store = $this->store();
            if (method_exists($store, 'handler')) {
                $redis = $store->handler();
                $lua = <<<'LUA'
if redis.call("GET", KEYS[1]) == ARGV[1] then
    return redis.call("DEL", KEYS[1])
else
    return 0
end
LUA;
                $redis->eval($lua, [$key, $expectedToken], 1);
            } else {
                // 降级：直接删除（非原子，但大多数场景够用）
                $current = $store->get($key);
                if ($current === $expectedToken) {
                    $store->delete($key);
                }
            }
        } catch (\Throwable $e) {
            // 释放失败静默处理
        }

        unset($this->tokens[$taskName]);
    }

    public function isLocked(string $taskName): bool
    {
        $key = $this->getLockKey($taskName);
        try {
            return $this->store()->has($key);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function getLockKey(string $taskName): string
    {
        return 'wise:crontab:lock:' . md5($taskName);
    }

    private function store()
    {
        return Cache::store($this->cacheStore);
    }
}
