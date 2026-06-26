<?php

declare(strict_types=1);

namespace wise\crontab\test;

use wise\crontab\CrontabManager;
use wise\crontab\test\TestCase;

class CrontabManagerTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = [
            'tasks'              => [],
            'table_task'         => 'crontab_task',
            'table_task_log'     => 'crontab_task_log',
            'default_timeout'    => 300,
            'default_max_retries' => 0,
            'log_retention_days' => 30,
            'lock_dir'           => '',
            'lock_ttl'           => 3600,
            'output_max_bytes'   => 65536,
        ];
    }

    /**
     * 测试 getTasks 返回配置文件中的任务
     */
    public function testGetTasksReturnsConfigTasks(): void
    {
        $this->config['tasks'] = [
            [
                'name'            => 'test_task',
                'title'           => '测试任务',
                'command'         => 'echo hello',
                'cron_expression' => '* * * * *',
                'status'          => 1,
            ],
        ];

        $manager = new CrontabManager($this->config);
        $tasks = $manager->getTasks();

        $this->assertCount(1, $tasks);
        $this->assertEquals('test_task', $tasks[0]['name']);
        $this->assertEquals('config', $tasks[0]['source']);
    }

    /**
     * 测试 getTasks 过滤 status=0 的禁用任务
     */
    public function testGetTasksFiltersDisabledTasks(): void
    {
        $this->config['tasks'] = [
            [
                'name'            => 'enabled_task',
                'title'           => '启用任务',
                'command'         => 'echo hello',
                'cron_expression' => '* * * * *',
                'status'          => 1,
            ],
            [
                'name'            => 'disabled_task',
                'title'           => '禁用任务',
                'command'         => 'echo world',
                'cron_expression' => '* * * * *',
                'status'          => 0,
            ],
        ];

        $manager = new CrontabManager($this->config);
        $tasks = $manager->getTasks();

        $this->assertCount(1, $tasks);
        $this->assertEquals('enabled_task', $tasks[0]['name']);
    }

    /**
     * 测试 getUpcomingTasks 返回排序后的任务列表
     */
    public function testGetUpcomingTasksReturnsSortedList(): void
    {
        $this->config['tasks'] = [
            [
                'name'            => 'hourly_task',
                'title'           => '每小时任务',
                'command'         => 'echo hourly',
                'cron_expression' => '0 * * * *',
                'status'          => 1,
            ],
        ];

        $manager = new CrontabManager($this->config);
        $upcoming = $manager->getUpcomingTasks(5);

        $this->assertIsArray($upcoming);
        $this->assertNotEmpty($upcoming);
        $this->assertArrayHasKey('task', $upcoming[0]);
        $this->assertArrayHasKey('next_run', $upcoming[0]);
        $this->assertArrayHasKey('timestamp', $upcoming[0]);
    }

    /**
     * 测试 getUpcomingTasks 遵守 limit 参数
     */
    public function testGetUpcomingTasksRespectsLimit(): void
    {
        $this->config['tasks'] = [
            [
                'name'            => 'task_a',
                'title'           => '任务A',
                'command'         => 'echo a',
                'cron_expression' => '* * * * *',
                'status'          => 1,
            ],
            [
                'name'            => 'task_b',
                'title'           => '任务B',
                'command'         => 'echo b',
                'cron_expression' => '0 * * * *',
                'status'          => 1,
            ],
        ];

        $manager = new CrontabManager($this->config);
        $upcoming = $manager->getUpcomingTasks(1);

        $this->assertCount(1, $upcoming);
    }

    /**
     * 测试 dispatch 返回正确的统计结构
     */
    public function testDispatchReturnsCorrectStatsStructure(): void
    {
        // 使用不会到期的表达式，确保没有任务被执行
        $this->config['tasks'] = [
            [
                'name'            => 'future_task',
                'title'           => '将来执行的任务',
                'command'         => 'echo future',
                'cron_expression' => '0 0 1 1 0', // 每年1月1日周日（几乎不匹配）
                'status'          => 1,
            ],
        ];

        $manager = new CrontabManager($this->config);
        $stats = $manager->dispatch();

        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('executed', $stats);
        $this->assertArrayHasKey('success', $stats);
        $this->assertArrayHasKey('failure', $stats);
        $this->assertArrayHasKey('skipped', $stats);
        $this->assertIsInt($stats['total']);
        $this->assertIsInt($stats['executed']);
    }
}
