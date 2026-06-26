<?php

declare(strict_types=1);

namespace wise\crontab;

use think\facade\Event;
use wise\crontab\trait\ServiceTrait;
use wise\crontab\command\Publish;
use wise\crontab\command\Run;
use wise\crontab\command\Clean;
use wise\crontab\command\Stats;
use wise\crontab\event\CrontabAlertEvent;

class Service extends \think\Service
{
    use ServiceTrait;

    public function register(): void
    {
        $this->mergeConfig('wise-crontab');

        $this->app->bind(CrontabManager::class, function () {
            return new CrontabManager($this->app->config->get('wise-crontab', []));
        });
    }

    public function boot(): void
    {
        // 注册告警事件监听 — AlertManager 通过 Event::trigger 发送 CrontabAlertEvent
        Event::listen(CrontabAlertEvent::class, function (CrontabAlertEvent $event) {
            // 可由宿主应用覆盖此监听器，接入邮件/钉钉/企微等通知渠道
            // 如: \app\common\service\Notifier::sendCrontabAlert($event);
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                Publish::class,
                Run::class,
                Clean::class,
                Stats::class,
            ]);
        }
    }
}
