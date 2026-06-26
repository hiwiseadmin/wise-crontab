<?php

declare(strict_types=1);

namespace wise\crontab\alert;

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

    /** @var array<string, int> 上次告警时间映射：task_name → timestamp */
    private static array $lastAlert = [];

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

        // 限流检查
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

        if (isset(self::$lastAlert[$taskName])) {
            return (time() - self::$lastAlert[$taskName]) < $throttle;
        }

        return false;
    }

    /**
     * 记录告警时间
     */
    private function recordAlert(string $taskName): void
    {
        self::$lastAlert[$taskName] = time();
    }

    /**
     * 构建告警消息
     */
    private function buildFailureMessage(TaskExecuteFailed $event): array
    {
        return [
            'title'   => "定时任务执行失败: {$event->taskName}",
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
            // 通过 ThinkPHP 事件系统广播告警，供外部监听器接入
            if (class_exists('\think\facade\Event')) {
                \think\facade\Event::trigger(new CrontabAlertEvent($message));
            }
        } catch (\Throwable $e) {
            // 告警发送失败不影响调度
        }
    }
}

/**
 * 定时任务告警事件
 */
class CrontabAlertEvent
{
    public function __construct(
        public readonly array $message
    ) {
    }
}
