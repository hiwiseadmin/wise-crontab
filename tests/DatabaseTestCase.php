<?php

declare(strict_types=1);

namespace wise\crontab\test;

use think\DbManager;
use think\Model;
use wise\crontab\model\TaskLogModel;
use wise\crontab\model\TaskModel;

/**
 * 需要数据库的测试基类
 *
 * 使用临时 SQLite 文件库（结构与包内迁移一致），测试结束后清理，
 * 并把 Db 配置还原为不可用状态，避免影响其它不需要数据库的用例。
 */
abstract class DatabaseTestCase extends TestCase
{
    protected string $sqliteFile = '';

    protected DbManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sqliteFile = sys_get_temp_dir() . '/wise-crontab-test-' . uniqid() . '.sqlite';

        $this->manager = new DbManager();
        $this->manager->setConfig($this->sqliteConfig());
        Model::setDb($this->manager);

        $this->createSchema();
    }

    /**
     * 临时 SQLite 配置
     */
    private function sqliteConfig(): array
    {
        return [
            'default'     => 'sqlite',
            'connections' => [
                'sqlite' => [
                    'type'     => 'sqlite',
                    'database' => $this->sqliteFile,
                    'prefix'   => '',
                ],
            ],
        ];
    }

    protected function tearDown(): void
    {
        if ($this->sqliteFile !== '' && is_file($this->sqliteFile)) {
            @unlink($this->sqliteFile);
        }

        Model::setDb(new DbManager());

        parent::tearDown();
    }

    /**
     * 建表（字段与 database/migrations 保持一致）
     */
    private function createSchema(): void
    {
        $db = $this->manager->connect('sqlite', true);

        foreach (['crontab_task_log', 'crontab_task_dependency', 'crontab_maintenance_window', 'crontab_task'] as $table) {
            $db->execute('DROP TABLE IF EXISTS ' . $table);
        }

        $db->execute('CREATE TABLE crontab_task (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            title TEXT,
            description TEXT,
            command TEXT,
            cron_expression TEXT,
            schedule TEXT,
            schedule_type TEXT DEFAULT "cron",
            run_at TEXT,
            executed_at TEXT,
            next_run_at TEXT,
            status INTEGER DEFAULT 1,
            timeout INTEGER DEFAULT 300,
            max_retries INTEGER DEFAULT 0,
            allow_concurrent INTEGER DEFAULT 0,
            source TEXT DEFAULT "database",
            create_time TEXT,
            update_time TEXT
        )');

        $db->execute('CREATE TABLE crontab_task_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER DEFAULT 0,
            task_name TEXT,
            status TEXT,
            output TEXT,
            error_output TEXT,
            exit_code INTEGER,
            start_time TEXT,
            end_time TEXT,
            duration REAL DEFAULT 0,
            retry_count INTEGER DEFAULT 0,
            process_id INTEGER,
            create_time TEXT
        )');

        $db->execute('CREATE TABLE crontab_task_dependency (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            depends_on_task_id INTEGER NOT NULL,
            condition TEXT DEFAULT "success"
        )');

        $db->execute('CREATE TABLE crontab_maintenance_window (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER,
            start_at TEXT,
            end_at TEXT,
            days_of_week TEXT DEFAULT "*",
            reason TEXT
        )');
    }

    /**
     * 创建测试任务
     */
    protected function createTask(array $overrides = []): array
    {
        return TaskModel::create(array_merge([
            'name'            => 'task_' . uniqid(),
            'title'           => '测试任务',
            'command'         => 'php think demo',
            'cron_expression' => '* * * * *',
            'schedule_type'   => 'cron',
            'status'          => 1,
        ], $overrides))->toArray();
    }

    /**
     * 直接写入执行日志（可精确指定 create_time，模型的自动时间戳会覆盖该字段）
     */
    protected function createLogAt(string $taskName, string $status, int $timestamp): void
    {
        $this->manager->connect('sqlite')->table('crontab_task_log')->insert([
            'task_id'      => 0,
            'task_name'    => $taskName,
            'status'       => $status,
            'output'       => '',
            'error_output' => '',
            'exit_code'    => $status === 'success' ? 0 : 1,
            'duration'     => 1.0,
            'retry_count'  => 0,
            'start_time'   => date('Y-m-d H:i:s', $timestamp),
            'create_time'  => date('Y-m-d H:i:s', $timestamp),
        ]);
    }

    /**
     * 创建执行日志
     */
    protected function createLog(array $overrides = []): array
    {
        return TaskLogModel::create(array_merge([
            'task_id'      => 0,
            'task_name'    => 'demo',
            'status'       => 'success',
            'output'       => '',
            'error_output' => '',
            'exit_code'    => 0,
            'duration'     => 1.0,
            'retry_count'  => 0,
            'start_time'   => date('Y-m-d H:i:s'),
        ], $overrides))->toArray();
    }
}
