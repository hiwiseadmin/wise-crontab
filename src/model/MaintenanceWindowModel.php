<?php

declare(strict_types=1);

namespace wise\crontab\model;

use think\Model;

/**
 * 定时任务维护窗口模型
 *
 * 对应表：crontab_maintenance_window
 * 定义任务不可执行的时间窗口，task_id 为 NULL 表示全局窗口。
 */
class MaintenanceWindowModel extends Model
{
    protected $name = 'crontab_maintenance_window';

    protected $autoWriteTimestamp = false;

    protected $schema = [
        'id'           => 'int',
        'task_id'      => 'int',
        'start_at'     => 'string',
        'end_at'       => 'string',
        'days_of_week' => 'string',
        'reason'       => 'string',
    ];

    /**
     * 检查当前时间是否落在维护窗口内
     *
     * 规则：
     *  - `task_id` 为 NULL 表示全局窗口；指定任务时命中「该任务专用窗口 + 全局窗口」
     *  - 支持跨午夜窗口（如 22:00-02:00）
     *  - `days_of_week` 支持 `*`、`0,6`、`1-5` 及其组合（如 `1-3,6`）
     *
     * @param int|null                 $taskId 任务ID，null 时只检查全局窗口
     * @param \DateTimeInterface|null  $at     基准时间（便于测试），默认当前时间
     *
     * @return array|null 命中的窗口，null 表示不在窗口内
     */
    public static function isInWindow(?int $taskId = null, ?\DateTimeInterface $at = null): ?array
    {
        $query = self::whereNull('task_id');
        if ($taskId !== null && $taskId > 0) {
            $query->whereOr('task_id', $taskId);
        }

        try {
            $windows = $query->select()->toArray();
        } catch (\Throwable $e) {
            return null;
        }

        return self::matchWindows($windows, $taskId, $at);
    }

    /**
     * 在已取出的窗口集合中匹配（便于调度器一次查库、多次匹配）
     *
     * 旧实现由调度器对每个到期任务调用一次 isInWindow()，N 个任务就是 N 次
     * 全表查询；改为「取一次 + 内存匹配」后每轮调度只有 1 次查询。
     *
     * @param array<int,array>        $windows 窗口集合（task_id 可为 null）
     * @param int|null                $taskId  任务ID，null 时只匹配全局窗口
     * @param \DateTimeInterface|null $at      基准时间
     *
     * @return array|null 命中的窗口，null 表示不在窗口内
     */
    public static function matchWindows(array $windows, ?int $taskId = null, ?\DateTimeInterface $at = null): ?array
    {
        $at = $at ?? new \DateTime('now');
        $nowMinutes = ((int) $at->format('H')) * 60 + (int) $at->format('i');
        $dayOfWeek = (int) $at->format('w');

        foreach ($windows as $window) {
            $windowTaskId = $window['task_id'] ?? null;
            if ($windowTaskId !== null && (int) $windowTaskId !== (int) $taskId) {
                continue;
            }

            if (!self::inTimeRange($nowMinutes, (string)($window['start_at'] ?? '00:00'), (string)($window['end_at'] ?? '00:00'))) {
                continue;
            }

            if (!self::matchesDayOfWeek((string)($window['days_of_week'] ?? '*'), $dayOfWeek)) {
                continue;
            }

            return $window;
        }

        return null;
    }

    /**
     * 判断分钟数是否落在时间区间内（支持跨午夜）
     */
    private static function inTimeRange(int $minutes, string $startAt, string $endAt): bool
    {
        $start = self::timeToMinutes($startAt);
        $end = self::timeToMinutes($endAt);

        if ($start > $end) {
            return $minutes >= $start || $minutes <= $end;
        }

        return $minutes >= $start && $minutes <= $end;
    }

    /**
     * `HH:MM[:SS]` → 当日分钟数
     */
    private static function timeToMinutes(string $time): int
    {
        $time = trim($time);
        if ($time === '') {
            return 0;
        }

        $parts = explode(':', $time);
        $hour = (int) ($parts[0] ?? 0);
        $minute = (int) ($parts[1] ?? 0);

        return max(0, min(23, $hour)) * 60 + max(0, min(59, $minute));
    }

    /**
     * 星期表达式匹配：支持 `*`、`0,6`、`1-5` 及其组合
     */
    private static function matchesDayOfWeek(string $expression, int $dayOfWeek): bool
    {
        $expression = trim($expression);
        if ($expression === '' || $expression === '*') {
            return true;
        }

        foreach (explode(',', $expression) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (str_contains($part, '-')) {
                [$from, $to] = array_map('intval', explode('-', $part, 2));
                if ($from <= $to) {
                    if ($dayOfWeek >= $from && $dayOfWeek <= $to) {
                        return true;
                    }
                } elseif ($dayOfWeek >= $from || $dayOfWeek <= $to) {
                    return true;
                }
                continue;
            }

            if ((int) $part === $dayOfWeek) {
                return true;
            }
        }

        return false;
    }
}
