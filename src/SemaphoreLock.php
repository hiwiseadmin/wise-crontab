<?php

declare(strict_types=1);

namespace wise\crontab;

use wise\support\lock\DistributedLock;

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
    /** 默认槽位 TTL（秒）：仅在没有宿主配置时兜底 */
    private const DEFAULT_SLOT_TTL = 7200;

    private DistributedLock $lock;
    private int $maxSlots;

    /**
     * 槽位 TTL（秒）
     *
     * 旧实现硬编码 7200：调度进程被 kill / OOM / worker reload 后，槽位会被
     * 残留占用 2 小时，期间所有任务只能拿到 null → 被静默丢弃。
     * 现在由调用方按「单轮最长等待 + 任务超时」推导。
     */
    private int $slotTtl;

    /** @var array<string, int> 已获取槽位: slotKey → slotIndex */
    private array $acquired = [];

    public function __construct(DistributedLock $lock, int $maxSlots = 3, ?int $slotTtl = null)
    {
        $this->lock = $lock;
        $this->maxSlots = max(1, $maxSlots);
        $this->slotTtl = $slotTtl !== null && $slotTtl > 0 ? $slotTtl : self::DEFAULT_SLOT_TTL;
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
            if ($this->lock->acquire($slotKey, $this->slotTtl)) {
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
            $this->lock->release($slotKey);
            unset($this->acquired[$slotKey]);
        }
    }

    /**
     * 释放当前实例已获取的所有槽位
     */
    public function releaseAllSlots(string $prefix = 'global'): void
    {
        $prefixPattern = "{$prefix}:slot:";
        foreach ($this->acquired as $key => $_) {
            if (str_starts_with($key, $prefixPattern)) {
                $this->lock->release($key);
                unset($this->acquired[$key]);
            }
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
            if ($this->lock->isAcquired($slotKey)) {
                $used++;
            }
        }
        return $this->maxSlots - $used;
    }
}
