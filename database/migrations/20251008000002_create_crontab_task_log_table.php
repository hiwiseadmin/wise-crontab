<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

class CreateCrontabTaskLogTable extends Migrator
{
    protected function getTable(): string
    {
        return 'crontab_task_log';
    }

    /**
     * 创建定时任务执行日志表
     */
    public function up(): void
    {
        $table = $this->table($this->getTable(), [
            'engine' => 'InnoDB',
            'comment' => '定时任务执行日志表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table->addColumn(Column::integer('task_id')->setComment('任务ID'))
            ->addColumn(Column::string('task_name', 100)->setComment('任务名称'))
            ->addColumn(Column::string('status', 20)->setDefault('running')->setComment('执行状态：running/success/failure/skipped'))
            ->addColumn(Column::text('output')->setNull(true)->setComment('标准输出'))
            ->addColumn(Column::text('error_output')->setNull(true)->setComment('错误输出'))
            ->addColumn(Column::integer('exit_code')->setNull(true)->setComment('退出码'))
            ->addColumn(Column::dateTime('start_time')->setComment('开始时间'))
            ->addColumn(Column::dateTime('end_time')->setNull(true)->setComment('结束时间'))
            ->addColumn(Column::decimal('duration', 10, 4)->setNull(true)->setComment('执行耗时（秒）'))
            ->addColumn(Column::integer('retry_count')->setDefault(0)->setComment('重试次数'))
            ->addColumn(Column::integer('process_id')->setNull(true)->setComment('进程ID'))
            ->addColumn(Column::dateTime('create_time')->setNull(true)->setComment('创建时间'))
            ->addIndex(['task_id'])
            ->addIndex(['task_name'])
            ->addIndex(['status'])
            ->addIndex(['create_time'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable($this->getTable())) {
            $this->table($this->getTable())->drop()->save();
        }
    }
}
