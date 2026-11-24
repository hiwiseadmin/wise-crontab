<?php

declare(strict_types=1);

namespace wise\crontab\model;

use think\Model;

class TaskModel extends Model
{
    /**
     * 表名（不含前缀，框架自动添加）
     *
     * @var string
     */
    protected $name = 'crontab_task';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    protected $schema = [
        'id'              => 'int',
        'name'            => 'string',
        'title'           => 'string',
        'description'     => 'string',
        'command'         => 'string',
        'cron_expression' => 'string',
        'schedule'        => 'string',
        'next_run_at'     => 'datetime',
        'status'          => 'int',
        'timeout'         => 'int',
        'max_retries'     => 'int',
        'allow_concurrent' => 'int',
        'source'          => 'string',
        'schedule_type'   => 'string',
        'run_at'          => 'datetime',
        'executed_at'     => 'datetime',
        'create_time'     => 'datetime',
        'update_time'     => 'datetime',
    ];
}
