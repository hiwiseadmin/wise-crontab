<?php
declare(strict_types=1);

namespace wise\crontab\exception;

use wise\support\exception\WiseException;

/**
 * 定时任务异常
 *
 * 错误码：ErrorCode::CRONTAB_ERROR (160000)
 * HTTP：500
 */
class CrontabException extends WiseException
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        $code = $code ?: ErrorCode::CRONTAB_ERROR->value;
        parent::__construct($code, $message, 500, $previous);
    }
}
