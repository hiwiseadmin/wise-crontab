<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

class CreateCrontabTaskDependencyTable extends Migrator
{
    protected function getTable(): string
    {
        return 'crontab_task_dependency';
    }

    /**
     * 创建定时任务依赖关系表
     */
    public function up(): void
    {
        $table = $this->table($this->getTable(), [
            'engine'    => 'InnoDB',
            'comment'   => '定时任务依赖关系表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table->addColumn(Column::integer('task_id')->setComment('任务ID'))
            ->addColumn(Column::integer('depends_on_task_id')->setComment('依赖的任务ID'))
            ->addColumn(Column::string('condition', 20)->setDefault('success')->setComment('依赖条件：success/completed/any'))
            ->addIndex(['task_id', 'depends_on_task_id'], ['unique' => true])
            ->addIndex(['depends_on_task_id'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable($this->getTable())) {
            $this->table($this->getTable())->drop()->save();
        }
    }
}
