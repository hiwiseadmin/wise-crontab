# wiseadmin/wise-crontab

> WiseAdmin 定时任务调度引擎 — 支持 Cron 表达式、双驱动任务源、分布式锁、任务编排、告警通知、并行执行

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)](https://www.php.net)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-%5E6.0%20%7C%7C%20%5E8.0-green)](https://www.thinkphp.cn)
[![License](https://img.shields.io/badge/license-MIT-brightgreen)](LICENSE)
[![Packagist](https://img.shields.io/badge/packagist-wiseadmin%2Fwise--crontab-orange)](https://packagist.org/packages/wiseadmin/wise-crontab)

---

## ✨ 特性

| 模块 | 说明 |
|------|------|
| **Cron 表达式** | 基于 `dragonmantank/cron-expression`，完整支持 `* * * * *` 格式 |
| **双驱动任务源** | 配置文件定义（`ConfigTaskRepository`）或数据库管理（`DatabaseTaskRepository`） |
| **文件锁防并发** | 基于 `flock`，TLL 自动过期，杜绝重复执行 |
| **重试机制** | 失败自动重试，可配置最大次数 |
| **超时控制** | 基于 `Symfony/Process`，防止任务僵尸进程 |
| **事件驱动** | `BeforeTaskExecute` / `AfterTaskExecute` / `TaskExecuteFailed` 生命周期事件 |
| **CLI 命令** | `wise:crontab:run` 调度运行 + `wise:crontab:publish` 发布配置 |
| **Facade 门面** | `Crontab::dispatch()` 静态调用 |

---

## 📦 安装

```bash
composer require wiseadmin/wise-crontab
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

将配置 `repository` 设为 `database`，即可通过数据库管理定时任务：

```php
use wise\crontab\model\TaskModel;

// 动态创建任务
TaskModel::create([
    'name'             => '自定义任务',
    'command'          => 'php think custom:task',
    'cron_expression'  => '0 */6 * * *',
    'allow_concurrent' => false,
    'status'           => 1,
]);
```

### 5. Cron 表达式参考

| 表达式 | 含义 |
|--------|------|
| `* * * * *` | 每分钟 |
| `*/5 * * * *` | 每5分钟 |
| `0 * * * *` | 每小时整点 |
| `0 2 * * *` | 每天凌晨2点 |
| `0 0 * * 0` | 每周日凌晨 |
| `0 0 1 * *` | 每月1号凌晨 |

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
    ],
];
```

### 常见 Cron 表达式示例

```php
// 每分钟
'* * * * *'

// 每5分钟（0, 5, 10, 15...）
'*/5 * * * *'

// 每30分钟（整点和半点）
'*/30 * * * *'

// 每小时
'0 * * * *'

// 每天凌晨3点
'0 3 * * *'

// 工作日上午9点（周一至周五）
'0 9 * * 1-5'

// 每月1号凌晨
'0 0 1 * *'

// 每周一早8点
'0 8 * * 1'
```

---

## ⚙️ 配置

发布配置文件：

```bash
php think wise:crontab:publish
```

配置文件 `config/wise-crontab.php`：

```php
return [
    // 文件锁目录
    'lock_dir' => runtime_path() . 'crontab_locks',

    // 锁过期时间（秒）
    'lock_ttl' => 3600,

    // 默认最大重试次数
    'default_max_retries' => 0,

    // 默认超时时间（秒）
    'default_timeout' => 300,

    // 输出最大截断长度（字节）
    'output_max_bytes' => 65536,

    // 日志保留天数
    'log_retention_days' => 30,

    // 任务仓库驱动：config / database
    'repository' => 'config',

    // 配置文件定义的任务
    'tasks' => [
        [
            'name'             => '清理临时文件',
            'command'          => 'php think clean:temp',
            'cron_expression'  => '0 2 * * *',      // 每天凌晨2点
            'allow_concurrent' => false,              // 不允许并发
            'max_retries'      => 1,
            'timeout'          => 600,
            'enabled'          => true,
        ],
        [
            'name'             => '备份数据库',
            'command'          => 'php think wise:backup:run',
            'cron_expression'  => '0 4 * * *',
            'allow_concurrent' => false,
            'enabled'          => true,
        ],
        [
            'name'             => '数据同步',
            'command'          => 'php think sync:data',
            'cron_expression'  => '*/10 * * * *',    // 每10分钟
            'allow_concurrent' => false,
            'enabled'          => true,
        ],
    ],
];
```

---

## 📂 目录结构

```
src/
├── Service.php                  # 服务提供者
├── CrontabManager.php           # 核心调度管理器
├── CronExpression.php           # Cron 表达式封装
├── TaskExecutor.php             # 任务执行器
├── TaskLock.php                 # 文件锁管理
├── TaskRepositoryInterface.php  # 任务仓库接口
├── command/
│   ├── Run.php                  # wise:crontab:run
│   └── Publish.php              # wise:crontab:publish
├── driver/
│   ├── ConfigTaskRepository.php  # 配置文件任务仓库
│   └── DatabaseTaskRepository.php # 数据库任务仓库
├── event/
│   ├── BeforeTaskExecute.php
│   ├── AfterTaskExecute.php
│   └── TaskExecuteFailed.php
├── exception/
│   ├── CrontabException.php
│   └── TaskLockException.php
├── facade/
│   └── Crontab.php              # Facade
├── model/
│   ├── TaskModel.php
│   └── TaskLogModel.php
└── trait/
    ├── ServiceTrait.php
    └── CommandTrait.php
```

---

## 📄 License

MIT
