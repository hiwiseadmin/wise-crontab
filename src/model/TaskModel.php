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

    public function __construct($data = [])
    {
        parent::__construct($data);
        $this->setName(config('wise-crontab.table_names.task', $this->name));
    }

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
        'status'          => 'int',
        'timeout'         => 'int',
        'max_retries'     => 'int',
        'allow_concurrent' => 'int',
        'source'          => 'string',
        'schedule_type'   => 'string',   // E-4: 'cron' | 'once'
        'run_at'          => 'datetime', // E-4: 一次性任务执行时间
        'executed_at'     => 'datetime', // E-4: 实际执行时间
        'create_time'     => 'datetime',
        'update_time'     => 'datetime',
    ];
}
