<?php

declare(strict_types=1);

namespace wise\crontab;

use wise\crontab\lock\LockDriverInterface;
use wise\crontab\lock\FileLockDriver;
use wise\crontab\lock\RedisLockDriver;
use wise\crontab\lock\DatabaseLockDriver;

class TaskLock
{
    private LockDriverInterface $driver;
    private int $ttl;
    private string $lockDir;

    /**
     * @param string $lockDir 锁文件目录（仅 file 驱动使用）
     * @param int    $ttl     锁过期时间（秒）
     * @param array  $config  完整配置（含 lock_driver）
     */
    public function __construct(string $lockDir = '', int $ttl = 3600, array $config = [])
    {
        $this->lockDir = $lockDir;
        $this->ttl = $ttl;

        $driver = $config['lock_driver'] ?? 'file';
        $this->driver = $this->createDriver($driver, $config);
    }

    /**
     * 创建锁驱动实例
     */
    private function createDriver(string $driver, array $config): LockDriverInterface
    {
        return match ($driver) {
            'redis' => new RedisLockDriver($config['lock_redis_store'] ?? 'default'),
            'database' => new DatabaseLockDriver(
                $config['lock_db_table'] ?? 'crontab_lock',
                $this->ttl
            ),
            default => new FileLockDriver($this->lockDir),
        };
    }

    /**
     * 获取底层驱动（用于 SemaphoreLock 等场景）
     */
    public function getDriver(): LockDriverInterface
    {
        return $this->driver;
    }

    /**
     * 尝试获取任务锁
     */
    public function acquire(string $taskName): bool
    {
        if ($this->driver->acquire($taskName, $this->ttl)) {
            return true;
        }
        return false;
    }

    /**
     * 释放任务锁
     */
    public function release(string $taskName): void
    {
        $this->driver->release($taskName);
    }

    /**
     * 检查任务是否被锁定
     */
    public function isLocked(string $taskName): bool
    {
        return $this->driver->isLocked($taskName);
    }
}
