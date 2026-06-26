<?php

declare(strict_types=1);

namespace wise\crontab\test;

use wise\crontab\TaskExecutor;
use wise\crontab\TaskLock;
use wise\crontab\test\TestCase;

class TaskExecutorTest extends TestCase
{
    private array $config;
    private string $lockDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise-crontab-test-' . uniqid();
        $this->config = [
            'default_timeout'    => 30,
            'default_max_retries' => 0,
            'output_max_bytes'   => 65536,
        ];
    }

    protected function tearDown(): void
    {
        // 清理测试锁目录
        $this->removeDir($this->lockDir);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * 测试成功执行简单命令
     */
    public function testExecuteSimpleCommandSuccessfully(): void
    {
        $executor = new TaskExecutor($this->config);
        $lock = new TaskLock($this->lockDir, 3600);

        $task = [
            'id'              => 1,
            'name'            => 'test_echo',
            'command'         => 'echo hello',
            'cron_expression' => '* * * * *',
            'allow_concurrent' => 0,
            'max_retries'     => 0,
            'timeout'         => 30,
        ];

        $result = $executor->execute($task, $lock);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals(0, $result['exit_code']);
        $this->assertStringContainsString('hello', $result['output']);
        $this->assertEquals(0, $result['retry_count']);
        $this->assertGreaterThan(0, $result['duration']);
    }

    /**
     * 测试失败命令获取到 failure 状态
     */
    public function testExecuteFailureCommand(): void
    {
        $executor = new TaskExecutor($this->config);
        $lock = new TaskLock($this->lockDir, 3600);

        $task = [
            'id'              => 2,
            'name'            => 'test_fail',
            'command'         => 'exit 1',
            'cron_expression' => '* * * * *',
            'allow_concurrent' => 0,
            'max_retries'     => 0,
            'timeout'         => 30,
        ];

        $result = $executor->execute($task, $lock);

        $this->assertEquals('failure', $result['status']);
        $this->assertNotEquals(0, $result['exit_code']);
        $this->assertEquals(0, $result['retry_count']);
    }

    /**
     * 测试输出截断
     */
    public function testOutputTruncation(): void
    {
        // 设置很小的截断阈值
        $this->config['output_max_bytes'] = 10;

        $executor = new TaskExecutor($this->config);
        $lock = new TaskLock($this->lockDir, 3600);

        $task = [
            'id'              => 3,
            'name'            => 'test_truncate',
            'command'         => 'echo "this is a very long output string"',
            'cron_expression' => '* * * * *',
            'allow_concurrent' => 0,
            'max_retries'     => 0,
            'timeout'         => 30,
        ];

        $result = $executor->execute($task, $lock);

        $this->assertEquals('success', $result['status']);
        // 输出应被截断并包含提示信息
        $this->assertStringContainsString('已截断', $result['output']);
    }

    /**
     * 测试并发限制（allow_concurrent=0，同一任务第二次执行被跳过）
     */
    public function testConcurrentLockPreventsDuplicateExecution(): void
    {
        $executor = new TaskExecutor($this->config);
        $lock = new TaskLock($this->lockDir, 3600);

        $task = [
            'id'              => 4,
            'name'            => 'test_concurrent',
            'command'         => 'echo running',
            'cron_expression' => '* * * * *',
            'allow_concurrent' => 0,
            'max_retries'     => 0,
            'timeout'         => 30,
        ];

        // 先获取锁但不释放
        $this->assertTrue($lock->acquire('test_concurrent'));

        // 第二次执行应被跳过
        $result = $executor->execute($task, $lock);

        $this->assertEquals('skipped', $result['status']);
    }
}
