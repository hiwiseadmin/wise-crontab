<?php

declare(strict_types=1);

namespace wise\crontab\alert;

use wise\crontab\event\CrontabAlertEvent;
use wise\crontab\event\TaskExecuteFailed;
use wise\crontab\model\TaskLogModel;

/**
 * 定时任务失败告警监听器
 *
 * 自动监听 TaskExecuteFailed 事件，根据配置发送多渠道通知。
 * 内置限流机制：同一任务在 throttle 秒内只告警一次。
 *
 * 配置示例 (config/wise-crontab.php)：
 *   'alert' => [
 *       'enabled' => true,
 *       'on_failure' => true,
 *       'on_timeout' => true,
 *       'channels' => ['email', 'dingtalk'],
 *       'throttle' => 300,
 *       'recipients' => [],
 *   ],
 */
class AlertManager
{
    private array $config;

    /**
     * 上次告警时间映射：task_name → timestamp（仅本实例内存缓存）
     *
     * 刻意不用 static：静态属性在 Swoole / Workerman 常驻 worker 里会跨请求、
     * 跨租户残留（且无上限增长），而多机部署时又不共享 —— 两个方向都是错的。
     * 进程间/机器间的一致性交给缓存（见 isThrottled / recordAlert）。
     *
     * @var array<string, int>
     */
    private array $lastAlert = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * 处理任务执行失败事件
     */
    public function handleFailure(TaskExecuteFailed $event): void
    {
        if (empty($this->config['enabled'])) {
            return;
        }

        if (empty($this->config['on_failure'])) {
            return;
        }

        if ($this->isThrottled($event->taskName)) {
            return;
        }

        $message = $this->buildFailureMessage($event);
        $this->send($message);
        $this->recordAlert($event->taskName);
    }

    /**
     * 检查是否超过限流间隔
     */
    private function isThrottled(string $taskName): bool
    {
        $throttle = (int)($this->config['throttle'] ?? 300);
        if ($throttle <= 0) {
            return false;
        }

        $lastAt = $this->lastAlert[$taskName] ?? $this->lastAlertedAtCached($taskName);
        if ($lastAt === null) {
            return false;
        }

        return (time() - $lastAt) < $throttle;
    }

    /**
     * 上一次告警时间：先查缓存，取不到再退回日志推断
     */
    private function lastAlertedAtCached(string $taskName): ?int
    {
        $cached = $this->cacheGet($this->throttleKey($taskName));
        if ($cached !== null) {
            return (int) $cached;
        }

        return $this->lastAlertedAt($taskName);
    }

    /** 告警限流的缓存键（前缀固定，便于运维排查/清理） */
    private function throttleKey(string $taskName): string
    {
        return 'crontab:alert:' . md5($taskName);
    }

    /**
     * 读缓存（缓存不可用时返回 null，退化为日志推断）
     */
    private function cacheGet(string $key): mixed
    {
        try {
            if (!class_exists(\think\facade\Cache::class)) {
                return null;
            }

            return \think\facade\Cache::get($key);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 写缓存（缓存不可用时静默，最多退化为「同一进程内限流 + 日志推断」）
     */
    private function cacheSet(string $key, int $value, int $ttl): void
    {
        try {
            if (!class_exists(\think\facade\Cache::class)) {
                return;
            }

            \think\facade\Cache::set($key, $value, max(1, $ttl));
        } catch (\Throwable $e) {
        }
    }

    /**
     * 上一次告警时间（跨 CLI 进程持久化）
     *
     * 调度器每分钟以独立 CLI 进程运行，纯内存限流会失效；这里以执行日志中
     * 「上一次失败」的时间作为近似——本次失败刚刚写库（最新一条），
     * 取倒数第二条即为上次告警时刻；查不到则说明是首次失败，不节流。
     */
    private function lastAlertedAt(string $taskName): ?int
    {
        try {
            $logs = TaskLogModel::where('task_name', $taskName)
                ->where('status', 'failure')
                ->order('id', 'desc')
                ->limit(2)
                ->select()
                ->toArray();

            if (count($logs) < 2) {
                return null;
            }

            $timestamp = strtotime((string) ($logs[1]['create_time'] ?? ''));

            return $timestamp === false ? null : $timestamp;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 记录告警时间
     */
    private function recordAlert(string $taskName): void
    {
        $this->lastAlert[$taskName] = time();

        $this->cacheSet($this->throttleKey($taskName), time(), (int) ($this->config['throttle'] ?? 300));
    }

    /**
     * 处理任务执行超时事件
     *
     * 由 `alert.on_timeout` 控制；未配置时默认开启，与 `on_failure` 互不影响。
     */
    public function handleTimeout(TaskExecuteFailed $event): void
    {
        if (empty($this->config['enabled'])) {
            return;
        }

        if (!($this->config['on_timeout'] ?? true)) {
            return;
        }

        if ($this->isThrottled($event->taskName)) {
            return;
        }

        $message = $this->buildFailureMessage($event, '执行超时');
        $message['timeout'] = true;
        $this->send($message);
        $this->recordAlert($event->taskName);
    }

    /**
     * 构建告警消息
     *
     * @param string $scene 场景描述：执行失败 / 执行超时
     */
    private function buildFailureMessage(TaskExecuteFailed $event, string $scene = '执行失败'): array
    {
        return [
            'title'   => "定时任务{$scene}: {$event->taskName}",
            'content' => sprintf(
                "任务: %s\n命令: %s\n退出码: %d\n耗时: %.2fs\n重试次数: %d\n错误输出: %s",
                $event->taskName,
                $event->command,
                $event->exitCode,
                $event->duration,
                $event->retryCount,
                mb_substr($event->errorOutput, 0, 500)
            ),
            'channels' => (array)($this->config['channels'] ?? ['email']),
            'recipients' => (array)($this->config['recipients'] ?? []),
            'task_name' => $event->taskName,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 发送告警（通过框架事件或通知系统）
     */
    private function send(array $message): void
    {
        try {
            if (class_exists('\think\facade\Event')) {
                \think\facade\Event::trigger(new CrontabAlertEvent($message));
            }
        } catch (\Throwable $e) {
        }
    }
}
