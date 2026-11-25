<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建分布式锁表 crontab_lock（供 lock_driver=database 使用）
 *
 * 表结构与 wise-support 的 DistributedLock 完全一致（lock_key/task_name/create_time/expires_at），
 * 预先建表可避免运行时动态建表所需的 DDL 权限，也便于 DBA 审核。
 *
 * 时间列与 DistributedLock::ensureDbTable() 的动态建表 DDL 保持一致：
 * - expires_at：**无符号 INT**（Unix 秒），参与 `WHERE expires_at < $now` 的绝对时刻比较，
 *   无符号可避免有符号 INT 在 2038-01-19 之后溢出（上限延至 2106-02-07）；
 * - create_time：`datetime`，与框架时间字段约定一致。
 *
 * 表名不含前缀，think-migration 会按 database.prefix 自动补全（当前为 `ws_crontab_lock`），
 * 与运行时代码的 `Db::name('crontab_lock')` 口径一致。
 */
class CreateCrontabLockTable extends Migrator
{
    protected function getTable(): string
    {
        return 'crontab_lock';
    }

    public function up(): void
    {
        $table = $this->table($this->getTable());

        if ($table->exists()) {
            return;
        }

        $table->addColumn(Column::string('lock_key', 64)->setNull(false)->setComment('锁键（MD5）'))
              ->addColumn(Column::string('task_name', 255)->setNull(false)->setComment('任务名称'))
              ->addColumn(Column::datetime('create_time')->setNull(false)->setComment('创建时间'))
              ->addColumn(Column::integer('expires_at')->setUnsigned()->setNull(false)->setComment('过期时间戳（Unix 秒）'))
              ->addIndex(['lock_key'], ['unique' => true, 'name' => 'uk_lock_key'])
              ->addIndex(['expires_at'], ['name' => 'idx_expires_at'])
              ->create();
    }

    public function down(): void
    {
        if ($this->hasTable($this->getTable())) {
            $this->table($this->getTable())->drop()->save();
        }
    }
}
