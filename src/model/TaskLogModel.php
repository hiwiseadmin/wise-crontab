<?php

declare(strict_types=1);

namespace wise\crontab\model;

use think\Model;

class TaskLogModel extends Model
{
    /**
     * 表名（不含前缀，框架自动添加）
     *
     * @var string
     */
    protected $name = 'crontab_task_log';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = false;

    protected $schema = [
        'id'           => 'int',
        'task_id'      => 'int',
        'task_name'    => 'string',
        'status'       => 'string',
        'output'       => 'string',
        'error_output' => 'string',
        'exit_code'    => 'int',
        'start_time'   => 'datetime',
        'end_time'     => 'datetime',
        'duration'     => 'float',
        'retry_count'  => 'int',
        'process_id'   => 'int',
        'create_time'  => 'datetime',
    ];
}
