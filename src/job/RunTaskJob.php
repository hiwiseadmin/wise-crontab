<?php

declare(strict_types=1);

namespace wise\crontab\job;

use wise\crontab\exception\CrontabException;
use wise\crontab\service\TaskAdminService;

/**
 * 「立即执行」队列任务＼�
 *
 * 为什么必须异步：
 *   - `TaskExecutor` 内部用 Symfony Process 起子进程执行命令，属于阻塞调用；
 *   - HTTP 路径里同步执行会超时，并且触碰「HTTP 内禁止 exec/sleep」的 DoD 红线。
 *
 * 执行链路：控制器 `Task@run` → `QueueService::dispatch()` → worker →
 * `TaskAdminService::runNow()`（内部 `TaskExecutor` + `TaskLock` 互斥）。
 *
 * 并发防重：`allow_concurrent = false` 的任务在 `TaskExecutor` 里拿不到 `TaskLock`
 * 时直接落一条 `skipped` 日志并返回，不会重复执行（验收 #8）。
 *
 * handler 注册：`CrontabServiceProvider::registerQueueHandlers()`。
 */
class RunTaskJob
{
    /** 队列 handler 标识 */
    public const HANDLER = 'crontab:run';

    /**
     * @param array{name?:string} $data    待执行任务名
     * @param mixed               $context 受管任务上下文（app\common\job\TaskContext，可空）
     *
     * @return array<string,mixed>
     *
     * @throws CrontabException 任务不存在
     */
    public function run(array $data, $context = null): array
    {
        unset($context);

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('缺少任务名称（payload.name）');
        }

        $service = new TaskAdminService((array) config('wise-crontab', []));
        $result  = $service->runNow($name);

        return [
            'task'         => $name,
            'status'       => (string) ($result['status'] ?? 'unknown'),
            'exit_code'    => $result['exit_code'] ?? null,
            'duration'     => (float) ($result['duration'] ?? 0),
            'retry_count'  => (int) ($result['retry_count'] ?? 0),
            'timeout'      => (bool) ($result['timeout'] ?? false),
            'output'       => $this->clip((string) ($result['output'] ?? '')),
            'error_output' => $this->clip((string) ($result['error_output'] ?? '')),
        ];
    }

    /** 结果回执只留摘要，完整输出在 crontab_task_log */
    private function clip(string $text, int $limit = 2000): string
    {
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
    }
}
