<?php

declare(strict_types=1);

namespace wise\crontab;

use Symfony\Component\Process\Process;
use think\facade\Event;
use wise\crontab\alert\AlertManager;
use wise\crontab\driver\ConfigTaskRepository;
use wise\crontab\driver\DatabaseTaskRepository;
use wise\crontab\event\AfterTaskExecute;
use wise\crontab\event\BeforeTaskExecute;
use wise\crontab\event\TaskExecuteFailed;
use wise\crontab\model\MaintenanceWindowModel;
use wise\crontab\model\TaskDependencyModel;
use wise\crontab\model\TaskLogModel;
use wise\crontab\model\TaskModel;

class CrontabManager
{
    private TaskLock $lock;
    private TaskExecutor $executor;
    private array $config;
    private ?TaskGraph $graph = null;
    private ?AlertManager $alertManager = null;
    private ?SemaphoreLock $semaphore = null;

    public function __construct(array $config)
    {
        $this->config = $config;

        $lockDir = $config['lock_dir'] ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise-crontab-locks';
        $this->lock = new TaskLock($lockDir, (int)($config['lock_ttl'] ?? 3600), $config);
        $this->executor = new TaskExecutor($config);

        // 初始化全局信号量（如果配置了最大并发数）
        $maxGlobal = (int)($config['concurrency']['max_global'] ?? 0);
        if ($maxGlobal > 0) {
            $this->semaphore = new SemaphoreLock($this->lock->getDriver(), $maxGlobal);
        }

        // 初始化告警管理器
        if (!empty($config['alert']['enabled'])) {
            $this->alertManager = new AlertManager($config['alert']);
        }
    }

    /**
     * 动态设置配置（供 Clean 命令等使用）
     */
    public function setConfig(string $key, $value): void
    {
        $this->config[$key] = $value;
    }

    /**
     * 获取当前配置
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * 扫描并调度执行所有到期的定时任务
     *
     * @return array{total: int, executed: int, success: int, failure: int, skipped: int, blocked_by_window: int, blocked_by_dependency: int}
     */
    public function dispatch(): array
    {
        $dueTasks = $this->getDueTasks();

        $stats = [
            'total'                => count($this->getTasks()),
            'executed'             => 0,
            'success'              => 0,
            'failure'              => 0,
            'skipped'              => 0,
            'blocked_by_window'    => 0,
            'blocked_by_dependency' => 0,
            'blocked_by_semaphore' => 0,
        ];

        if (empty($dueTasks)) {
            return $stats;
        }

        // E-5: 过滤维护窗口内的任务
        $dueTasks = $this->filterMaintenanceWindows($dueTasks, $stats);

        // E-1: 过滤依赖未满足的任务
        $dueTasks = $this->filterByDependencies($dueTasks, $stats);

        if (empty($dueTasks)) {
            return $stats;
        }

        // O-3: 并行或串行执行
        $parallel = !empty($this->config['parallel']['enabled']);
        if ($parallel) {
            $this->dispatchParallel($dueTasks, $stats);
        } else {
            $this->dispatchSequential($dueTasks, $stats);
        }

        // O-5: 自动清理过期日志（如果配置）
        if (!empty($this->config['auto_clean_logs'])) {
            try {
                $this->cleanExpiredLogs();
            } catch (\Throwable $e) {
                // 清理失败不影响调度
            }
        }

        return $stats;
    }

    /**
     * 串行执行到期任务
     */
    private function dispatchSequential(array $dueTasks, array &$stats): void
    {
        foreach ($dueTasks as $task) {
            $this->executeTask($task, $stats);
        }
    }

