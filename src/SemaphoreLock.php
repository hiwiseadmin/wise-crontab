<?php

declare(strict_types=1);

namespace wise\crontab;

use wise\crontab\lock\LockDriverInterface;

/**
 * 全局信号量锁 — 限制同时执行的任务总数
 *
 * 基于槽位机制：
 * - maxSlots 个并发槽位
 * - acquireSlot() 获取一个槽位
 * - releaseSlot() 释放槽位
 * - availableSlots() 查询可用槽位
 */
class SemaphoreLock
{
    private LockDriverInterface $driver;
    private int $maxSlots;

    /** @var array<string, int> 已获取槽位: slotKey → slotIndex */
    private array $acquired = [];

    public function __construct(LockDriverInterface $driver, int $maxSlots = 3)
    {
        $this->driver = $driver;
        $this->maxSlots = $maxSlots;
    }

    /**
     * 尝试获取一个执行槽位
     *
     * @param string $prefix 槽位前缀（用于区分不同实例）
     * @return int|null 槽位编号，null 表示无可用槽位
     */
    public function acquireSlot(string $prefix = 'global'): ?int
    {
        for ($i = 0; $i < $this->maxSlots; $i++) {
            $slotKey = "{$prefix}:slot:{$i}";
            if ($this->driver->acquire($slotKey, 7200)) {
                $this->acquired[$slotKey] = $i;
                return $i;
            }
        }
        return null;
    }

    /**
     * 释放指定槽位
     */
    public function releaseSlot(string $prefix = 'global', int $slotIndex = 0): void
    {
        $slotKey = "{$prefix}:slot:{$slotIndex}";
        if (isset($this->acquired[$slotKey])) {
            $this->driver->release($slotKey);
            unset($this->acquired[$slotKey]);
        }
    }

    /**
     * 获取可用槽位数
     */
    public function availableSlots(string $prefix = 'global'): int
    {
        $used = 0;
        for ($i = 0; $i < $this->maxSlots; $i++) {
            $slotKey = "{$prefix}:slot:{$i}";
            if ($this->driver->isLocked($slotKey)) {
                $used++;
            }
        }
        return $this->maxSlots - $used;
    }
}
