# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

## [1.0.2] - 2026-11-25

### Added
- 新增任务管理命令与后台管理能力；支持语义化调度表达式与下一次执行时间落库。

### Changed
- 移除第三方 cron-expression 依赖，改为自研解析，公开 API 与行为保持兼容。
- 分布式锁默认改用 `redis`；`crontab_lock` 表结构与锁组件对齐，存量库需手工 ALTER。

### Fixed
- 修复任务超时、并发锁泄漏、单任务异常中断整轮调度、并行模式能力缺失、告警限流跨进程失效等问题。
- 修复维护窗口跨午夜与星期写法、新窗口误拦截、环检测与统计覆盖等问题。

## [1.0.0] - 2024-01-01

- Initial release

[1.0.0]: https://github.com/hiwiseadmin/wise-crontab/releases/tag/v1.0.0

[Unreleased]: https://github.com/hiwiseadmin/wise-crontab/compare/v1.0.2...HEAD
[1.0.2]: https://github.com/hiwiseadmin/wise-crontab/releases/tag/v1.0.2
