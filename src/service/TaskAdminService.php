<?php

declare(strict_types=1);

namespace wise\crontab\service;

use wise\crontab\CronExpression;
use wise\crontab\CrontabManager;
use wise\crontab\exception\CrontabException;
use wise\crontab\model\MaintenanceWindowModel;
use wise\crontab\model\TaskDependencyModel;
use wise\crontab\model\TaskLogModel;
use wise\crontab\model\TaskModel;
use wise\crontab\Schedule;
use wise\crontab\TaskExecutor;
use wise\crontab\TaskLock;

/**
 * 定时任务管理面服务
 *
 * 补齐此前缺失的写入能力：任务增删改查、启停、立即执行，
 * 以及任务依赖与维护窗口的维护接口（此前三张表只能靠直接写库维护）。
 *
 * 校验规则与调度器保持一致：命令白名单、语义化/原生调度表达式均在此归一化。
 */
class TaskAdminService
{
    public function __construct(private array $config = [])
    {
    }


    /**
     * 任务列表（含归一化后的 cron、中文描述与下次执行时间）
     *
     * @param array $filter status / keyword / limit
     *
     * @return array<int,array>
     */
    public function list(array $filter = []): array
    {
        $query = TaskModel::order('id', 'asc');

        if (($filter['status'] ?? null) !== null && $filter['status'] !== '') {
            $query->where('status', (int) $filter['status']);
        }

        $keyword = trim((string) ($filter['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where('name', 'like', $like)->whereOr('title', 'like', $like);
        }

        $limit = (int) ($filter['limit'] ?? 0);
        if ($limit > 0) {
            $query->limit($limit);
        }

        $result = [];
        foreach ($query->select()->toArray() as $row) {
            $result[] = $this->decorate($row);
        }

        return $result;
    }

    /**
     * 查询单个任务
     */
    public function find(string $name): ?array
    {
        $task = TaskModel::where('name', $name)->find();

        return $task === null ? null : $this->decorate($task->toArray());
    }

    /**
     * 按主键查询任务（后台编辑用）
     */
    public function findById(int $id): ?array
    {
        $task = TaskModel::find($id);

        return $task === null ? null : $this->decorate($task->toArray());
    }

    /**
     * 后台列表：配置任务 + 数据库任务合并后分页＼�
     *
     * 合并口径与调度器一致（`CrontabManager::getTasks()`：同名时数据库覆盖配置），
     * 因此后台看到的「下次执行时间 / 调度说明」与真实调度不会漂移。
     *
     * @param array $filter status / keyword
     *
     * @return array{list:array<int,array>, total:int, page:int, limit:int}
     */
    public function paginate(array $filter = [], int $page = 1, int $limit = 20): array
    {
        $page  = max(1, $page);
        $limit = max(1, min(200, $limit));

        $all   = $this->all($filter);
        $total = count($all);

        return [
            'list'  => array_slice($all, ($page - 1) * $limit, $limit),
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ];
    }

    /**
     * 全量任务（配置 + 数据库），附带最近一次执行结果与可编辑标记
     *
     * `editable = false` 表示该任务来自配置文件（`config/wise-crontab.php` 的 tasks），
     * 后台只能查看 / 立即执行，不能改删（改了也会被配置覆盖）。
     *
     * @param array $filter status / keyword
     *
     * @return array<int,array>
     */
    public function all(array $filter = []): array
    {
        $tasks = $this->mergeAllTasks();

        $names = [];
        foreach ($tasks as $task) {
            $name = (string) ($task['name'] ?? '');
            if ($name !== '') {
                $names[] = $name;
            }
        }

        $lastLogs = $this->lastLogs($names);

        $status  = $filter['status'] ?? null;
        $keyword = trim((string) ($filter['keyword'] ?? ''));

        $result = [];
        foreach ($tasks as $task) {
            $name = (string) ($task['name'] ?? '');
            $row  = $this->decorate($task);

            $row['editable'] = ((int) ($row['id'] ?? 0)) > 0;
            $row['last_log'] = $lastLogs[$name] ?? null;

            if ($status !== null && $status !== '' && (int) ($row['status'] ?? 0) !== (int) $status) {
                continue;
            }

            if ($keyword !== '') {
                $haystack = $name . ' ' . (string) ($row['title'] ?? '') . ' ' . (string) ($row['command'] ?? '');
                if (stripos($haystack, $keyword) === false) {
                    continue;
                }
            }

            $result[] = $row;
        }

        usort($result, static function (array $a, array $b): int {
            $statusDiff = (int) ($b['status'] ?? 0) <=> (int) ($a['status'] ?? 0);
            if ($statusDiff !== 0) {
                return $statusDiff;
            }

            $editableDiff = (int) ($b['editable'] ?? 0) <=> (int) ($a['editable'] ?? 0);
            if ($editableDiff !== 0) {
                return $editableDiff;
            }

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $result;
    }

    /**
     * 调度表达式非法的任务（任务名 => 错误信息）
     *
     * 面板化此前只存在于 CLI 输出里的信息（验收 #10）。
     *
     * @return array<string,string>
     */
    public function scheduleErrors(): array
    {
        try {
            $manager = new CrontabManager($this->config);
            $manager->getTasks();

            return $manager->getScheduleErrors();
        } catch (\Throwable $e) {
            return ['*' => $e->getMessage()];
        }
    }


    /**
     * 执行日志分页＼�
     *
     * @param array $filter task_name / status / keyword
     *
     * @return array{list:array<int,array>, total:int, page:int, limit:int}
     */
    public function logs(array $filter = [], int $page = 1, int $limit = 20): array
    {
        $page  = max(1, $page);
        $limit = max(1, min(200, $limit));

        $build = function () use ($filter) {
            $query = TaskLogModel::order('id', 'desc');

            $taskName = trim((string) ($filter['task_name'] ?? ''));
            if ($taskName !== '') {
                $query->whereLike('task_name', '%' . $this->escapeLike($taskName) . '%');
            }

            $status = trim((string) ($filter['status'] ?? ''));
            if ($status !== '') {
                $query->where('status', $status);
            }

            $keyword = trim((string) ($filter['keyword'] ?? ''));
            if ($keyword !== '') {
                $like = '%' . $this->escapeLike($keyword) . '%';
                $query->where(static function ($q) use ($like): void {
                    $q->whereLike('task_name', $like)->whereOr('output', 'like', $like)->whereOr('error_output', 'like', $like);
                });
            }

            return $query;
        };

        $total = (int) $build()->count();
        $list  = $build()->page($page, $limit)->select()->toArray();

        foreach ($list as &$row) {
            $row['duration_text'] = $this->durationText((float) ($row['duration'] ?? 0));
        }
        unset($row);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    /**
     * 单条执行日志详情（含输出 / 异常 / 耗时）
     */
    public function logDetail(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $log = TaskLogModel::find($id);
        if ($log === null) {
            return null;
        }

        $data = $log->toArray();
        $data['duration_text'] = $this->durationText((float) ($data['duration'] ?? 0));

        return $data;
    }

    /**
     * 每个任务最近一次执行日志
     *
     * @param array<int,string> $names
     *
     * @return array<string,array>
     */
    private function lastLogs(array $names): array
    {
        $names = array_values(array_unique(array_filter($names, static fn (string $n): bool => $n !== '')));
        if ($names === []) {
            return [];
        }

        $rows = TaskLogModel::whereIn('task_name', $names)->order('id', 'desc')->limit(1000)->select()->toArray();

        $map = [];
        foreach ($rows as $row) {
            $name = (string) ($row['task_name'] ?? '');
            if ($name !== '' && !isset($map[$name])) {
                $row['duration_text'] = $this->durationText((float) ($row['duration'] ?? 0));
                $map[$name] = $row;
            }
        }

        return $map;
    }

    /** 耗时可读化 */
    private function durationText(float $seconds): string
    {
        if ($seconds <= 0) {
            return '-';
        }

        if ($seconds < 1) {
            return round($seconds * 1000) . ' ms';
        }

        if ($seconds < 60) {
            return round($seconds, 2) . ' s';
        }

        return floor($seconds / 60) . ' min ' . round(fmod($seconds, 60)) . ' s';
    }

    /** LIKE 转义（% _ \） */
    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /**
     * 创建任务
     *
     * @throws CrontabException 校验失败或任务已存在
     */
    public function create(array $data): array
    {
        $payload = $this->validate($data);

        if (TaskModel::where('name', $payload['name'])->find() !== null) {
            throw new CrontabException("任务已存在: {$payload['name']}");
        }

        $task = TaskModel::create($payload);

        return $this->decorate($task->toArray());
    }

    /**
     * 更新任务（未提供的字段沿用原值）
     *
     * @throws CrontabException
     */
    public function update(string $name, array $data): array
    {
        $current = $this->requireTask($name);
        $merged = array_merge($current->toArray(), $data, ['name' => $name]);

        $payload = $this->validate($merged);
        unset($payload['name']);

        $current->save($payload);

        return $this->decorate($current->toArray());
    }

    /**
     * 删除任务（同时清理其依赖与维护窗口）
     *
     * @throws CrontabException
     */
    public function delete(string $name): bool
    {
        $task = $this->requireTask($name);
        $taskId = (int) $task->id;

        TaskDependencyModel::where('task_id', $taskId)->whereOr('depends_on_task_id', $taskId)->delete();
        MaintenanceWindowModel::where('task_id', $taskId)->delete();

        return $task->delete() !== false;
    }

    /**
     * @throws CrontabException
     */
    public function enable(string $name): bool
    {
        $task = $this->requireTask($name);
        $task->status = 1;

        return $task->save() !== false;
    }

    /**
     * @throws CrontabException
     */
    public function disable(string $name): bool
    {
        $task = $this->requireTask($name);
        $task->status = 0;

        return $task->save() !== false;
    }

    /**
     * 立即执行一次（不等待调度周期）
     *
     * @return array{status:string, exit_code:int|null, output:string, error_output:string, duration:float, retry_count:int, timeout:bool}
     *
     * @throws CrontabException
     */
    public function runNow(string $name): array
    {
        $task = Schedule::normalizeTask($this->requireTask($name)->toArray());

        $lockDir = ($this->config['lock_dir'] ?? '') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise-crontab-locks';
        $lock = new TaskLock($lockDir, (int) ($this->config['lock_ttl'] ?? 3600), $this->config);

        return (new TaskExecutor($this->config))->execute($task, $lock);
    }


    /**
     * 添加/更新任务依赖
     *
     * @param string $condition success（默认）| completed | any
     *
     * @throws CrontabException
     */
    public function addDependency(string $taskName, string $dependsOnName, string $condition = 'success'): bool
    {
        if (!in_array($condition, ['success', 'completed', 'any'], true)) {
            throw new CrontabException('依赖条件必须是 success / completed / any');
        }

        $task = $this->requireTask($taskName);
        $dependsOn = $this->requireTask($dependsOnName);

        if ((int) $task->id === (int) $dependsOn->id) {
            throw new CrontabException('任务不能依赖自身');
        }

        $exists = TaskDependencyModel::where('task_id', (int) $task->id)
            ->where('depends_on_task_id', (int) $dependsOn->id)
            ->find();

        if ($exists !== null) {
            $exists->condition = $condition;

            return $exists->save() !== false;
        }

        return TaskDependencyModel::create([
            'task_id'           => (int) $task->id,
            'depends_on_task_id' => (int) $dependsOn->id,
            'condition'         => $condition,
        ]) !== null;
    }

    /**
     * @throws CrontabException
     */
    public function removeDependency(string $taskName, string $dependsOnName): bool
    {
        $task = $this->requireTask($taskName);
        $dependsOn = $this->requireTask($dependsOnName);

        return TaskDependencyModel::where('task_id', (int) $task->id)
            ->where('depends_on_task_id', (int) $dependsOn->id)
            ->delete() > 0;
    }

    /**
     * 某任务的依赖列表（含被依赖任务名称）
     *
     * @throws CrontabException
     */
    public function dependencies(string $taskName): array
    {
        $task = $this->requireTask($taskName);

        $rows = TaskDependencyModel::where('task_id', (int) $task->id)->select()->toArray();
        $names = $this->taskNames(array_column($rows, 'depends_on_task_id'));

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'task_id'            => (int) $row['task_id'],
                'depends_on_task_id' => (int) $row['depends_on_task_id'],
                'depends_on_name'    => $names[(int) $row['depends_on_task_id']] ?? '',
                'condition'          => (string) ($row['condition'] ?? 'success'),
            ];
        }

        return $result;
    }


    /**
     * 新增维护窗口
     *
     * @param array $data task_id（null=全局窗口）/ start_at / end_at（HH:MM）/ days_of_week / reason
     *
     * @throws CrontabException
     */
    public function addWindow(array $data): int
    {
        $startAt = trim((string) ($data['start_at'] ?? ''));
        $endAt = trim((string) ($data['end_at'] ?? ''));

        if (!self::isTime($startAt) || !self::isTime($endAt)) {
            throw new CrontabException('维护窗口时间格式应为 HH:MM（支持跨午夜，如 22:00-02:00）');
        }

        $taskId = ($data['task_id'] ?? null) === null ? null : (int) $data['task_id'];
        if ($taskId !== null) {
            $this->requireTaskById($taskId);
        }

        $window = MaintenanceWindowModel::create([
            'task_id'      => $taskId,
            'start_at'     => $startAt,
            'end_at'       => $endAt,
            'days_of_week' => trim((string) ($data['days_of_week'] ?? '*')) ?: '*',
            'reason'       => (string) ($data['reason'] ?? ''),
        ]);

        return (int) $window->id;
    }

    public function removeWindow(int $id): bool
    {
        return MaintenanceWindowModel::where('id', $id)->delete() > 0;
    }

    /**
     * 维护窗口列表
     *
     * @param int|null $taskId 指定任务时返回该任务专用窗口 + 全局窗口；null 返回全部
     */
    public function windows(?int $taskId = null): array
    {
        $query = MaintenanceWindowModel::order('id', 'asc');
        if ($taskId !== null) {
            $query->where('task_id', $taskId)->whereOrNull('task_id');
        }

        return $query->select()->toArray();
    }


    /**
     * 回写下次执行时间（供后台列表直接展示）
     *
     * @param string|null $name 指定任务；null 表示全部启用任务
     *
     * @return int 更新条数
     */
    public function refreshNextRun(?string $name = null): int
    {
        $query = TaskModel::where('status', 1);
        if ($name !== null && $name !== '') {
            $query->where('name', $name);
        }

        $count = 0;
        foreach ($query->select()->toArray() as $row) {
            $next = $this->nextRunAt(Schedule::normalizeTask($row));
            TaskModel::where('id', (int) $row['id'])->update(['next_run_at' => $next]);
            $count++;
        }

        return $count;
    }

    /**
     * 计算下次执行时间（一次性任务为 run_at，cron 任务由表达式推算）
     */
    public function nextRunAt(array $task): ?string
    {
        if (($task['schedule_type'] ?? 'cron') === Schedule::TYPE_ONCE) {
            $runAt = (string) ($task['run_at'] ?? '');

            return ($runAt !== '' && empty($task['executed_at'])) ? $runAt : null;
        }

        $cron = (string) ($task['cron_expression'] ?? '');
        if ($cron === '') {
            return null;
        }

        try {
            return (new CronExpression($cron))->getNextRunDate()->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }


    /**
     * 合并「配置文件任务」与「数据库任务（含停用）」
     *
     * 覆盖优先级与调度器一致：同名时数据库任务生效（配置任务不再出现）。
     *
     * @return array<int,array>
     */
    private function mergeAllTasks(): array
    {
        try {
            $dbRows = TaskModel::order('id', 'asc')->select()->toArray();
        } catch (\Throwable $e) {
            $dbRows = [];
        }

        $dbNames = [];
        foreach ($dbRows as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name !== '') {
                $dbNames[$name] = true;
            }
        }

        $merged = [];
        foreach ((array) ($this->config['tasks'] ?? []) as $task) {
            if (!is_array($task)) {
                continue;
            }

            $name = (string) ($task['name'] ?? '');
            if ($name === '' || isset($dbNames[$name])) {
                continue;
            }

            $task['id']     = (int) ($task['id'] ?? 0);
            $task['source'] = (string) ($task['source'] ?? 'config');
            $merged[]       = $task;
        }

        foreach ($dbRows as $row) {
            $merged[] = $row;
        }

        return $merged;
    }

    /**
     * 归一化任务输出：折算 cron/中文描述 + 下次执行时间
     */
    private function decorate(array $task): array
    {
        try {
            $task = Schedule::normalizeTask($task);
        } catch (\Throwable $e) {
            $task['schedule_error'] = $e->getMessage();
        }

        $task['next_run_at'] = $this->nextRunAt($task);

        return $task;
    }

    /**
     * 校验并组装落库数据
     *
     * @throws CrontabException
     */
    private function validate(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new CrontabException('任务名称不能为空');
        }

        $command = trim((string) ($data['command'] ?? ''));
        if ($command === '') {
            throw new CrontabException('执行命令不能为空');
        }

        if (TaskExecutor::containsShellInjection($command)) {
            throw new CrontabException('命令包含 shell 元字符（; & | ` $ > < 换行），已拒绝: ' . $command);
        }

        $patterns = $this->config['allowed_command_patterns'] ?? ['/^php\s+/'];
        $allowed = false;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $command)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new CrontabException("命令不在白名单中: {$command}");
        }

        $status = self::normalizeStatus($data);

        $payload = [
            'name'             => $name,
            'title'            => (string) ($data['title'] ?? $name),
            'description'      => $data['description'] ?? null,
            'command'          => $command,
            'schedule_type'    => Schedule::TYPE_CRON,
            'status'           => $status,
            'timeout'          => (int) ($data['timeout'] ?? $this->config['default_timeout'] ?? 300),
            'max_retries'      => (int) ($data['max_retries'] ?? $this->config['default_max_retries'] ?? 0),
            'allow_concurrent' => (int) (bool) ($data['allow_concurrent'] ?? false),
            'source'           => (string) ($data['source'] ?? 'database'),
        ];

        $schedule = trim((string) ($data['schedule'] ?? ''));
        $cron = trim((string) ($data['cron_expression'] ?? ''));
        $expression = $schedule !== '' ? $schedule : $cron;

        if ($expression === '') {
            throw new CrontabException('调度表达式不能为空（schedule 或 cron_expression）');
        }

        try {
            $parsed = Schedule::parse($expression);
        } catch (\Throwable $e) {
            throw new CrontabException("调度表达式非法: {$expression}（{$e->getMessage()}）");
        }

        if ($parsed['schedule_type'] === Schedule::TYPE_ONCE) {
            $payload['schedule_type'] = Schedule::TYPE_ONCE;
            $payload['run_at'] = $parsed['run_at'];
            $payload['cron_expression'] = '';
        } else {
            $payload['cron_expression'] = $parsed['cron_expression'];
            $payload['run_at'] = null;
        }

        $payload['schedule'] = $schedule;

        return $payload;
    }

