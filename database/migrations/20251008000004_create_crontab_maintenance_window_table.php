<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

class CreateCrontabMaintenanceWindowTable extends Migrator
{
    protected function getTable(): string
    {
        return 'crontab_maintenance_window';
    }

    /**
     * 创建维护窗口表
     */
    public function up(): void
    {
        $table = $this->table($this->getTable(), [
            'engine'    => 'InnoDB',
            'comment'   => '定时任务维护窗口表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table->addColumn(Column::integer('task_id')->setNull(true)->setComment('任务ID（NULL=全局窗口）'))
            ->addColumn(Column::time('start_at')->setComment('开始时间'))
            ->addColumn(Column::time('end_at')->setComment('结束时间'))
            ->addColumn(Column::string('days_of_week', 20)->setDefault('*')->setComment('星期：1-5,0,6 或 *'))
            ->addColumn(Column::string('reason', 200)->setNull(true)->setComment('维护原因'))
            ->addIndex(['task_id'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable($this->getTable())) {
            $this->table($this->getTable())->drop()->save();
        }
    }
}
