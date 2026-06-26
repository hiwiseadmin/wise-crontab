<?php

declare(strict_types=1);

namespace wise\crontab;

use Cron\CronExpression as CronExpr;

class CronExpression
{
    private CronExpr $inner;

    public function __construct(string $expression)
    {
        if (!self::isValid($expression)) {
            throw new \InvalidArgumentException("无效的 Cron 表达式: {$expression}");
        }
        $this->inner = new CronExpr($expression);
    }

    /**
     * 判断当前时间是否匹配 Cron 表达式
     */
    public function isDue(string $time = 'now'): bool
    {
        return $this->inner->isDue($time);
    }

    /**
     * 获取下一次执行时间
     */
    public function getNextRunDate(string $time = 'now'): \DateTime
    {
        return $this->inner->getNextRunDate($time);
    }

    /**
     * 获取上一次执行时间
     */
    public function getPreviousRunDate(string $time = 'now'): \DateTime
    {
        return $this->inner->getPreviousRunDate($time);
    }

    /**
     * 验证 Cron 表达式是否有效
     */
    public static function isValid(string $expression): bool
    {
        return CronExpr::isValidExpression($expression);
    }
}
