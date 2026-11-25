<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

class CreateCrontabTaskTable extends Migrator
{
    protected function getTable(): string
    {
        return 'crontab_task';
    }

    /**
     * 创建定时任务定义表
     */
    public function up(): void
    {
        $table = $this->table($this->getTable(), [
            'engine' => 'InnoDB',
            'comment' => '定时任务定义表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table->addColumn(Column::string('name', 100)->setComment('任务名称'))
            ->addColumn(Column::string('title', 200)->setComment('任务标题'))
            ->addColumn(Column::string('description', 500)->setNull(true)->setComment('任务描述'))
            ->addColumn(Column::string('command', 500)->setComment('执行命令'))
            ->addColumn(Column::string('cron_expression', 100)->setComment('Cron 表达式'))
            ->addColumn(Column::string('schedule', 100)->setNullable()->setComment('语义化调度表达式（优先于 cron_expression）'))
            ->addColumn(Column::string('schedule_type', 20)->setDefault('cron')->setComment('调度类型：cron/once'))
            ->addColumn(Column::dateTime('run_at')->setNull(true)->setComment('一次性任务执行时间（schedule_type=once时必填）'))
            ->addColumn(Column::dateTime('executed_at')->setNull(true)->setComment('实际执行时间'))
            ->addColumn(Column::datetime('next_run_at')->setNullable()->setComment('下次执行时间（调度器回写）'))
            ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态：0禁用 1启用'))
            ->addColumn(Column::integer('timeout')->setDefault(300)->setComment('超时时间（秒）'))
            ->addColumn(Column::integer('max_retries')->setDefault(0)->setComment('最大重试次数'))
            ->addColumn(Column::tinyInteger('allow_concurrent')->setDefault(0)->setComment('允许并发：0否 1是'))
            ->addColumn(Column::string('source', 20)->setDefault('database')->setComment('任务来源'))
            ->addColumn(Column::dateTime('create_time')->setNull(true)->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setNull(true)->setComment('更新时间'))
            ->addIndex(['name'], ['unique' => true])
            ->addIndex(['status'])
            ->addIndex(['schedule_type'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable($this->getTable())) {
            $this->table($this->getTable())->drop()->save();
        }
    }
}
