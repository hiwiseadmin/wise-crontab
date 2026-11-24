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
     * 回归：配置任务 `enabled => false` 必须等价于 status=0（文档承诺）
     */
    public function testConfigTaskDisabledByEnabledFlag(): void
    {
        $this->config['tasks'] = [
            [
                'name'            => 'enabled_false',
                'command'         => 'php think demo',
                'cron_expression' => '* * * * *',
                'enabled'         => false,
            ],
            [
                'name'            => 'enabled_true',
                'command'         => 'php think demo',
                'cron_expression' => '* * * * *',
                'enabled'         => true,
            ],
        ];

        $tasks = (new CrontabManager($this->config))->getTasks();

        $this->assertCount(1, $tasks);
        $this->assertSame('enabled_true', $tasks[0]['name']);
    }

    /**
     * 回归：parallel.wait_all=false 不再「启动完就退出」
     *
     * 旧实现直接 break：子进程仍在跑，父进程退出连带释放 flock / 全局槽位，
     * 日志永久停在 running、success/failure 统计为 0。现在统一为有界等待，
     * 退出前一定收尾。
     */
    public function testParallelNonWaitModeStillFinishesTasks(): void
    {
        if (!self::canSpawnProcess()) {
            $this->markTestSkipped('当前环境无法创建子进程');
        }

        $this->config['parallel'] = [
            'enabled'        => true,
            'max_concurrent' => 5,
            'wait_all'       => false,
            'wait_timeout'   => 10,
        ];
        $this->config['tasks'] = [
            [
                'name'            => 'non_wait_ok',
                'command'         => 'php -r "echo 1;"',
                'cron_expression' => '* * * * *',
                'status'          => 1,
            ],
        ];

        $stats = (new CrontabManager($this->config))->dispatch();

        $this->assertSame(1, $stats['executed']);
        $this->assertSame(1, $stats['success'], '非等待模式也必须在退出前收尾');
        $this->assertSame(0, $stats['failure']);
    }

    /**
     * 测试语义化调度（schedule 字段）被折算为 cron 表达式
     */
    public function testGetTasksNormalizesSemanticSchedule(): void
    {
        $this->config['tasks'] = [
            [
                'name'     => 'semantic_task',
                'title'    => '语义化任务',
                'command'  => 'php think demo:task',
                'schedule' => '每5分钟',
                'status'   => 1,
            ],
        ];

        $manager = new CrontabManager($this->config);
        $tasks = $manager->getTasks();

        $this->assertSame('*/5 * * * *', $tasks[0]['cron_expression']);
        $this->assertSame('cron', $tasks[0]['schedule_type']);
        $this->assertSame('每 5 分钟', $tasks[0]['schedule_description']);
        $this->assertSame([], $manager->getScheduleErrors());
    }

    /**
     * 测试语义化调度支持 L / W / # 语法
     */
    public function testSemanticScheduleSupportsLastWeekday(): void
    {
        $this->config['tasks'] = [
            [
                'name'     => 'monthly_task',
                'title'    => '每月最后一个周五',
                'command'  => 'php think demo:monthly',
                'schedule' => '每月最后一个周五 09:00',
                'status'   => 1,
            ],
        ];

        $upcoming = (new CrontabManager($this->config))->getUpcomingTasks(1);

        $this->assertSame('0 9 * * 5L', $upcoming[0]['task']['cron_expression']);
        $this->assertNotEmpty($upcoming[0]['next_run']);
    }

    /**
     * 测试非法调度表达式会被记录且不参与调度
     */
    public function testInvalidScheduleIsReportedAndSkipped(): void
    {
        $this->config['tasks'] = [
            [
                'name'     => 'broken_task',
                'title'    => '坏任务',
                'command'  => 'php think demo:broken',
                'schedule' => '随便写点什么',
                'status'   => 1,
            ],
        ];

        $manager = new CrontabManager($this->config);
        $tasks = $manager->getTasks();
        $errors = $manager->getScheduleErrors();

        $this->assertSame('', $tasks[0]['cron_expression']);
        $this->assertArrayHasKey('broken_task', $errors);
        $this->assertArrayHasKey('schedule_error', $tasks[0]);
        $this->assertSame([], $manager->getUpcomingTasks());
    }

    /**
     * 测试并行模式下同样执行命令白名单校验（不再绕过安全策略）
     */
    public function testParallelDispatchRespectsCommandWhitelist(): void
    {
        $this->config['parallel'] = [
            'enabled'        => true,
            'max_concurrent' => 5,
            'wait_all'       => true,
            'wait_timeout'   => 5,
        ];
        $this->config['tasks'] = [
            [
                'name'            => 'blocked_parallel',
                'command'         => 'rm -rf /',
                'cron_expression' => '* * * * *',
                'status'          => 1,
            ],
        ];

        $stats = (new CrontabManager($this->config))->dispatch();

        $this->assertSame(0, $stats['executed'], '白名单外的命令不应被启动');
        $this->assertSame(1, $stats['failure']);
    }

    /**
     * 测试并行模式下正常命令可以执行完成（走日志 + 超时检测 + 收尾链路）
     */
    public function testParallelDispatchExecutesAllowedCommand(): void
    {
        if (!self::canSpawnProcess()) {
            $this->markTestSkipped('当前环境无法创建子进程');
        }

        $this->config['parallel'] = [
            'enabled'        => true,
            'max_concurrent' => 5,
            'wait_all'       => true,
            'wait_timeout'   => 10,
        ];
        $this->config['tasks'] = [
            [
                'name'            => 'parallel_ok',
                'command'         => 'php -r "echo 1;"',
                'cron_expression' => '* * * * *',
                'status'          => 1,
            ],
        ];

        $stats = (new CrontabManager($this->config))->dispatch();

        $this->assertSame(1, $stats['executed']);
        $this->assertSame(1, $stats['success']);
        $this->assertSame(0, $stats['failure']);
    }

    /**
     * 测试并行模式下超时任务会被终止并按失败结算
     */
    public function testParallelDispatchTerminatesTimedOutTask(): void
    {
        if (!self::canSpawnProcess()) {
            $this->markTestSkipped('当前环境无法创建子进程');
        }

        $this->config['parallel'] = [
            'enabled'        => true,
            'max_concurrent' => 5,
            'wait_all'       => true,
            'wait_timeout'   => 30,
        ];
        $this->config['tasks'] = [
            [
                'name'            => 'parallel_timeout',
                'command'         => 'php -r "sleep(5);"',
                'cron_expression' => '* * * * *',
                'status'          => 1,
                'timeout'         => 1,
            ],
        ];

        $start = microtime(true);
        $stats = (new CrontabManager($this->config))->dispatch();
        $elapsed = microtime(true) - $start;

        $this->assertSame(1, $stats['executed']);
        $this->assertSame(1, $stats['failure']);
        $this->assertLessThan(5.0, $elapsed, '并行任务应在超时阈值附近被终止');
    }

    /**
     * 测试并行等待超时后仍在运行的任务会被收尾（不停留在 running）
     */
    public function testParallelWaitTimeoutAbandonsRunningTasks(): void
    {
        if (!self::canSpawnProcess()) {
            $this->markTestSkipped('当前环境无法创建子进程');
        }

        $this->config['parallel'] = [
            'enabled'        => true,
            'max_concurrent' => 5,
            'wait_all'       => true,
            'wait_timeout'   => 2,
        ];
        $this->config['tasks'] = [
            [
                'name'            => 'parallel_slow',
                'command'         => 'php -r "sleep(20);"',
                'cron_expression' => '* * * * *',
                'status'          => 1,
                'timeout'         => 60,
            ],
        ];

        $stats = (new CrontabManager($this->config))->dispatch();

        $this->assertSame(1, $stats['executed']);
        $this->assertSame(1, $stats['failure'], '放弃等待的任务应按失败收尾');
        $this->assertSame(0, $stats['success']);
    }

    /**
     * 测试单个任务失败不会中断整轮调度
     */
    public function testDispatchContinuesAfterSingleTaskFailure(): void
    {
        if (!self::canSpawnProcess()) {
            $this->markTestSkipped('当前环境无法创建子进程');
        }

        $this->config['tasks'] = [
            [
                'name'            => 'broken',
                'command'         => 'rm -rf /',
                'cron_expression' => '* * * * *',
                'status'          => 1,
            ],
            [
                'name'            => 'healthy',
                'command'         => 'php -r "echo 1;"',
                'cron_expression' => '* * * * *',
                'status'          => 1,
            ],
        ];

        $stats = (new CrontabManager($this->config))->dispatch();

        $this->assertSame(2, $stats['executed'], '两个任务都应被尝试执行');
        $this->assertSame(1, $stats['failure']);
        $this->assertSame(1, $stats['success']);
    }

    /**
     * 当前环境是否能创建子进程
     */
    private static function canSpawnProcess(): bool
    {
        static $can = null;

        if ($can === null) {
            try {
                $process = \Symfony\Component\Process\Process::fromShellCommandline('echo probe');
                $process->run();
                $can = $process->getExitCode() === 0;
            } catch (\Throwable $e) {
                $can = false;
            }
        }

        return $can;
    }

    /**
     * 测试一次性任务（schedule_type=once）仍按 run_at 判定
     */
    public function testOnceTaskIsKeptAsIs(): void
    {
        $this->config['tasks'] = [
            [
                'name'          => 'once_task',
                'title'         => '一次性任务',
                'command'       => 'php think demo:once',
                'schedule_type' => 'once',
                'run_at'        => date('Y-m-d H:i:s', time() + 3600),
                'status'        => 1,
            ],
        ];

        $manager = new CrontabManager($this->config);
        $tasks = $manager->getTasks();

        $this->assertSame('once', $tasks[0]['schedule_type']);
        $this->assertArrayHasKey('run_at', $tasks[0]);
        $this->assertSame([], $manager->getScheduleErrors());
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
        $this->config['tasks'] = [
            [
                'name'            => 'future_task',
                'title'           => '将来执行的任务',
                'command'         => 'echo future',
                'cron_expression' => '0 0 1 1 0',
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
