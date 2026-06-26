<?php

return [
    // 配置文件定义的任务
    'tasks' => [],

    // 数据库表名配置
    'table_names' => [
        'task'                => 'crontab_task',
        'task_log'            => 'crontab_task_log',
        'task_dependency'     => 'crontab_task_dependency',
        'maintenance_window'  => 'crontab_maintenance_window',
    ],

    // 锁配置
    'lock_driver'        => 'file',              // file | redis | database
    'lock_dir'           => '',                  // 文件锁目录（空=系统临时目录）
    'lock_ttl'           => 3600,                // 锁过期时间（秒）
    'lock_redis_store'   => 'default',           // Redis 驱动使用的 cache store
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
        'wait_all'       => true,
        'wait_timeout'   => 600,
    ],

    // 全局并发限制（E-6）
    'concurrency' => [
        'max_global' => 0,       // 0=不限制
    ],

    // 告警配置（E-2）
    'alert' => [
        'enabled'    => false,
        'on_failure' => true,
        'on_timeout' => true,
        'channels'   => ['email'],
        'throttle'   => 300,     // 同一任务告警间隔（秒）
        'recipients' => [],
    ],
];
