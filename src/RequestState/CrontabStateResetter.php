<?php

declare(strict_types=1);

namespace wise\crontab\RequestState;

use wise\support\contracts\RequestStateResetterInterface;

/**
 * wise-crontab 请求级状态重置器
 *
 * 常驻运行时（Swoole / Workerman）下，容器里的 CrontabManager 是单例，
 * 而它持有按「一轮调度」语义设计的实例状态：$scheduleErrors（只增不清）、
 * $windowWarningLogged（一旦置 true 永久静默）。FPM 每次 CLI/请求都是新容器，
 * 天然干净；常驻 worker 里必须显式归零，否则后台「调度错误面板」会出现
 * 上一轮的幽灵报错，窗口故障也再不会被记录。
 *
 * 注册入口：{@see \wise\crontab\CrontabServiceProvider::boot()}
 *
 * @package wise\crontab\RequestState
 */
class CrontabStateResetter implements RequestStateResetterInterface
{
    /**
     * {@inheritDoc}
     */
    public function resetRequestState(): void
    {
        if (!function_exists('app')) {
            return;
        }

        try {
            app(\wise\crontab\CrontabManager::class)->resetPerRunState();
        } catch (\Throwable) {
        }
    }
}
