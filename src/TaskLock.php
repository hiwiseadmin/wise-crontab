<?php

declare(strict_types=1);

namespace wise\crontab;

use wise\support\lock\DistributedLock;

class TaskLock
{
    private DistributedLock $lock;
    private int $ttl;

    /**
     * @param string $lockDir 锁文件目录（仅 file 驱动使用）
     * @param int    $ttl     锁过期时间（秒）
     * @param array  $config  完整配置（含 lock_driver）
     */
    public function __construct(string $lockDir = '', int $ttl = 3600, array $config = [])
    {
        $this->ttl = $ttl;

        $driver = $config['lock_driver'] ?? 'file';

        $this->lock = new DistributedLock($driver, [
            'redis_store' => $config['lock_redis_store'] ?? 'redis',
            'lock_dir'    => $lockDir,
            'db_table'    => $config['lock_db_table'] ?? 'crontab_lock',
        ]);
    }

    /**
     * 获取底层 DistributedLock 实例（用于 SemaphoreLock 等场景）
     */
    public function getLock(): DistributedLock
    {
        return $this->lock;
    }

    /**
     * 尝试获取任务锁
     */
    public function acquire(string $taskName): bool
    {
        return $this->lock->acquire($taskName, $this->ttl);
    }

    /**
     * 释放任务锁
     */
    public function release(string $taskName): void
    {
        $this->lock->release($taskName);
    }

    /**
     * 检查任务是否被锁定
     */
    public function isLocked(string $taskName): bool
    {
        return $this->lock->isAcquired($taskName);
    }
}
