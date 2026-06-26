<?php

declare(strict_types=1);

namespace wise\crontab\model;

use think\Model;

/**
 * 定时任务依赖关系模型
 *
 * 对应表：crontab_task_dependency
 * 定义任务间的编排关系：task_id 依赖 depends_on_task_id 的成功执行。
 */
class TaskDependencyModel extends Model
{
    protected $name = 'crontab_task_dependency';

    public function __construct($data = [])
    {
        parent::__construct($data);
        $this->setName(config('wise-crontab.table_names.task_dependency', $this->name));
    }

    protected $autoWriteTimestamp = false;

    protected $schema = [
        'id'                 => 'int',
        'task_id'            => 'int',
        'depends_on_task_id' => 'int',
        'condition'          => 'string',
    ];
}
