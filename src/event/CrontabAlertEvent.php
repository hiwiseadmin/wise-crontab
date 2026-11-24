<?php

declare(strict_types=1);

namespace wise\crontab\event;

/**
 * 定时任务告警事件
 *
 * 由 AlertManager 触发，宿主应用可监听此事件接入邮件/钉钉/企微等通知渠道。
 */
class CrontabAlertEvent
{
    public function __construct(
        public readonly array $message
    ) {
    }
}
