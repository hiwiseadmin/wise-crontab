<?php
declare(strict_types=1);

namespace wise\crontab\exception;

use wise\support\exception\WiseException;

/**
 * 任务锁异常
 *
 * 当任务已在执行（未获取到锁）时抛出。
 *
 * 错误码：ErrorCode::TASK_LOCKED (160100)
 * HTTP：409
 */
class TaskLockException extends WiseException
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        $code = $code ?: ErrorCode::TASK_LOCKED->value;
        parent::__construct($code, $message, 409, $previous);
    }
}
