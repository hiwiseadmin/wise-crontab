<?php

declare(strict_types=1);

namespace wise\crontab\lock;

use think\facade\Db;

/**
 * 数据库分布式锁驱动
 *
 * 基于 MySQL INSERT ... ON DUPLICATE KEY 或悲观锁实现。
 * 使用 crontab_lock 表作为锁存储。
 *
 * 配置示例：
 *   'lock_driver' => 'database',
 *   'lock_db_table' => 'crontab_lock',
 *   'lock_ttl' => 3600,
 */
class DatabaseLockDriver implements LockDriverInterface
{
    private string $table;
    private int $ttl;

    /** @var array<string, int> 持有的锁 ID 映射 */
    private array $lockIds = [];

    public function __construct(string $table = 'crontab_lock', int $ttl = 3600)
    {
        $this->table = $table;
        $this->ttl = $ttl;
        $this->ensureTable();
    }

    public function acquire(string $taskName, int $ttl): bool
    {
        $now = time();
        $expiresAt = $now + $ttl;
        $lockKey = md5($taskName);

        try {
            // 先清理过期锁
            Db::table($this->table)
                ->where('expires_at', '<', $now)
                ->delete();

            // 尝试获取锁：INSERT 唯一键，冲突即被占用
            $inserted = Db::table($this->table)->insert([
                'lock_key'   => $lockKey,
                'task_name'  => $taskName,
                'create_time' => $now,
                'expires_at' => $expiresAt,
            ]);

            if ($inserted) {
                $id = (int)Db::getLastInsID();
                $this->lockIds[$taskName] = $id;
                return true;
            }
        } catch (\Throwable $e) {
            // INSERT 冲突或连接失败
        }

        return false;
    }

    public function release(string $taskName): void
    {
        if (!isset($this->lockIds[$taskName])) {
            return;
        }

        try {
            Db::table($this->table)
                ->where('id', $this->lockIds[$taskName])
                ->delete();
        } catch (\Throwable $e) {
            // 释放失败静默处理
        }

        unset($this->lockIds[$taskName]);
    }

    public function isLocked(string $taskName): bool
    {
        $lockKey = md5($taskName);
        try {
            return Db::table($this->table)
                ->where('lock_key', $lockKey)
                ->where('expires_at', '>', time())
                ->count() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 确保锁表存在
     */
    private function ensureTable(): void
    {
        try {
            $exists = Db::query("SHOW TABLES LIKE '{$this->table}'");
            if (empty($exists)) {
                Db::execute("
                    CREATE TABLE `{$this->table}` (
                        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        `lock_key` VARCHAR(64) NOT NULL COMMENT '锁键（MD5）',
                        `task_name` VARCHAR(100) NOT NULL COMMENT '任务名称',
                        `create_time` INT UNSIGNED NOT NULL COMMENT '创建时间戳',
                        `expires_at` INT UNSIGNED NOT NULL COMMENT '过期时间戳',
                        UNIQUE KEY `uk_lock_key` (`lock_key`),
                        KEY `idx_expires_at` (`expires_at`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='定时任务分布式锁表'
                ");
            }
        } catch (\Throwable $e) {
            // 表创建失败静默
        }
    }
}
