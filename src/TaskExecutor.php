<?php

declare(strict_types=1);

namespace wise\crontab;

use Symfony\Component\Process\Process;
use wise\crontab\model\TaskLogModel;

class TaskExecutor
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * 执行定时任务
     *
     * @param array    $task 任务配置
     * @param TaskLock $lock 锁管理器
     * @return array 执行结果统计
     */
    public function execute(array $task, TaskLock $lock): array
    {
        $taskName = $task['name'] ?? 'unknown';
        $command = $task['command'] ?? '';
        $allowConcurrent = !empty($task['allow_concurrent']);
        $maxRetries = (int)($task['max_retries'] ?? $this->config['default_max_retries'] ?? 0);
        $timeout = (float)($task['timeout'] ?? $this->config['default_timeout'] ?? 300);

        // 1. 检查并发限制
        if (!$allowConcurrent) {
            if (!$lock->acquire($taskName)) {
                $this->createLog($task, 'skipped', '', '', null);
                return [
                    'status'      => 'skipped',
                    'exit_code'   => null,
                    'output'      => '',
                    'error_output' => '',
                    'duration'    => 0,
                    'retry_count' => 0,
                ];
            }
        }

        // 2. 创建运行中日志（先占位，PID 稍后更新）
        $logId = $this->createLog($task, 'running', '', '', null);

        $retryCount = 0;
        $startTime = microtime(true);
        $output = '';
        $errorOutput = '';
        $exitCode = null;
        $processId = null;

        // 3. 执行并重试
        do {
            try {
                $process = Process::fromShellCommandline($command);
                $process->setTimeout($timeout);
                $process->run();

                $exitCode = $process->getExitCode();
                $output = $this->truncateOutput($process->getOutput());
                $errorOutput = $this->truncateOutput($process->getErrorOutput());

                // 捕获进程 PID（首次即可）
                if ($processId === null) {
                    $processId = $process->getPid();
                    if ($processId) {
                        $this->updateLogProcessId($logId, $processId);
                    }
                }
            } catch (\Symfony\Component\Process\Exception\InvalidArgumentException $e) {
                // Windows 环境块过大等问题导致进程无法启动
                $exitCode = -1;
                $output = '';
                $errorOutput = '进程启动失败: ' . $e->getMessage();
            }
            $duration = round(microtime(true) - $startTime, 4);

            // 4. 成功
            if ($exitCode === 0) {
                $this->updateLog($logId, 'success', $output, $errorOutput, $exitCode, $duration, $retryCount);

                if (!$allowConcurrent) {
                    $lock->release($taskName);
                }

                return [
                    'status'       => 'success',
                    'exit_code'    => $exitCode,
                    'output'       => $output,
                    'error_output' => $errorOutput,
                    'duration'     => $duration,
                    'retry_count'  => $retryCount,
                ];
            }

            $retryCount++;
        } while ($retryCount <= $maxRetries);

        // 5. 所有重试均失败
        $duration = round(microtime(true) - $startTime, 4);
        $this->updateLog($logId, 'failure', $output, $errorOutput, $exitCode, $duration, $retryCount - 1);

        if (!$allowConcurrent) {
            $lock->release($taskName);
        }

        return [
            'status'       => 'failure',
            'exit_code'    => $exitCode,
            'output'       => $output,
            'error_output' => $errorOutput,
            'duration'     => $duration,
            'retry_count'  => $retryCount - 1,
        ];
    }

    /**
     * 截断输出（超过配置的最大字节数）
     */
    private function truncateOutput(string $output): string
    {
        $maxBytes = (int)($this->config['output_max_bytes'] ?? 65536);
        if (strlen($output) > $maxBytes) {
            return substr($output, 0, $maxBytes) . "\n\n[输出已截断，超出 {$maxBytes} 字节限制]";
        }
        return $output;
    }

    /**
     * 创建任务执行日志
     */
    private function createLog(array $task, string $status, string $output, string $errorOutput, ?int $exitCode, ?int $processId = null): int
    {
        try {
            $log = new TaskLogModel();
            $log->task_id     = $task['id'] ?? 0;
            $log->task_name   = $task['name'] ?? 'unknown';
            $log->status      = $status;
            $log->output      = $output;
            $log->error_output = $errorOutput;
            $log->exit_code   = $exitCode;
            $log->start_time  = date('Y-m-d H:i:s');
            if ($processId !== null) {
                $log->process_id = $processId;
            }
            $log->save();

            return (int)$log->id;
        } catch (\Throwable $e) {
            // 日志写入失败不影响任务执行
            return 0;
        }
    }

    /**
     * 更新任务执行日志
     */
    private function updateLog(int $logId, string $status, string $output, string $errorOutput, int $exitCode, float $duration, int $retryCount): void
    {
        try {
            $log = TaskLogModel::find($logId);
            if ($log) {
                $log->status       = $status;
                $log->output       = $output;
                $log->error_output = $errorOutput;
                $log->exit_code    = $exitCode;
                $log->end_time     = date('Y-m-d H:i:s');
                $log->duration     = $duration;
                $log->retry_count  = $retryCount;
                $log->save();
            }
        } catch (\Throwable $e) {
            // 日志更新失败不影响任务执行
        }
    }

    /**
     * 更新任务日志的进程 ID
     */
    private function updateLogProcessId(int $logId, int $processId): void
    {
        try {
            $log = TaskLogModel::find($logId);
            if ($log) {
                $log->process_id = $processId;
                $log->save();
            }
        } catch (\Throwable $e) {
            // 进程ID更新失败不影响任务执行
        }
    }
}
