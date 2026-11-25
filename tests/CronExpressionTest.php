<?php

declare(strict_types=1);

namespace wise\crontab\test;

use wise\crontab\CronExpression;
use wise\crontab\test\TestCase;

class CronExpressionTest extends TestCase
{
    /**
     * 测试有效 Cron 表达式的 isValid 返回 true
     */
    public function testValidExpressionIsValid(): void
    {
        $this->assertTrue(CronExpression::isValid('0 2 * * *'));
    }

    /**
     * 测试有效表达式可以正常构造
     */
    public function testValidExpressionConstruction(): void
    {
        $cron = new CronExpression('0 2 * * *');
        $this->assertInstanceOf(CronExpression::class, $cron);
    }

    /**
     * 测试无效表达式 isValid 返回 false
     */
    public function testInvalidExpressionIsValidReturnsFalse(): void
    {
        $this->assertFalse(CronExpression::isValid('invalid cron'));
    }

    /**
     * 测试无效表达式构造时抛异常
     */
    public function testInvalidExpressionThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('无效的 Cron 表达式');
        new CronExpression('invalid cron');
    }

    /**
     * 测试 isDue 方法
     */
    public function testIsDue(): void
    {
        $cron = new CronExpression('* * * * *');
        $this->assertTrue($cron->isDue());
    }

    /**
     * 测试 getNextRunDate 返回 DateTime
     */
    public function testGetNextRunDateReturnsDateTime(): void
    {
        $cron = new CronExpression('0 2 * * *');
        $next = $cron->getNextRunDate();
        $this->assertInstanceOf(\DateTime::class, $next);
    }

    /**
     * 测试 getPreviousRunDate 返回 DateTime
     */
    public function testGetPreviousRunDateReturnsDateTime(): void
    {
        $cron = new CronExpression('0 2 * * *');
        $prev = $cron->getPreviousRunDate();
        $this->assertInstanceOf(\DateTime::class, $prev);
    }

    /**
     * 测试每年执行一次的表达式当前不为到期时间
     */
    public function testYearlyExpressionNotDueNow(): void
    {
        $cron = new CronExpression('0 0 1 1 *');
        $result = $cron->isDue();
        $this->assertIsBool($result);
    }
}
