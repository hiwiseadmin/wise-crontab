<?php

declare(strict_types=1);

namespace wise\crontab;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
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
use wise\support\runtime\Runtime;

class CrontabManager
{
    private TaskLock $lock;
    private TaskExecutor $executor;
    private array $config;
    private ?TaskGraph $graph = null;
    private ?AlertManager $alertManager = null;
    private ?SemaphoreLock $semaphore = null;

    /** @var array<string,string> 调度表达式非法任务：任务名 => 错误信息 */
    private array $scheduleErrors = [];

    /** 维护窗口检查失败是否已在本次调度中记录过（避免刷日志） */
    private bool $windowWarningLogged = false;

    public function __construct(array $config)
    {
        $this->config = $config;

        $lockDir = $config['lock_dir'] ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise-crontab-locks';
        $this->lock = new TaskLock($lockDir, (int)($config['lock_ttl'] ?? 3600), $config);
        $this->executor = new TaskExecutor($config);

        $maxGlobal = (int)($config['concurrency']['max_global'] ?? 0);
        if ($maxGlobal > 0) {
            $this->semaphore = new SemaphoreLock($this->lock->getLock(), $maxGlobal, $this->resolveSlotTtl());
        }

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
        $dispatchKey = 'dispatch:' . date('YmdHi');
        $dispatchTtl = $this->resolveDispatchLockTtl();

        if (!$this->lock->getLock()->acquire($dispatchKey, $dispatchTtl)) {
            return $this->emptyStats();
        }

        try {
            $this->resetPerRunState();

            $this->reapStaleRunningLogs();

            $tasks = $this->getTasks();

            $stats = $this->emptyStats();
            $stats['total'] = count($tasks);

            $dueTasks = $this->getDueTasks($tasks);

            if (empty($dueTasks)) {
                return $stats;
            }

            $dueTasks = $this->filterMaintenanceWindows($dueTasks, $stats);

            $dueTasks = $this->filterByDependencies($dueTasks, $stats);

            if (empty($dueTasks)) {
                return $stats;
            }

            $parallel = !empty($this->config['parallel']['enabled']);
            if ($parallel) {
                $this->dispatchParallel($dueTasks, $stats);
            } else {
                $this->dispatchSequential($dueTasks, $stats);
            }

            if (!empty($this->config['auto_clean_logs'])) {
                try {
                    $this->cleanExpiredLogs();
                } catch (\Throwable $e) {
                }
            }

            return $stats;
        } finally {
            $this->lock->getLock()->release($dispatchKey);
        }
    }

    /**
     * 清空「单轮调度」维度的实例状态
     *
     * 容器绑定是单例（Container::bind 无 newInstance 参数）。FPM 下每次 CLI/请求
     * 都是新容器，天然干净；Swoole / Workerman 常驻 worker 内同一实例会被反复复用，
     * 累积的 scheduleErrors 会把上一轮的错误带到下一轮，windowWarningLogged
     * 也会把后续真正的窗口故障永久静默。dispatch() 入口与包级重置器都会调用。
     */
    public function resetPerRunState(): void
    {
        $this->scheduleErrors = [];
        $this->windowWarningLogged = false;
    }

    /**
     * 空统计结构（供「本轮未执行」的分支复用）
     *
     * @return array{total:int,executed:int,success:int,failure:int,skipped:int,blocked_by_window:int,blocked_by_dependency:int,blocked_by_semaphore:int}
     */
    private function emptyStats(): array
    {
        return [
            'total'                 => 0,
            'executed'              => 0,
            'success'               => 0,
            'failure'               => 0,
            'skipped'               => 0,
            'blocked_by_window'     => 0,
            'blocked_by_dependency' => 0,
            'blocked_by_semaphore'  => 0,
        ];
    }