    /**
     * 把入参的 status/enabled 归一化为 0（禁用）/ 1（启用）
     *
     * 兼容后台表单常见的多种写法：bool、数字、'1'/'0'、'true'/'false'、'on'/'off'、'yes'/'no'。
     * status 缺失或为空串/null 时回退 enabled，两者都没有时默认启用。
     */
    private static function normalizeStatus(array $data): int
    {
        if (array_key_exists('status', $data) && !self::isBlankStatus($data['status'])) {
            return self::toEnabledFlag($data['status']);
        }

        if (array_key_exists('enabled', $data) && !self::isBlankStatus($data['enabled'])) {
            return self::toEnabledFlag($data['enabled']);
        }

        return 1;
    }

    private static function isBlankStatus(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private static function toEnabledFlag(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_numeric($value)) {
            return (float) $value !== 0.0 ? 1 : 0;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes', 'y', 'enable', 'enabled'], true) ? 1 : 0;
        }

        return 0;
    }

    /**
     * @throws CrontabException
     */
    private function requireTask(string $name): TaskModel
    {
        $task = TaskModel::where('name', $name)->find();
        if ($task === null) {
            throw new CrontabException("任务不存在: {$name}");
        }

        return $task;
    }

    /**
     * @throws CrontabException
     */
    private function requireTaskById(int $id): TaskModel
    {
        $task = TaskModel::find($id);
        if ($task === null) {
            throw new CrontabException("任务不存在: #{$id}");
        }

        return $task;
    }

    /**
     * 批量取任务名称：task_id → name
     *
     * @param array $ids
     *
     * @return array<int,string>
     */
    private function taskNames(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        $names = [];
        foreach (TaskModel::whereIn('id', $ids)->select()->toArray() as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }

        return $names;
    }

    private static function isTime(string $value): bool
    {
        return (bool) preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value);
    }
}
