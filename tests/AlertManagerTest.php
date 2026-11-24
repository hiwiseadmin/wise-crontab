<?php

declare(strict_types=1);

namespace wise\crontab\test;

use think\facade\Event;
use wise\crontab\alert\AlertManager;
use wise\crontab\event\CrontabAlertEvent;
use wise\crontab\event\TaskExecuteFailed;

/**
 * 告警管理器测试
 */
class AlertManagerTest extends DatabaseTestCase
{
    /** @var CrontabAlertEvent[] */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $container = \think\Container::getInstance();
        if (!$container->has('event')) {
            $container->bind('event', \think\Event::class);
        }

        $this->sent = [];
        Event::listen(CrontabAlertEvent::class, function (CrontabAlertEvent $event): void {
            $this->sent[] = $event;
        });
    }

    private function failureEvent(string $taskName = 'demo'): TaskExecuteFailed
    {
        return new TaskExecuteFailed($taskName, 'php think demo', 1, 1.5, 'boom', 0);
    }

    public function testDisabledAlertDoesNotSend(): void
    {
        (new AlertManager(['enabled' => false, 'on_failure' => true]))->handleFailure($this->failureEvent());

        $this->assertSame([], $this->sent);
    }

    public function testOnFailureDisabledDoesNotSend(): void
    {
        (new AlertManager(['enabled' => true, 'on_failure' => false]))->handleFailure($this->failureEvent());

        $this->assertSame([], $this->sent);
    }

    public function testFirstFailureSendsAlert(): void
    {
        (new AlertManager(['enabled' => true, 'on_failure' => true, 'throttle' => 300]))
            ->handleFailure($this->failureEvent('first'));

        $this->assertCount(1, $this->sent);
    }

    /**
     * 限流基于执行日志，跨 CLI 进程生效：
     * 当前这次失败已写库（最新一条），倒数第二条在 throttle 内则不重复告警
     */
    public function testThrottleSkipsRecentRepeatFailures(): void
    {
        $this->createLogAt('recent', 'failure', time() - 60);
        $this->createLogAt('recent', 'failure', time());

        (new AlertManager(['enabled' => true, 'on_failure' => true, 'throttle' => 300]))
            ->handleFailure($this->failureEvent('recent'));

        $this->assertSame([], $this->sent, '距上次失败不足 throttle 时应被限流');
    }

    public function testThrottleAllowsAlertAfterInterval(): void
    {
        $this->createLogAt('later', 'failure', time() - 3600);
        $this->createLogAt('later', 'failure', time());

        (new AlertManager(['enabled' => true, 'on_failure' => true, 'throttle' => 300]))
            ->handleFailure($this->failureEvent('later'));

        $this->assertCount(1, $this->sent);
    }

    public function testTimeoutRespectsOnTimeoutFlag(): void
    {
        (new AlertManager(['enabled' => true, 'on_timeout' => false, 'throttle' => 0]))
            ->handleTimeout($this->failureEvent());

        $this->assertSame([], $this->sent);

        (new AlertManager(['enabled' => true, 'on_timeout' => true, 'throttle' => 0]))
            ->handleTimeout($this->failureEvent());

        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('超时', (string) ($this->sent[0]->message['title'] ?? ''));
    }
}