    /**
     * 并行执行到期任务（基于 Symfony Process 后台非阻塞启动）
     */
    private function dispatchParallel(array $dueTasks, array &$stats): void
    {
        $maxConcurrent = (int)($this->config['parallel']['max_concurrent'] ?? 5);
        $waitAll = !empty($this->config['parallel']['wait_all']);
        $waitTimeout = (int)($this->config['parallel']['wait_timeout'] ?? 600);

        /** @var array<int, array{process: Process, task: array}> */
        $running = [];
        $pending = $dueTasks;

        while (!empty($pending) || !empty($running)) {
            // 启动新任务（在未达并发上限且有待处理任务时）
            while (count($running) < $maxConcurrent && !empty($pending)) {
                $task = array_shift($pending);

                // E-6: 全局信号量检查
                if ($this->semaphore && $this->semaphore->acquireSlot() === null) {
                    $stats['blocked_by_semaphore']++;
                    continue;
                }

                // 检查任务级并发锁
                $allowConcurrent = !empty($task['allow_concurrent']);
                if (!$allowConcurrent && !$this->lock->acquire($task['name'])) {
                    $stats['skipped']++;
                    continue;
                }

                // 触发 before 事件
                try {
                    Event::trigger(new BeforeTaskExecute(
                        $task['name'],
                        $task['command'],
                        $task['cron_expression'] ?? ''
                    ));
                } catch (\Throwable $e) {
                }

                $command = $task['command'] ?? '';
                $timeout = (float)($task['timeout'] ?? $this->config['default_timeout'] ?? 300);
                $process = Process::fromShellCommandline($command);
                $process->setTimeout($timeout);
                $process->start();

                $running[] = ['process' => $process, 'task' => $task];
                $stats['executed']++;
            }

            // 等待任意进程完成
            if (!empty($running)) {
                usleep(100000); // 100ms 轮询

                foreach ($running as $i => $item) {
                    $process = $item['process'];
                    $task = $item['task'];

                    if ($process->isRunning()) {
                        continue;
                    }

                    // 进程已完成
                    $exitCode = $process->getExitCode();
                    $output = $process->getOutput();
                    $errorOutput = $process->getErrorOutput();

                    if ($exitCode === 0) {
                        $stats['success']++;
                        try {
                            Event::trigger(new AfterTaskExecute(
                                $task['name'], $task['command'],
                                $exitCode, 0, $output
                            ));
                        } catch (\Throwable $e) {
                        }
                    } else {
                        $stats['failure']++;
                        try {
                            Event::trigger(new TaskExecuteFailed(
                                $task['name'], $task['command'],
                                $exitCode, 0, $errorOutput, 0
                            ));
                        } catch (\Throwable $e) {
                        }

                        // E-2: 告警
                        if ($this->alertManager) {
                            $alertEvent = new \wise\crontab\event\TaskExecuteFailed(
                                $task['name'], $task['command'],
                                $exitCode, 0, $errorOutput, 0
                            );
                            $this->alertManager->handleFailure($alertEvent);
                        }
                    }

                    // 释放锁
                    if (!empty($task['allow_concurrent'])) {
                        $this->lock->release($task['name']);
                    }

                    unset($running[$i]);
                }

                // 重新索引
                $running = array_values($running);
            }

            // 超时保护
            if ($waitAll && empty($pending) && !empty($running)) {
                static $startWait = null;
                if ($startWait === null) {
                    $startWait = time();
                }
                if (time() - $startWait > $waitTimeout) {
                    break;
                }
            }

            // 不等待模式：批量启动完即退出
            if (!$waitAll && empty($pending)) {
                break;
            }
        }

        // 释放全局槽位
        if ($this->semaphore) {
            for ($i = 0; $i < min($stats['executed'], 10); $i++) {
                $this->semaphore->releaseSlot('global', $i);
            }
        }
    }

    /**
     * 执行单个任务
     */
    private function executeTask(array $task, array &$stats): void
    {
        $cronExpression = $task['cron_expression'] ?? '';
        if (empty($cronExpression)) {
            return;
        }

        // 触发执行前事件
        try {
            Event::trigger(new BeforeTaskExecute(
                $task['name'],
                $task['command'],
                $cronExpression
            ));
        } catch (\Throwable $e) {
        }

        $stats['executed']++;

        // 执行任务
        $result = $this->executor->execute($task, $this->lock);

        if ($result['status'] === 'success') {
            $stats['success']++;

            // E-4: 一次性任务执行成功后自动禁用
            if (($task['schedule_type'] ?? '') === 'once') {
                $this->markOneTimeTaskExecuted($task);
            }

            try {
                Event::trigger(new AfterTaskExecute(
                    $task['name'],
                    $task['command'],
                    $result['exit_code'],
                    $result['duration'],
                    $result['output']
                ));
            } catch (\Throwable $e) {
            }
        } elseif ($result['status'] === 'failure') {
            $stats['failure']++;
            try {
                Event::trigger(new TaskExecuteFailed(
                    $task['name'],
                    $task['command'],
                    $result['exit_code'],
                    $result['duration'],
                    $result['error_output'],
                    $result['retry_count']
                ));
            } catch (\Throwable $e) {
            }

            // E-2: 告警
            if ($this->alertManager) {
                $alertEvent = new \wise\crontab\event\TaskExecuteFailed(
                    $task['name'],
                    $task['command'],
                    $result['exit_code'],
                    $result['duration'],
                    $result['error_output'],
                    $result['retry_count']
                );
                $this->alertManager->handleFailure($alertEvent);
            }
        } else {
            $stats['skipped']++;
        }
    }

    /**
     * 获取所有启用的任务（DB 配置优先覆盖文件配置）
     */
    public function getTasks(): array
    {
        $tasks = [];

        // 配置文件中的任务
        $configRepo = new ConfigTaskRepository($this->config['tasks'] ?? []);
        foreach ($configRepo->all() as $task) {
            $tasks[$task['name']] = $task;
        }

        // 数据库中的任务（优先级更高，覆盖同名配置任务）
        $dbRepo = new DatabaseTaskRepository();
        if ($dbRepo->isAvailable()) {
            foreach ($dbRepo->all() as $task) {
                $tasks[$task['name']] = $task;
            }
        }

        return array_values($tasks);
    }

