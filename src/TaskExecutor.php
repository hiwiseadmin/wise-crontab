<?php

declare(strict_types=1);

namespace wise\crontab;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use wise\crontab\model\TaskLogModel;
use wise\support\runtime\Runtime;

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
     * 保证：
     *  - 超时按失败处理（写入日志、释放锁），且**不再重试**，避免出现 N × timeout 的长时间占用
     *  - 无论正常返回还是抛出异常，任务级并发锁都会被释放（finally）
     *
     * @param array    $task 任务配置
     * @param TaskLock $lock 锁管理器
     *
     * @return array{status:string, exit_code:int|null, output:string, error_output:string, duration:float, retry_count:int, timeout:bool}
     */
    public function execute(array $task, TaskLock $lock): array
    {
        $taskName = $task['name'] ?? 'unknown';
        $command = $task['command'] ?? '';
        $allowConcurrent = !empty($task['allow_concurrent']);
        $maxRetries = $this->resolveMaxRetries($task);
        $timeout = $this->resolveTimeout($task);

        if (!$allowConcurrent) {
            if (!$lock->acquire($taskName)) {
                $this->createLog($task, 'skipped', '', '', null);
                return $this->result('skipped', null, '', '', 0.0, 0);
            }
        }

        $logId = $this->createLog($task, 'running', '', '', null);
        $startTime = microtime(true);

        try {
            if (!$this->isCommandAllowed($command)) {
                $duration = round(microtime(true) - $startTime, 4);
                $errorOutput = '命令不在白名单中: ' . $command;
                $this->updateLog($logId, 'failure', '', $errorOutput, -1, $duration, 0);

                return $this->result('failure', -1, '', $errorOutput, $duration, 0);
            }

            $retryCount = 0;
            $processId = null;
            $output = '';
            $errorOutput = '';
            $exitCode = null;
            $timedOut = false;

            do {
                $process = null;
                try {
                    $process = Process::fromShellCommandline($command);
                    $process->setTimeout($timeout);

                    $process->start();
                    while ($process->isRunning()) {
                        $process->checkTimeout();
                        Runtime::sleep(0.1);
                    }

                    $exitCode = $process->getExitCode();
                    $output = $this->truncateOutput($process->getOutput());
                    $errorOutput = $this->truncateOutput($process->getErrorOutput());

                    if ($processId === null) {
                        $processId = $process->getPid();
                        if ($processId) {
                            $this->updateLogProcessId($logId, $processId);
                        }
                    }
                } catch (ProcessTimedOutException $e) {
                    $this->forceStop($process);
                    $exitCode = -1;
                    $output = $process !== null ? $this->truncateOutput($process->getOutput()) : '';
                    $errorOutput = sprintf('任务执行超时（超过 %.0f 秒）: %s', $timeout, $command);
                    $timedOut = true;
                } catch (\Symfony\Component\Process\Exception\InvalidArgumentException $e) {
                    $exitCode = -1;
                    $output = '';
                    $errorOutput = '进程启动失败: ' . $e->getMessage();
                }

                $duration = round(microtime(true) - $startTime, 4);

                if ($exitCode === 0) {
                    $this->updateLog($logId, 'success', $output, $errorOutput, $exitCode, $duration, $retryCount);

                    return $this->result('success', $exitCode, $output, $errorOutput, $duration, $retryCount);
                }

                if ($timedOut) {
                    break;
                }

                $retryCount++;
            } while ($retryCount <= $maxRetries);

            $actualRetries = $timedOut ? $retryCount : max(0, $retryCount - 1);
            $duration = round(microtime(true) - $startTime, 4);
            $exitCode = $exitCode ?? -1;

            $this->updateLog($logId, 'failure', $output, $errorOutput, $exitCode, $duration, $actualRetries);

            return $this->result('failure', $exitCode, $output, $errorOutput, $duration, $actualRetries, $timedOut);
        } finally {
            if (!$allowConcurrent) {
                $lock->release($taskName);
            }
        }
    }


    /**
     * 解析任务超时时间（秒）
     */
    public function resolveTimeout(array $task): float
    {
        return (float)($task['timeout'] ?? $this->config['default_timeout'] ?? 300);
    }

    /**
     * 解析任务最大重试次数
     */
    public function resolveMaxRetries(array $task): int
    {
        return (int)($task['max_retries'] ?? $this->config['default_max_retries'] ?? 0);
    }

    /**
     * 检查命令是否在允许列表中（防止命令注入）
     */
    public function isCommandAllowed(string $command): bool
    {
        $command = trim($command);
        if ($command === '') {
            return false;
        }

        if (self::containsShellInjection($command)) {
            return false;
        }

        foreach ($this->config['allowed_command_patterns'] ?? ['/^php\s+/'] as $pattern) {
            if (preg_match($pattern, $command)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 是否包含可用于命令注入的 shell 元字符
     *
     * 逐字符扫描并跟踪引号状态：
     *  - 引号外：`;` `&` `|` `` ` `` `$` `>` `<` 换行视为危险（命令分隔/管道/重定向/展开）
     *  - 双引号内：`$(...)` 与反引号仍会被 shell 展开，同样拦截
     *  - 单引号内：shell 不做任何展开，放行
     *
     * 反斜杠不拦：它是转义符而非分隔符（`\;` 是字面分号），且 Windows 绝对路径
     * （`php D:\www\think ...`）必须放行。
     *
     * 注意：白名单本身挡不住 `php -r '<任意代码>'`——「允许 php 命令」等价于
     * 「允许执行任意 PHP」。生产环境应把 allowed_command_patterns 收紧到
     * 具体入口（如 `/^php\s+think\s+[a-z0-9:_-]+$/`）。
     *
     * @param string $command 待执行命令
     */
    public static function containsShellInjection(string $command): bool
    {
        $inSingle = false;
        $inDouble = false;
        $length   = strlen($command);

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];

            if ($inSingle) {
                if ($char === "'") {
                    $inSingle = false;
                }
                continue;
            }

            if ($inDouble) {
                if ($char === '"') {
                    $inDouble = false;
                    continue;
                }
                if ($char === '\\') {
                    $i++;
                    continue;
                }
                if ($char === '`' || ($char === '$' && ($command[$i + 1] ?? '') === '(')) {
                    return true;
                }
                continue;
            }

            if ($char === "'") {
                $inSingle = true;
                continue;
            }
            if ($char === '"') {
                $inDouble = true;
                continue;
            }

            if (str_contains(";&|`$><\n\r", $char)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 截断输出（超过配置的最大字节数）
     */
    public function truncateOutput(string $output): string
    {
        $maxBytes = (int)($this->config['output_max_bytes'] ?? 65536);
        if (strlen($output) > $maxBytes) {
            return substr($output, 0, $maxBytes) . "\n\n[输出已截断，超出 {$maxBytes} 字节限制]";
        }
        return $output;
    }

    /**
     * 创建「运行中」日志（并行执行使用）
     */
    public function startRunLog(array $task): int
    {
        return $this->createLog($task, 'running', '', '', null);
    }

    /**
     * 收尾执行日志（并行执行使用）
     */
    public function finishRunLog(
        int $logId,
        string $status,
        string $output,
        string $errorOutput,
        int $exitCode,
        float $duration,
        int $retryCount = 0
    ): void {
        $this->updateLog($logId, $status, $output, $errorOutput, $exitCode, $duration, $retryCount);
    }

    /**
     * 构建统一的执行结果数组
     */
    private function result(
        string $status,
        ?int $exitCode,
        string $output,
        string $errorOutput,
        float $duration,
        int $retryCount,
        bool $timedOut = false
    ): array {
        return [
            'status'       => $status,
            'exit_code'    => $exitCode,
            'output'       => $output,
            'error_output' => $errorOutput,
            'duration'     => $duration,
            'retry_count'  => $retryCount,
            'timeout'      => $timedOut,
        ];
    }

    /**
     * 强制终止进程（用于超时场景）
     */
    private function forceStop(?Process $process): void
    {
        if ($process === null) {
            return;
        }

        try {
            $process->stop(0);
        } catch (\Throwable $e) {
        }
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
        }
    }
}
