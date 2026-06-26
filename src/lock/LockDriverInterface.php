<?php

declare(strict_types=1);

namespace wise\crontab\lock;

/**
 * 定时任务锁驱动接口
 *
 * 支持多种锁后端：文件锁、Redis 锁、数据库锁。
 * 所有实现必须保证 acquire/release 操作的原子性。
 */
interface LockDriverInterface
{
    /**
     * 尝试获取任务锁
     *
     * @param string $taskName 任务名称
     * @param int    $ttl      锁过期时间（秒）
     * @return bool 是否成功获取
     */
    public function acquire(string $taskName, int $ttl): bool;

    /**
     * 释放任务锁
     *
     * @param string $taskName 任务名称
     */
    public function release(string $taskName): void;

    /**
     * 检查任务是否被锁定
     *
     * @param string $taskName 任务名称
     * @return bool
     */
    public function isLocked(string $taskName): bool;
}