    /**
     * 全局调度锁 TTL：单轮最长等待 + 余量，避免锁比实际执行时间短
     */
    private function resolveDispatchLockTtl(): int
    {
        $configured = (int) ($this->config['dispatch_lock_ttl'] ?? 0);
        if ($configured > 0) {
            return $configured;
        }

        return max(120, (int) ($this->config['parallel']['wait_timeout'] ?? 600) + 60);
    }

    /**
     * 信号量槽位 TTL：与「单轮等待 + 任务超时」挂钩
     *
     * 旧实现硬编码 7200：调度进程被 kill 后槽位被占 2 小时，期间所有任务
     * 只能拿到 null 并被静默丢弃。
     */
    private function resolveSlotTtl(): int
    {
        $configured = (int) ($this->config['concurrency']['slot_ttl'] ?? 0);
        if ($configured > 0) {
            return $configured;
        }

        return max(
            300,
            (int) ($this->config['parallel']['wait_timeout'] ?? 600)
            + (int) ($this->config['default_timeout'] ?? 300)
            + 60
        );
    }

    /**
     * 回收超期仍在 running 的日志
     *
     * 进程被 kill / OOM / worker reload / 非等待模式中断时，日志会永久停在
     * running，后台看起来「一直在跑」。按 lock_ttl 判定超期并标记为 failure
     * （保持与统计/依赖判定使用的状态集一致，不引入新状态值）。
     */
    private function reapStaleRunningLogs(): void
    {
        $ttl = max(600, (int) ($this->config['lock_ttl'] ?? 3600));
        $cutoff = date('Y-m-d H:i:s', time() - $ttl);

        try {
            TaskLogModel::where('status', 'running')
                ->where('create_time', '<', $cutoff)
                ->update([
                    'status'       => 'failure',
                    'end_time'     => date('Y-m-d H:i:s'),
                    'error_output' => '执行进程已退出（超期 running 日志自动回收）',
                    'exit_code'    => -1,
                ]);
        } catch (\Throwable $e) {
        }
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
     *
     * 与串行执行保持同等能力：命令白名单、执行日志、超时终止、失败重试、事件与告警。
     *
     * 说明：`parallel.wait_all` 只影响历史语义，现在一律「有界等待」到 wait_timeout ——
     * 直接放弃等待会让父进程退出时带走 flock / 全局槽位，而子进程仍在运行，
     * 日志也会永久停留在 running（详见 dispatchParallel 末尾注释）。
     */
    private function dispatchParallel(array $dueTasks, array &$stats): void
    {
        $maxConcurrent = (int)($this->config['parallel']['max_concurrent'] ?? 5);
        $waitTimeout = (int)($this->config['parallel']['wait_timeout'] ?? 600);
        $startWait = null;

        $running = [];
        $pending = $dueTasks;

        while (!empty($pending) || !empty($running)) {
            while (count($running) < $maxConcurrent && !empty($pending)) {
                $task = array_shift($pending);
                $command = $task['command'] ?? '';
                $allowConcurrent = !empty($task['allow_concurrent']);
                $timeout = $this->executor->resolveTimeout($task);
                $maxRetries = $this->executor->resolveMaxRetries($task);

                $slot = null;
                if ($this->semaphore) {
                    $slot = $this->semaphore->acquireSlot();
                    if ($slot === null) {
                        $stats['blocked_by_semaphore']++;
                        continue;
                    }
                }

                if (!$allowConcurrent && !$this->lock->acquire($task['name'])) {
                    $stats['skipped']++;
                    if ($this->semaphore && $slot !== null) {
                        $this->semaphore->releaseSlot('global', $slot);
                    }
                    continue;
                }

                $logId = $this->executor->startRunLog($task);

                if (!$this->executor->isCommandAllowed($command)) {
                    $errorOutput = '命令不在白名单中: ' . $command;
                    $this->executor->finishRunLog($logId, 'failure', '', $errorOutput, -1, 0.0);

                    $stats['failure']++;
                    $this->emitFailure(
                        new \wise\crontab\event\TaskExecuteFailed(
                            $task['name'], $command, -1, 0.0, $errorOutput, 0
                        ),
                        false
                    );

                    if (!$allowConcurrent) {
                        $this->lock->release($task['name']);
                    }
                    if ($this->semaphore && $slot !== null) {
                        $this->semaphore->releaseSlot('global', $slot);
                    }
                    continue;
                }

                try {
                    Event::trigger(new BeforeTaskExecute(
                        $task['name'],
                        $command,
                        $task['cron_expression'] ?? ''
                    ));
                } catch (\Throwable $e) {
                }

                try {
                    $process = Process::fromShellCommandline($command);
                    $process->setTimeout($timeout);
                    $process->start();
                } catch (\Throwable $e) {
                    $errorOutput = '进程启动失败: ' . $e->getMessage();
                    $this->executor->finishRunLog($logId, 'failure', '', $errorOutput, -1, 0.0);

                    $stats['failure']++;
                    $this->emitFailure(
                        new \wise\crontab\event\TaskExecuteFailed(
                            $task['name'], $command, -1, 0.0, $errorOutput, 0
                        ),
                        false
                    );

                    if (!$allowConcurrent) {
                        $this->lock->release($task['name']);
                    }
                    if ($this->semaphore && $slot !== null) {
                        $this->semaphore->releaseSlot('global', $slot);
                    }
                    continue;
                }

                $running[] = [
                    'process'    => $process,
                    'task'       => $task,
                    'log_id'     => $logId,
                    'slot'       => $slot,
                    'started_at' => microtime(true),
                    'retry'      => 0,
                    'max_retries' => $maxRetries,
                    'timed_out'  => false,
                ];
                $stats['executed']++;
            }

            if (!empty($running)) {
                Runtime::sleep(0.1);

                foreach ($running as $i => $item) {
                    $process = $item['process'];
                    $task = $item['task'];
                    $command = $task['command'] ?? '';

                    if (!$item['timed_out']) {
                        try {
                            $process->checkTimeout();
                        } catch (ProcessTimedOutException $e) {
                            try {
                                $process->stop(0);
                            } catch (\Throwable $ignore) {
                            }
                            $running[$i]['timed_out'] = true;
                            $item['timed_out'] = true;
                        }
                    }

                    if (!$item['timed_out'] && $process->isRunning()) {
                        continue;
                    }

                    $duration = round(microtime(true) - $item['started_at'], 4);
                    $exitCode = $process->getExitCode();
                    $output = $this->executor->truncateOutput($process->getOutput());
                    $errorOutput = $this->executor->truncateOutput($process->getErrorOutput());

                    if ($item['timed_out']) {
                        $exitCode = -1;
                        $errorOutput = sprintf(
                            '任务执行超时（超过 %.0f 秒）: %s',
                            $this->executor->resolveTimeout($task),
                            $command
                        );
                    }

                    if ($exitCode !== 0 && !$item['timed_out'] && $item['retry'] < $item['max_retries']) {
                        try {
                            $retryProcess = Process::fromShellCommandline($command);
                            $retryProcess->setTimeout($this->executor->resolveTimeout($task));
                            $retryProcess->start();

                            $running[$i]['process']    = $retryProcess;
                            $running[$i]['retry']      = $item['retry'] + 1;
                            $running[$i]['started_at'] = microtime(true);
                            continue;
                        } catch (\Throwable $e) {
                        }
                    }

                    if ($exitCode === 0) {
                        $stats['success']++;
                        $this->executor->finishRunLog(
                            $item['log_id'], 'success', $output, $errorOutput, 0, $duration, $item['retry']
                        );
                        try {
                            Event::trigger(new AfterTaskExecute(
                                $task['name'], $command,
                                $exitCode, $duration, $output
                            ));
                        } catch (\Throwable $e) {
                        }
                    } else {
                        $stats['failure']++;
                        $this->executor->finishRunLog(
                            $item['log_id'], 'failure', $output, $errorOutput, (int)$exitCode, $duration, $item['retry']
                        );
                        $this->emitFailure(
                            new \wise\crontab\event\TaskExecuteFailed(
                                $task['name'], $command,
                                (int)$exitCode, $duration, $errorOutput, $item['retry']
                            ),
                            $item['timed_out']
                        );
                    }

                    if (empty($task['allow_concurrent'])) {
                        $this->lock->release($task['name']);
                    }

                    if ($this->semaphore && ($item['slot'] ?? null) !== null) {
                        $this->semaphore->releaseSlot('global', (int) $item['slot']);
                    }

                    $this->updateNextRunTime($task);

                    unset($running[$i]);
                }

                $running = array_values($running);
            }

            if (empty($pending) && !empty($running)) {
                $startWait ??= time();
                if (time() - $startWait > $waitTimeout) {
                    $this->abandonRunning($running, $stats, $waitTimeout);
                    break;
                }
            }
        }

        if ($stats['blocked_by_semaphore'] > 0) {
            $this->warn(sprintf(
                '全局并发已满（concurrency.max_global=%d），本轮跳过 %d 个任务',
                (int) ($this->config['concurrency']['max_global'] ?? 0),
                $stats['blocked_by_semaphore']
            ));
        }

        if ($this->semaphore) {
            $this->semaphore->releaseAllSlots('global');
        }
    }

    /**
     * 放弃仍在运行的并行任务（等待超时 / 调度退出时）
     *
     * 终止子进程、按失败收尾日志并释放任务级锁，避免日志长期停留在 running、锁残留到 TTL 到期。
     *
     * @param array $running 仍在运行的任务项
     */
    private function abandonRunning(array $running, array &$stats, int $waitTimeout): void
    {
        foreach ($running as $item) {
            $duration = round(microtime(true) - (float)$item['started_at'], 4);

            try {
                $item['process']->stop(0);
            } catch (\Throwable $e) {
            }

            $this->executor->finishRunLog(
                (int)$item['log_id'],
                'failure',
                '',
                sprintf('等待并行任务超时（wait_timeout=%d 秒），任务已终止', $waitTimeout),
                -1,
                $duration,
                (int)$item['retry']
            );

            $stats['failure']++;

            if (empty($item['task']['allow_concurrent'])) {
                $this->lock->release($item['task']['name']);
            }

            if ($this->semaphore && ($item['slot'] ?? null) !== null) {
                $this->semaphore->releaseSlot('global', (int) $item['slot']);
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

        try {
            Event::trigger(new BeforeTaskExecute(
                $task['name'],
                $task['command'],
                $cronExpression
            ));
        } catch (\Throwable $e) {
        }

        $stats['executed']++;

        try {
            $result = $this->executor->execute($task, $this->lock);
        } catch (\Throwable $e) {
            $taskName = (string)($task['name'] ?? 'unknown');
            $this->warn("任务「{$taskName}」执行异常: " . $e->getMessage());

            $result = [
                'status'       => 'failure',
                'exit_code'    => -1,
                'output'       => '',
                'error_output' => '任务执行异常: ' . $e->getMessage(),
                'duration'     => 0.0,
                'retry_count'  => 0,
                'timeout'      => false,
            ];
        }

        if ($result['status'] === 'success') {
            $stats['success']++;

            if (($task['schedule_type'] ?? '') === 'once') {
                $this->markOneTimeTaskExecuted($task);

                $task['executed_at'] = date('Y-m-d H:i:s');
                $task['status']      = 0;
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
            $this->emitFailure(
                new \wise\crontab\event\TaskExecuteFailed(
                    $task['name'],
                    $task['command'],
                    $result['exit_code'],
                    $result['duration'],
                    $result['error_output'],
                    $result['retry_count']
                ),
                !empty($result['timeout'])
            );
        } else {
            $stats['skipped']++;
        }

        $this->updateNextRunTime($task);
    }

    /**
     * 回写任务的下次执行时间到 crontab_task.next_run_at
     */
    private function updateNextRunTime(array $task): void
    {
        $taskId = (int)($task['id'] ?? 0);
        if ($taskId <= 0) {
            return;
        }

        try {
            $scheduleType = $task['schedule_type'] ?? 'cron';

            if ($scheduleType === 'once') {
                $executed = !empty($task['executed_at']) || (int) ($task['status'] ?? 1) === 0;
                $runAt    = trim((string) ($task['run_at'] ?? ''));

                $nextRun = ($executed || $runAt === '') ? null : $runAt;
            } elseif (!empty($task['cron_expression'])) {
                $nextRun = (new CronExpression($task['cron_expression']))->getNextRunDate()->format('Y-m-d H:i:s');
            } else {
                return;
            }

            TaskModel::where('id', $taskId)->update(['next_run_at' => $nextRun]);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 触发失败事件并按场景路由告警（超时走 on_timeout，普通失败走 on_failure）
     */
    private function emitFailure(\wise\crontab\event\TaskExecuteFailed $event, bool $timeout): void
    {
        try {
            Event::trigger($event);
        } catch (\Throwable $e) {
        }

        if ($this->alertManager === null) {
            return;
        }

        if ($timeout) {
            $this->alertManager->handleTimeout($event);
            return;
        }

        $this->alertManager->handleFailure($event);
    }

    /**
     * 获取所有启用的任务（DB 配置优先覆盖文件配置）
     *
     * 调度表达式在这里统一归一化：`schedule`（语义化，优先）或 `cron_expression`（原生）都会被折算为
     * 可用的 `cron_expression` / `run_at`，因此配置文件与数据库两种来源行为一致。
     */
    public function getTasks(): array
    {
        $tasks = [];

        $configRepo = new ConfigTaskRepository($this->config['tasks'] ?? []);
        foreach ($configRepo->all() as $task) {
            $tasks[$task['name']] = $this->normalizeTask($task);
        }

        $dbRepo = new DatabaseTaskRepository();
        if ($dbRepo->isAvailable()) {
            foreach ($dbRepo->all() as $task) {
                $tasks[$task['name']] = $this->normalizeTask($task);
            }
        }

        return array_values($tasks);
    }

    /**
     * 获取调度表达式非法的任务列表（任务名 => 错误信息）
     *
     * @return array<string,string>
     */
    public function getScheduleErrors(): array
    {
        return $this->scheduleErrors;
    }

    /**
     * 归一化单个任务的调度表达式
     *
     * 支持语义化（`每5分钟` / `daily at 02:30` / `每月最后一个周五 09:00`）与原生 cron；
     * 解析失败时记录错误并清空调度字段，避免任务被误判为到期。
     */
    private function normalizeTask(array $task): array
    {
        try {
            return Schedule::normalizeTask($task);
        } catch (\Throwable $e) {
            $name  = (string) ($task['name'] ?? 'unknown');
            $input = trim((string) ($task['schedule'] ?? '')) !== ''
                ? (string) $task['schedule']
                : (string) ($task['cron_expression'] ?? '');

            $this->scheduleErrors[$name] = $e->getMessage();
            $this->warn("任务「{$name}」调度表达式非法（{$input}）: " . $e->getMessage());

            $task['cron_expression'] = '';
            $task['schedule_error']  = $e->getMessage();

            return $task;
        }
    }

    /**
     * 获取到期任务列表
     *
     * @param array|null $tasks 已取好的任务列表（避免 dispatch 内重复查库）
     */
    private function getDueTasks(?array $tasks = null): array
    {
        $tasks = $tasks ?? $this->getTasks();
        $dueTasks = [];
        $now = time();

        $pendingOnceCheck = [];

        foreach ($tasks as $task) {
            $scheduleType = $task['schedule_type'] ?? 'cron';

            if ($scheduleType === 'once') {
                $runAt = $task['run_at'] ?? null;
                $executedAt = $task['executed_at'] ?? null;

                if ($runAt && empty($executedAt)) {
                    $runTimestamp = strtotime($runAt);
                    if ($runTimestamp !== false && $runTimestamp <= $now) {
                        if (($task['id'] ?? 0) <= 0) {
                            $pendingOnceCheck[(string) ($task['name'] ?? '')] = date('Y-m-d H:i:s', $runTimestamp);
                        }

                        $dueTasks[] = $task;
                    }
                }
                continue;
            }

            $cronExpression = $task['cron_expression'] ?? '';
            if (empty($cronExpression)) {
                continue;
            }

            try {
                $cron = new CronExpression($cronExpression);
                if ($cron->isDue()) {
                    $dueTasks[] = $task;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        if (!empty($pendingOnceCheck)) {
            $succeeded = $this->succeededTasksSince($pendingOnceCheck);

            $dueTasks = array_values(array_filter(
                $dueTasks,
                static function (array $task) use ($succeeded): bool {
                    $name = (string) ($task['name'] ?? '');

                    if (($task['schedule_type'] ?? '') !== 'once' || (int) ($task['id'] ?? 0) > 0) {
                        return true;
                    }

                    return !isset($succeeded[$name]);
                }
            ));
        }

        return $dueTasks;
    }

    /**
     * 批量判断「配置源一次性任务」在各自 run_at 之后是否已有成功日志
     *
     * @param array<string,string> $sinceByName 任务名 => run_at（Y-m-d H:i:s）
     *
     * @return array<string,bool> 已成功执行过的任务名集合
     */
    private function succeededTasksSince(array $sinceByName): array
    {
        $names = array_keys(array_filter($sinceByName, static fn ($since): bool => (string) $since !== ''));
        if ($names === []) {
            return [];
        }

        $minSince = min(array_map('strval', array_intersect_key($sinceByName, array_flip($names))));

        try {
            $rows = TaskLogModel::whereIn('task_name', $names)
                ->where('status', 'success')
                ->where('create_time', '>=', $minSince)
                ->fieldRaw('task_name, MAX(create_time) AS last_success')
                ->group('task_name')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $name = (string) ($row['task_name'] ?? '');
            $last = (string) ($row['last_success'] ?? '');
            if ($name === '' || $last === '') {
                continue;
            }

            if ($last >= (string) ($sinceByName[$name] ?? '')) {
                $result[$name] = true;
            }
        }

        return $result;
    }

    /**
     * E-5: 过滤维护窗口内的任务
     */
    private function filterMaintenanceWindows(array $dueTasks, array &$stats): array
    {
        if (empty($dueTasks)) {
            return [];
        }

        try {
            $windows = MaintenanceWindowModel::select()->toArray();
        } catch (\Throwable $e) {
            if (!$this->windowWarningLogged) {
                $this->windowWarningLogged = true;
                $this->warn('维护窗口不可用，已跳过窗口检查: ' . $e->getMessage());
            }

            return $dueTasks;
        }

        $now = new \DateTime('now');

        $filtered = [];
        foreach ($dueTasks as $task) {
            $taskId = (int) ($task['id'] ?? 0);
            $window = MaintenanceWindowModel::matchWindows($windows, $taskId > 0 ? $taskId : null, $now);

            if ($window !== null) {
                $stats['blocked_by_window']++;

                $this->recordWindowSkipLog($task, $taskId, $window);

                continue;
            }

            $filtered[] = $task;
        }

        return $filtered;
    }

    /**
     * 记录「被维护窗口阻塞」的跳过日志（按任务+窗口+小时去重）
     *
     * @param array $task   任务定义
     * @param int   $taskId 任务ID（配置源任务为 0）
     * @param array $window 命中的窗口
     */
    private function recordWindowSkipLog(array $task, int $taskId, array $window): void
    {
        $taskName  = (string) ($task['name'] ?? 'unknown');
        $dedupeKey = sprintf('crontab:window_skip:%s:%d:%s', $taskName, (int) ($window['id'] ?? 0), date('YmdH'));

        if ($this->cacheGet($dedupeKey) !== null) {
            return;
        }

        try {
            $log = new TaskLogModel();
            $log->task_id = $taskId;
            $log->task_name = $taskName;
            $log->status = 'skipped';
            $log->output = "维护窗口: {$window['reason']} ({$window['start_at']}-{$window['end_at']})";
            $log->start_time = date('Y-m-d H:i:s');
            $log->save();

            $this->cacheSet($dedupeKey, 1, 3600);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 读缓存（缓存不可用时返回 null，退化为每次记录）
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
     * 写缓存（缓存不可用时静默，最多退化为「不去重」）
     */
    private function cacheSet(string $key, mixed $value, int $ttl): void
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
     * 统一告警输出（优先内核日志，缺失时退 error_log）
     *
     * 直接 error_log() 在 Swoole 下只会进 master 的 stderr（不进 runtime/log/），
     * 在 FPM 下进 php_error.log，与框架日志体系脱节，排障时找不到。
     */
    private function warn(string $message): void
    {
        try {
            if (class_exists(\wise\support\context\ContextualLogger::class)) {
                \wise\support\context\ContextualLogger::warning('[wise-crontab] ' . $message, ['tag' => 'crontab']);
                return;
            }
        } catch (\Throwable) {
        }

        error_log('[wise-crontab] ' . $message);
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
     *
     * 仅对数据库任务生效（写入 status=0 + executed_at）；
     * 配置源任务没有可回写的位置，改由 getDueTasks() 依据执行日志幂等跳过。
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
        }
    }

    /**
     * 获取未来即将执行的任务列表
     */
    public function getUpcomingTasks(int $limit = 10): array
    {
        $tasks = $this->getTasks();
        $upcoming = [];

        $pendingOnceCheck = [];
        foreach ($tasks as $task) {
            $runAt = trim((string) ($task['run_at'] ?? ''));
            if (($task['schedule_type'] ?? 'cron') === 'once'
                && (int) ($task['id'] ?? 0) <= 0
                && $runAt !== ''
                && empty($task['executed_at'])) {
                $pendingOnceCheck[(string) ($task['name'] ?? '')] = $runAt;
            }
        }
        $succeededOnce = $this->succeededTasksSince($pendingOnceCheck);

        foreach ($tasks as $task) {
            $scheduleType = $task['schedule_type'] ?? 'cron';

            if ($scheduleType === 'once') {
                $runAt = $task['run_at'] ?? null;
                $executedAt = $task['executed_at'] ?? null;
                $runTimestamp = $runAt ? strtotime($runAt) : false;

                if ($runAt && empty($executedAt) && $runTimestamp !== false && $runTimestamp > time()) {
                    if (isset($succeededOnce[(string) ($task['name'] ?? '')])) {
                        continue;
                    }

                    $upcoming[] = [
                        'task'      => $task,
                        'next_run'  => $runAt,
                        'timestamp' => $runTimestamp,
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
            } catch (\Throwable $e) {
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
     *
     * @return int 实际删除的条数
     *
     * 注意返回类型：`delete()` 返回受影响行数（int）。旧声明为 `: bool`，
     * 在 strict_types 下会抛 TypeError —— `Run --clean` 里被 try/catch 吞掉，
     * 而 `wise:crontab:clean` 命令则直接崩溃。
     */
    public function cleanExpiredLogs(): int
    {
        $retentionDays = (int)($this->config['log_retention_days'] ?? 30);
        $cutoffTime = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

        return (int) TaskLogModel::where('create_time', '<', $cutoffTime)->delete();
    }
}
