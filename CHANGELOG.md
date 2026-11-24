# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

## [1.0.1] - 2026-11-24

### Fixed
- 任务超时不再中断整轮调度：`TaskExecutor` 捕获 `ProcessTimedOutException`，终止进程并按失败结算
  （写日志、标记 `timeout`、不重试），告警走 `alert.on_timeout`
- 任务级并发锁改用 `finally` 释放，异常路径不再残留到 TTL 到期
- `CrontabManager::executeTask()` 增加异常兜底，单个任务异常不影响本轮其余任务
- 并行模式补齐与串行同等的能力：命令白名单校验、执行日志、超时终止（`checkTimeout()` + `stop(0)`）、
  失败重试、事件与告警（此前会绕过整个执行器）
- 配置源一次性任务不再每分钟重复执行：依据执行日志判断幂等（`hasSucceededSince()`）
- 维护窗口表不可用时不再抛出异常中断调度，降级为“无窗口”并只记录一次警告
- 并行等待超时（`parallel.wait_timeout`）时不再丢下子进程：终止进程、按失败收尾日志并释放任务级锁
- 维护窗口：修复 `taskId=null` 时返回全部窗口导致误拦截的问题，支持跨午夜窗口与 `1-5`/`0,6` 星期写法
- 告警限流改为基于执行日志持久化，跨 CLI 进程生效（此前静态内存态在每次调度后失效）
- `TaskMetrics::getDailyStats()` 修复引用别名导致不同任务统计互相覆盖的问题
- `TaskGraph::detectCycles()` 修复环路径错误（`$path` 未引用传递）并对同一环去重
- `lock_redis_store` 默认值统一为 `redis`（原配置值 `default` 指向文件缓存，无法用于分布式锁）

### Added
- 管理面闭环：新增 `TaskAdminService`（任务增删改查、启停、立即执行、依赖与维护窗口维护）
  与 4 个命令：`wise:crontab:list`、`wise:crontab:task`、`wise:crontab:dependency`、`wise:crontab:window`
- `next_run_at` 落库：任务执行后自动回写下一次执行时间（`crontab_task.next_run_at`，迁移 20260918000003）
- 迁移 `20260918000002` 预建 `crontab_lock` 表（`lock_driver=database` 不再依赖运行时建表权限）
- 语义化调度：新增 `schedule` 字段，支持中英双语自然语言（`每5分钟` / `daily at 02:30` / `每月最后一个周五 09:00`），
  由 `Schedule` 统一折算为 cron 表达式；`crontab_task` 表新增 `schedule` 列（迁移 `20260918000001`）
- `Schedule::describe()`：cron 表达式转中文描述，供后台展示与 MCP 输出使用
- `CrontabManager::getScheduleErrors()`：暴露调度表达式非法的任务，`wise:crontab:run` 会打印提示
- 回归测试：新增 `CronExpressionGoldenTest`（基于原库生成的 462 组黄金用例）与 `ScheduleTest`

### Changed
- **移除第三方依赖 `dragonmantank/cron-expression`**：`CronExpression` 改为自研实现，支持 `*` `,` `-` `/` `?`、
  `L`/`W`/`#`、`JAN`-`DEC`/`MON`-`SUN` 与 `@daily` 等别名，公开 API 与原有行为保持一致
- 配置文件示例与 README 更正：补充完整配置参考、语义化调度语法表、任务字段表
- 修正 `wise-game-energy` / `wise-game-ops` 的 crontab 示例配置（键名与 `php think` 命令前缀）
- 迁移 `20260918000002`（`crontab_lock`）列定义与 `DistributedLock::ensureDbTable()` 完全对齐：
  `expires_at` 改为**无符号 `INT`**（消除 2038 溢出风险）、`create_time` 改为 `DATETIME`（与框架时间字段约定一致），
  四列统一 `NOT NULL`。不做向后兼容，存量库需手工 `ALTER` 或重建该表
- `TaskLock` 补齐 `db_table` 参数：此前 `lock_db_table` 配置无任何消费者，database 驱动实际落回默认的 `system_lock`；
  现在按配置使用 `crontab_lock`，经 `Db::name()` 拼表前缀后为 `ws_crontab_lock`，与迁移建表一致

## [1.0.0] - 2024-01-01

### Added
- Initial release

[1.0.0]: https://github.com/hiwiseadmin/wise-crontab/releases/tag/v1.0.0

[Unreleased]: https://github.com/hiwiseadmin/wise-crontab/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/hiwiseadmin/wise-crontab/releases/tag/v1.0.1
