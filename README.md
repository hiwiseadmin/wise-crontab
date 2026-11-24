# wiseadmin/wise-crontab

> 适用：WiseAdmin 1.0+ / PHP 8.1+ / ThinkPHP 6/8
> 状态：稳定
> 可独立使用：是 —— 需数据库迁移，系统 crontab 每分钟触发调度器
> 直接依赖：wise-support

> WiseAdmin 定时任务调度引擎 — 支持 Cron 表达式、双驱动任务源、分布式锁、任务编排、告警通知、并行执行

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)](https://www.php.net)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-%5E6.0%20%7C%7C%20%5E8.0-green)](https://www.thinkphp.cn)
[![License](https://img.shields.io/badge/license-Apache%202.0-brightgreen)](LICENSE)
[![Packagist](https://img.shields.io/badge/packagist-wiseadmin%2Fwise--crontab-orange)](https://packagist.org/packages/wiseadmin/wise-crontab)

---

## ✨ 特性

| 模块 | 说明 |
|------|------|
| **语义化调度** | 中英双语自然语言，如 `每5分钟`、`daily at 02:30`、`每月最后一个周五 09:00`，无需记忆 cron 格式 |
| **Cron 表达式** | 自研解析器，零第三方依赖；支持 `L`/`W`/`#`、`JAN`-`DEC`/`MON`-`SUN` 与 `@daily` 等别名 |
| **双驱动任务源** | 配置文件定义（`ConfigTaskRepository`）或数据库管理（`DatabaseTaskRepository`） |
| **文件锁防并发** | 基于 `flock`，TLL 自动过期，杜绝重复执行 |
| **重试机制** | 失败自动重试，可配置最大次数 |
| **超时控制** | 基于 `Symfony/Process`，防止任务僵尸进程 |
| **事件驱动** | `BeforeTaskExecute` / `AfterTaskExecute` / `TaskExecuteFailed` 生命周期事件 |
| **CLI 命令** | `wise:crontab:run` 调度运行 |
| **Facade 门面** | `Crontab::dispatch()` 静态调用 |

---

## 📦 安装

```bash
composer require wiseadmin/wise-crontab
```

安装后执行（发布配置 + 建表）：

```bash
# 发布 config/wise-crontab.php（ThinkPHP 服务发现会自动完成，必要时手动执行）
php think vendor:publish --provider="wise\crontab\CrontabServiceProvider"

# 执行包内置迁移（crontab_task / crontab_task_log / crontab_task_dependency /
# crontab_maintenance_window / crontab_lock）
php think migrate:run
```

### 推荐包

```bash
# 异步日志写入和任务队列支持
composer require topthink/think-queue

# Redis 分布式锁和缓存支持
composer require topthink/think-cache

# 任务失败多渠道告警通知
composer require wiseadmin/wise-notification
```

---

## 5 分钟最小示例

> 前置：数据库任务/日志需要迁移；命令默认只允许 `php` 开头；系统 crontab 每分钟触发调度器。

```bash
php think vendor:publish --provider="wise\crontab\CrontabServiceProvider"
php think migrate:run

php think wise:crontab:task add 数据同步 \
  --command="php think sync:data" \
  --schedule="每10分钟"

# 系统 crontab：
# * * * * * cd /path/to/project && php think wise:crontab:run >> /dev/null 2>&1
```

```php
use wise\crontab\facade\Crontab;

$result = Crontab::dispatch();
print_r($result);
```

## 🚀 快速开始

### 1. 运行调度器

```bash
# 单次执行（扫描到期任务并执行）
php think wise:crontab:run
```

通常在系统的 crontab 中配每分钟触发：

```cron
* * * * * cd /path/to/project && php think wise:crontab:run >> /dev/null 2>&1
```

### 2. 编程方式调用

```php
use wise\crontab\facade\Crontab;

// 执行到期任务
$result = Crontab::dispatch();
// 返回: ['total' => 5, 'executed' => 3, 'success' => 2, 'failure' => 1, 'skipped' => 2]

// 获取所有任务
$tasks = Crontab::getTasks();

// 获取即将执行的任务
$upcoming = Crontab::getUpcomingTasks(10);

// 清理过期日志
$count = Crontab::cleanExpiredLogs();
```

### 3. 依赖注入方式

```php
use wise\crontab\CrontabManager;

class MonitorController
{
    public function status(CrontabManager $manager)
    {
        return json([
            'tasks'    => $manager->getTasks(),
            'upcoming' => $manager->getUpcomingTasks(20),
        ]);
    }
}
```

### 4. 数据库管理任务

除配置文件外，任务也可以直接写入 `crontab_task` 表（两种来源合并加载，**同名时数据库优先**）：

```php
use wise\crontab\model\TaskModel;

// 动态创建任务（语义化调度）
TaskModel::create([
    'name'             => '自定义任务',
    'command'          => 'php think custom:task',
    'schedule'         => '每6小时',           // 等价 '0 */6 * * *'；也可继续使用 cron_expression
    'allow_concurrent' => false,
    'status'           => 1,
]);

// 一次性任务
TaskModel::create([
    'name'          => '限时活动开启',
    'command'       => 'php think game-ops:open-activity',
    'schedule_type' => 'once',
    'run_at'        => '2026-09-20 10:00:00',
    'status'        => 1,
]);
```

### 5. 调度表达式

`schedule` 字段支持**中英双语语义化写法**，无需记忆 cron 格式；`cron_expression` 保留原生 5 段表达式（`schedule` 为空时生效）。

#### 语义化写法（推荐）

| 中文写法 | 英文写法 | 解析为 cron |
|----------|----------|-------------|
| `每分钟` | `every minute` | `* * * * *` |
| `每5分钟` | `every 5 minutes` | `*/5 * * * *` |
| `每30分钟 从9点到18点` | `every 30 minutes between 09:00 and 18:00` | `*/30 9-18 * * *` |
| `每小时` | `hourly` / `every hour` | `0 * * * *` |
| `每2小时` | `every 2 hours` | `0 */2 * * *` |
| `每天凌晨2点` | `daily at 02:00` | `0 2 * * *` |
| `每天上午9点30分` | `every day at 09:30` | `30 9 * * *` |
| `工作日9点` | `weekdays at 09:00` | `0 9 * * 1-5` |
| `每周一8点` | `weekly on monday at 08:00` | `0 8 * * 1` |
| `每周一、周三、周五 9点` | — | `0 9 * * 1,3,5` |
| `每月1号凌晨` | `monthly on 1st at 00:00` | `0 0 1 * *` |
| `每月最后一天 23:00` | `monthly on the last day at 23:00` | `0 23 L * *` |
| `每月第三个周五 09:00` | `monthly on the third friday at 09:00` | `0 9 * * 5#3` |
| `每月最后一个周五 18:00` | `monthly on the last friday at 18:00` | `0 18 * * 5L` |
| `每月15号最近的工作日 09:00` | — | `0 9 15W * *` |
| `每年` | `yearly` / `annually` | `0 0 1 1 *` |
| `每年3月15日 10:00` | — | `0 10 15 3 *` |
| `once at 2026-09-20 10:00`、`2026-09-20 10:00`、`9月20日 10:00 执行一次` | 同左 | 一次性任务（`schedule_type=once` + `run_at`） |

> 仅写 `at 2:30` 这类**不含日期**的写法会被拒绝（避免误排到当天），请补全年月日。

#### 原生 cron（向后兼容）

字段顺序为 `分 时 日 月 周`，日与周**同时被限定时按「或」匹配**（标准 cron 语义）。

| 表达式 | 含义 |
|--------|------|
| `* * * * *` | 每分钟 |
| `*/5 * * * *` | 每5分钟 |
| `0 * * * *` | 每小时整点 |
| `0 2 * * *` | 每天凌晨2点 |
| `0 9 * * 1-5` | 工作日上午9点 |
| `0 0 * * 0` | 每周日 0 点（`7` 亦表示周日，`SUN`-`SAT` 同样支持） |
| `0 0 1 * *` | 每月1号 0 点 |
| `0 0 L * *` | 每月最后一天 |
| `0 0 15W * *` | 每月15号最近的工作日 |
| `0 0 * * 5L` | 每月最后一个周五 |
| `0 0 * * 5#3` | 每月第三个周五 |
| `0 0 * FEB-DEC/3 *` | 2、5、8、11 月 1 号（支持 `JAN`-`DEC`） |
| `@daily`、`@hourly`、`@weekly`、`@monthly`、`@yearly`、`@midnight`、`@annually` | 内置别名 |

若表达式不可能命中（如 `0 0 30 2 *`），该任务会被跳过，并写入执行日志。

#### 解析与回显

```php
use wise\crontab\Schedule;

// 语义化 → cron
Schedule::toCron('每5分钟');                 // '*/5 * * * *'
Schedule::toCron('工作日9点');               // '0 9 * * 1-5'

// 解析（含一次性任务）
$parsed = Schedule::parse('once at 2026-09-20 10:00');
// ['schedule_type' => 'once', 'run_at' => '2026-09-20 10:00:00', 'description' => '仅执行一次（...）', ...]

// cron → 中文描述（后台展示 / MCP 输出）
Schedule::describe('0 9 * * 1-5');           // '工作日 09:00'
Schedule::describe('0 23 L * *');            // '每月最后一天 23:00'

// 校验用户输入
Schedule::isValid('每月最后一个周五 09:00'); // true
```

### 6. 事件监听

```php
// app/event.php
return [
    'listen' => [
        \wise\crontab\event\BeforeTaskExecute::class => [
            \app\listener\TaskStartLogger::class,
        ],
        \wise\crontab\event\AfterTaskExecute::class => [
            \app\listener\TaskResultLogger::class,
        ],
        \wise\crontab\event\TaskExecuteFailed::class => [
            \app\listener\TaskFailureAlert::class,
        ],
        \wise\crontab\event\CrontabAlertEvent::class => [
            \app\listener\CrontabAlertNotifier::class,
        ],
    ],
];
```

---

## ⚙️ 配置参考

配置文件 `config/wise-crontab.php`（安装后可执行 `php think vendor:publish` 发布）：

| 配置键 | 默认值 | 说明 |
|--------|--------|------|
| `tasks` | `[]` | 配置文件定义的任务列表，字段见下一小节 |
| `allowed_command_patterns` | `['/^php\s+/']` | 命令白名单（正则），未命中的命令会被拒绝执行，防命令注入 |
| `lock_driver` | `'file'` | 锁驱动：`file` / `redis` / `database` |
| `lock_dir` | `''` | 文件锁目录，空则使用系统临时目录 |
| `lock_ttl` | `3600` | 锁过期时间（秒） |
| `lock_redis_store` | `'redis'` | Redis 驱动使用的 cache store（须是 `cache.php` 中的 redis 通道） |
| `lock_db_table` | `'crontab_lock'` | DB 驱动使用的锁表名 |
| `default_timeout` | `300` | 任务默认超时（秒） |
| `default_max_retries` | `0` | 任务默认失败重试次数 |
| `output_max_bytes` | `65536` | 执行输出截断长度（字节） |
| `log_retention_days` | `30` | 执行日志保留天数 |
| `auto_clean_logs` | `false` | 每次 `dispatch()` 后自动清理过期日志 |
| `parallel.enabled` | `false` | 是否并行执行到期任务 |
| `parallel.max_concurrent` | `5` | 并行执行上限 |
| `parallel.wait_all` | `true` | 是否等待全部子进程结束 |
| `parallel.wait_timeout` | `600` | 并行等待超时（秒） |
| `concurrency.max_global` | `0` | 全局并发槽位上限，`0` 表示不限制 |
| `alert.enabled` | `false` | 是否启用失败/超时告警 |
| `alert.on_failure` | `true` | 失败时告警 |
| `alert.on_timeout` | `true` | 超时时告警 |
| `alert.channels` | `['email']` | 告警渠道（需宿主实现 `CrontabAlertEvent` 监听） |
| `alert.throttle` | `300` | 同一任务告警最小间隔（秒） |
| `alert.recipients` | `[]` | 告警接收人 |

### 任务字段（`tasks` 数组 / `crontab_task` 表）

| 字段 | 必填 | 说明 |
|------|------|------|
| `name` | ✅ | 任务唯一名称 |
| `command` | ✅ | 执行命令，需命中 `allowed_command_patterns`（如 `php think xxx`） |
| `schedule` | ⬜ | 语义化调度表达式（推荐，见上一节），**优先于** `cron_expression` |
| `cron_expression` | ⬜ | 原生 5 段 cron 表达式（`schedule` 为空时生效） |
| `schedule_type` | ⬜ | `cron`（默认）/ `once`（一次性任务） |
| `run_at` | ⬜ | 一次性任务执行时间（`schedule_type=once` 时必填） |
| `executed_at` | ⬜ | 一次性任务实际执行时间（执行成功后自动写入并禁用） |
| `allow_concurrent` | ⬜ | 是否允许并发，默认 `false`（同一任务串行） |
| `max_retries` | ⬜ | 失败重试次数，默认取 `default_max_retries` |
| `timeout` | ⬜ | 超时时间（秒），默认取 `default_timeout` |
| `enabled` / `status` | ⬜ | `enabled=false` 等价于 `status=0`（禁用） |
| `description` | ⬜ | 任务描述（仅用于展示） |

> 配置文件任务与数据库任务（`crontab_task` 表）会**合并加载，同名时数据库优先**，没有单独的 `repository` 开关。
> 调度表达式非法的任务不会被执行，会在 `php think wise:crontab:run` 输出与错误日志中提示。

### 执行语义（串行 / 并行）

串行（`parallel.enabled=false`）与并行（`true`）具备同等能力，行为如下：

| 环节 | 行为 |
|------|------|
| 命令白名单 | 两种模式都会校验 `allowed_command_patterns`，未命中的命令按失败记录，不启动进程 |
| 执行日志 | 两种模式都会写入 `crontab_task_log`（running → success/failure） |
| 超时 | 超过 `timeout` 秒即终止进程，按**失败**结算，`timeout` 标记置位，且**不再重试**（避免 N × 超时时长） |
| 失败重试 | 最多 `max_retries` 次；重试次数、退出码、输出、耗时均写入日志 |
| 锁 | 任务级并发锁在 `finally` 中释放，任何异常路径都不会残留到 TTL 到期 |
| 单任务异常 | 被捕获并记录，不会中断本轮其余任务 |
| 告警 | 普通失败走 `alert.on_failure`，超时走 `alert.on_timeout` |

> 并行模式下 `wait_all=false` 时只负责拉起进程、不等待结果，统计中只体现 `executed`。

### 配置示例

```php
return [
    // 命令白名单：只允许 php 命令
    'allowed_command_patterns' => ['/^php\s+/'],

    // 并行执行（可选）
    'parallel' => [
        'enabled'        => false,
        'max_concurrent' => 5,
    ],

    'tasks' => [
        [
            'name'             => '清理临时文件',
            'command'          => 'php think clean:temp',
            'schedule'         => '每天凌晨2点',       // 语义化：等价 '0 2 * * *'
            'allow_concurrent' => false,
            'max_retries'      => 1,
            'timeout'          => 600,
            'enabled'          => true,
        ],
        [
            'name'            => '备份数据库',
            'command'         => 'php think wise:backup:run',
            'cron_expression' => '0 4 * * *',         // 原生 cron 同样支持
            'enabled'         => true,
        ],
        [
            'name'     => '数据同步',
            'command'  => 'php think sync:data',
            'schedule' => '每10分钟',
            'enabled'  => true,
        ],
    ],
];
```

---

## 🛠 管理命令

| 命令 | 说明 |
|------|------|
| `php think wise:crontab:run` | 扫描并执行到期任务（系统 crontab 每分钟调用）；`--clean` 执行后清理过期日志 |
| `php think wise:crontab:list` | 查看任务列表（调度、中文说明、下次执行、状态）；`--refresh` 回写下次执行时间 |
| `php think wise:crontab:task <动作> <任务名>` | 任务管理：`add` / `update` / `delete` / `enable` / `disable` / `run` |
| `php think wise:crontab:dependency <动作> --task= --depends=` | 依赖管理：`list` / `add` / `remove` |
| `php think wise:crontab:window <动作>` | 维护窗口管理：`list` / `add` / `remove` |
| `php think wise:crontab:stats` | 执行统计：`--task`、`--trend`、`--anomalies`、`--hours` |
| `php think wise:crontab:clean` | 清理过期日志：`--days`、`--force` |

```bash
# 新增任务（语义化调度）
php think wise:crontab:task add 数据同步 --command="php think sync:data" --schedule="每10分钟"

# 调整调度 / 立即执行一次
php think wise:crontab:task update 数据同步 --schedule="每天凌晨2点"
php think wise:crontab:task run 数据同步

# 依赖：日报任务等待数据同步成功后再执行
php think wise:crontab:dependency add --task=日报预计算 --depends=数据同步 --condition=success

# 夜间发版窗口（跨午夜，周一至周五）
php think wise:crontab:window add --start=22:00 --end=02:00 --days=1-5 --reason="夜间发版"
```

编程方式（后台管理接口可直接复用 `TaskAdminService`）：

```php
use wise\crontab\service\TaskAdminService;

/** @var TaskAdminService $service */
$service = app(TaskAdminService::class);

$service->create(['name' => 'demo', 'command' => 'php think demo', 'schedule' => '每5分钟']);
$service->update('demo', ['schedule' => '工作日9点']);
$service->disable('demo');
$service->addDependency('demo', '数据同步', 'success');
$result = $service->runNow('demo');   // 立即执行一次
$service->refreshNextRun();           // 回写全部任务的下次执行时间
```

---

## 🔗 任务依赖与维护窗口

**依赖**（`crontab_task_dependency`）：定义「某任务必须在其前置任务之后执行」：

| condition | 含义 |
|-----------|------|
| `success` | 前置任务最近一次执行**成功**（默认） |
| `completed` | 前置任务最近一次执行完成（成功或失败均可） |
| `any` | 前置任务只要有执行记录即可 |

依赖未满足的任务会被跳过并计入 `blocked_by_dependency`；`TaskGraph::detectCycles()` 可检测循环依赖。

**维护窗口**（`crontab_maintenance_window`）：定义任务不可执行的时间窗，`task_id` 为 NULL 表示全局窗口：

- 时间格式 `HH:MM`，支持**跨午夜**（如 `22:00`–`02:00`）
- `days_of_week` 支持 `*`、`1-5`、`0,6`、`1-3,6` 等写法
- 命中窗口的任务被跳过并计入 `blocked_by_window`，同时写一条 `skipped` 日志

---

## 🗄 数据库表结构

| 表 | 说明 | 主要字段 |
|----|------|----------|
| `crontab_task` | 任务定义 | `name`、`title`、`command`、`schedule`（语义化）、`cron_expression`、`schedule_type`、`run_at`、`executed_at`、`next_run_at`、`status`、`timeout`、`max_retries`、`allow_concurrent`、`source` |
| `crontab_task_log` | 执行日志 | `task_id`、`task_name`、`status`（running/success/failure/skipped）、`output`、`error_output`、`exit_code`、`start_time`、`end_time`、`duration`、`retry_count`、`process_id` |
| `crontab_task_dependency` | 任务依赖 | `task_id`、`depends_on_task_id`、`condition` |
| `crontab_maintenance_window` | 维护窗口 | `task_id`（NULL=全局）、`start_at`、`end_at`、`days_of_week`、`reason` |
| `crontab_lock` | 分布式锁（`lock_driver=database`） | `lock_key`、`task_name`、`create_time`、`expires_at` |

> 升级安装执行 `php think migrate:run` 即可补全 `schedule`、`next_run_at` 字段与 `crontab_lock` 表（迁移均为幂等）。

---

## 📂 目录结构

```
src/
├── CrontabServiceProvider.php   # 服务提供者（注册配置/门面/命令/迁移/事件/MCP）
├── CrontabManager.php           # 核心调度管理器
├── CronExpression.php           # 自研 Cron 表达式解析器（含 L/W/#）
├── Schedule.php                 # 语义化调度解析（中英双语）+ cron 中文描述
├── TaskExecutor.php             # 任务执行器（symfony/process）
├── TaskLock.php                 # 分布式锁封装
├── SemaphoreLock.php            # 全局并发信号量
├── TaskGraph.php                # 任务依赖图
├── TaskRepositoryInterface.php  # 任务仓库接口
├── alert/
│   └── AlertManager.php         # 失败/超时告警
├── command/
│   ├── Run.php                  # wise:crontab:run
│   ├── Clean.php                # wise:crontab:clean
│   └── Stats.php                # wise:crontab:stats
├── driver/
│   ├── ConfigTaskRepository.php   # 配置文件任务仓库
│   └── DatabaseTaskRepository.php # 数据库任务仓库
├── event/
│   ├── BeforeTaskExecute.php
│   ├── AfterTaskExecute.php
│   ├── TaskExecuteFailed.php
│   └── CrontabAlertEvent.php
├── exception/
│   ├── CrontabException.php
│   ├── TaskLockException.php
│   └── ErrorCode.php
├── facade/
│   └── Crontab.php              # Facade
├── mcp/
│   └── CrontabToolProvider.php  # MCP 工具（crontab.list / crontab.get）
├── metrics/
│   └── TaskMetrics.php          # 执行统计与异常检测
├── service/
│   └── TaskAdminService.php     # 管理面服务（任务/依赖/维护窗口增删改）
└── model/
    ├── TaskModel.php
    ├── TaskLogModel.php
    ├── TaskDependencyModel.php
    └── MaintenanceWindowModel.php
```

---

## 依赖关系

- **必需**：`topthink/framework`、`topthink/think-orm`、`symfony/process`、`ext-json`
- **Cron 解析**：包内自研（`src/CronExpression.php` + `src/Schedule.php`），不依赖任何第三方 cron 库
- **可选（suggest）**：`topthink/think-queue`（异步日志）、`topthink/think-cache`（Redis 分布式锁）、`wise-notification`（失败告警）、`wise-mcp`（MCP 工具暴露）
- **迁移**：迁移目录由 `registerMigrations()` 注册，安装后需执行迁移

## 许可证

Apache-2.0