    /**
     * 获取到期任务列表
     */
    private function getDueTasks(): array
    {
        $tasks = $this->getTasks();
        $dueTasks = [];
        $now = time();

        foreach ($tasks as $task) {
            $scheduleType = $task['schedule_type'] ?? 'cron';

            if ($scheduleType === 'once') {
                // E-4: 一次性任务
                $runAt = $task['run_at'] ?? null;
                $executedAt = $task['executed_at'] ?? null;

                if ($runAt && strtotime($runAt) <= $now && empty($executedAt)) {
                    $dueTasks[] = $task;
                }
                continue;
            }

            // 标准 Cron 任务
            $cronExpression = $task['cron_expression'] ?? '';
            if (empty($cronExpression)) {
                continue;
            }

            try {
                $cron = new CronExpression($cronExpression);
                if ($cron->isDue()) {
                    $dueTasks[] = $task;
                }
            } catch (\InvalidArgumentException $e) {
                continue;
            }
        }

        return $dueTasks;
    }

    /**
     * E-5: 过滤维护窗口内的任务
     */
    private function filterMaintenanceWindows(array $dueTasks, array &$stats): array
    {
        if (empty($dueTasks)) {
            return [];
        }

        $filtered = [];
        foreach ($dueTasks as $task) {
            $taskId = $task['id'] ?? 0;
            $window = MaintenanceWindowModel::isInWindow($taskId > 0 ? (int)$taskId : null);

            if ($window !== null) {
                $stats['blocked_by_window']++;
                // 记录跳过日志
                try {
                    $log = new TaskLogModel();
                    $log->task_id = $taskId;
                    $log->task_name = $task['name'] ?? 'unknown';
                    $log->status = 'skipped';
                    $log->output = "维护窗口: {$window['reason']} ({$window['start_at']}-{$window['end_at']})";
                    $log->start_time = date('Y-m-d H:i:s');
                    $log->save();
                } catch (\Throwable $e) {
                }
                continue;
            }

            $filtered[] = $task;
        }

        return $filtered;
    }

    /**
     * E-1: 过滤依赖未满足的任务
     */
    private function filterByDependencies(array $dueTasks, array &$stats): array
    {
        if (empty($dueTasks)) {
            return [];
        }

        if ($this->graph === null) {
            $this->graph = new TaskGraph();
        }

        $before = count($dueTasks);
        $runnable = $this->graph->getRunnableTasks($dueTasks);
        $stats['blocked_by_dependency'] = $before - count($runnable);

        return $runnable;
    }

    /**
     * E-4: 标记一次性任务为已执行
     */
    private function markOneTimeTaskExecuted(array $task): void
    {
        $taskId = $task['id'] ?? 0;
        if ($taskId <= 0) {
            return;
        }

        try {
            TaskModel::where('id', $taskId)->update([
                'status'      => 0,
                'executed_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 静默处理
        }
    }

    /**
     * 获取未来即将执行的任务列表
     */
    public function getUpcomingTasks(int $limit = 10): array
    {
        $tasks = $this->getTasks();
        $upcoming = [];

        foreach ($tasks as $task) {
            $scheduleType = $task['schedule_type'] ?? 'cron';

            if ($scheduleType === 'once') {
                $runAt = $task['run_at'] ?? null;
                $executedAt = $task['executed_at'] ?? null;
                if ($runAt && empty($executedAt) && strtotime($runAt) > time()) {
                    $upcoming[] = [
                        'task'      => $task,
                        'next_run'  => $runAt,
                        'timestamp' => strtotime($runAt),
                    ];
                }
                continue;
            }

            $cronExpression = $task['cron_expression'] ?? '';
            if (empty($cronExpression)) {
                continue;
            }

            try {
                $cron = new CronExpression($cronExpression);
                $nextRun = $cron->getNextRunDate();
                $upcoming[] = [
                    'task'      => $task,
                    'next_run'  => $nextRun->format('Y-m-d H:i:s'),
                    'timestamp' => $nextRun->getTimestamp(),
                ];
            } catch (\InvalidArgumentException $e) {
                continue;
            }
        }

        usort($upcoming, function (array $a, array $b): int {
            return $a['timestamp'] <=> $b['timestamp'];
        });

        return array_slice($upcoming, 0, $limit);
    }

    /**
     * 清理过期的执行日志
     */
    public function cleanExpiredLogs(): int
    {
        $retentionDays = (int)($this->config['log_retention_days'] ?? 30);
        $cutoffTime = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

        return TaskLogModel::where('create_time', '<', $cutoffTime)->delete();
    }
}
