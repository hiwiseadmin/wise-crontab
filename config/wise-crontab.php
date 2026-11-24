<?php

return [
    /**
     * 配置文件定义的任务
     *
     * 每个任务支持以下字段（以「清理临时文件」为例）：
     *
     * [
     *     // 语义化调度（推荐，中英双语）：优先于 cron_expression
     *     'schedule'         => '每天凌晨2点',        // 或 'daily at 02:30'、'每5分钟'、'每月最后一个周五 09:00'
     *     // 原生 5 段 cron（向后兼容，schedule 为空时生效）
     *     'cron_expression'  => '0 2 * * *',
     *     'name'             => '清理临时文件',        // 必填，唯一
     *     'command'          => 'php think clean:temp', // 必填，需命中 allowed_command_patterns
     *     'allow_concurrent' => false,                 // 是否允许并发执行
     *     'max_retries'      => 1,                     // 失败重试次数
     *     'timeout'          => 600,                   // 超时（秒）
     *     'enabled'          => true,                  // false 等价于 status=0
     *     'status'           => 1,                     // 0 禁用 / 1 启用
     * ],
     */
    'tasks' => [],

    // 命令白名单（正则）：只有命中的命令才会被执行，防止命令注入
    'allowed_command_patterns' => ['/^php\s+/'],

    // 锁配置
    'lock_driver'        => 'file',              // file | redis | database
    'lock_dir'           => '',                  // 文件锁目录（空=系统临时目录）
    'lock_ttl'           => 3600,                // 锁过期时间（秒）
    'lock_redis_store'   => 'redis',             // Redis 驱动使用的 cache store（必须是 cache.php 中的 redis 通道）
    'lock_db_table'      => 'crontab_lock',      // DB 驱动使用的锁表名

    // 默认值
    'default_timeout'     => 300,
    'default_max_retries' => 0,
    'output_max_bytes'    => 65536,

    // 日志
    'log_retention_days' => 30,
    'auto_clean_logs'    => false,               // 是否在每次 dispatch 后自动清理

    // 并行执行（O-3）
    'parallel' => [
        'enabled'        => false,
        'max_concurrent' => 5,
        // wait_all=false 只表示「最多等 wait_timeout」，不会再「启动完就退出」：
        // 提前退出会让父进程带走 flock/信号量，子进程仍在跑、日志永久停在 running。
        'wait_all'       => true,
        'wait_timeout'   => 600,
    ],

    // 全局并发限制（E-6）
    'concurrency' => [
        'max_global' => 0,       // 0=不限制
        // 槽位 TTL（秒）：0=自动推导（wait_timeout + default_timeout + 60）。
        // 太短 → 长任务执行中槽位过期被重复占用；太长 → 进程被 kill 后长时间空占。
        'slot_ttl'   => 0,
    ],

    // 单轮调度的全局互斥锁 TTL（秒）：0=自动推导（wait_timeout + 60）。
    // 防止上一轮还没跑完、下一分钟的 tick 或另一台机器重复调度同一批任务。
    'dispatch_lock_ttl' => 0,

    // 告警配置（E-2）
    'alert' => [
        'enabled'    => false,
        'on_failure' => true,
        'on_timeout' => true,
        'channels'   => ['email'],
        'throttle'   => 300,     // 同一任务告警间隔（秒）
        'recipients' => [],
    ],

    // ==========================================
    //  后台界面（P0-3，配置驱动路由）
    // ==========================================
    // 由 wise\crontab\RouteRegistrar 读取；宿主可在 config/wise-crontab.php 覆盖。
    // 未安装 WiseAdmin 宿主（无 app\admin\controller\BaseController）时后台组自动跳过。
    'web' => [
        'enable'     => true,
        // 投递优先级（扩展点内排序）
        'priority'   => 100,
        'middleware' => [],
        'groups'     => [
            // 后台管理页面：/admin/crontab/**
            // 鉴权走 admin 应用级 RBAC 白名单 + CSRF，包内不再挂中间件。
            'admin' => [
                'enable'     => true,
                'prefix'     => 'admin/crontab',
                'middleware' => [],
            ],
        ],
    ],

    // 立即执行的限流（秒）：同一任务在该窗口内只允许手动触发一次，
    // 与 TaskLock 的「执行期互斥」互补（前者防连点，后者防并发执行）。
    'manual_run_throttle' => 60,
];
