<?php
declare(strict_types=1);

namespace wise\crontab\exception;

use wise\support\exception\ErrorCodeInterface;

/**
 * wise-crontab 业务错误码（号段 160000~169999）
 *
 *  160000~160099  定时任务通用
 *  160100~160199  任务锁
 *
 * @package wise\crontab\exception
 */
enum ErrorCode: int implements ErrorCodeInterface
{
    /** 定时任务执行失败 */
    case CRONTAB_ERROR  = 160000;

    /** 任务锁冲突（任务已在执行） */
    case TASK_LOCKED    = 160100;

    public function defaultMessage(): string
    {
        return match ($this) {
            self::CRONTAB_ERROR => '定时任务执行失败',
            self::TASK_LOCKED   => '任务正在执行中',
        };
    }
}
