<?php

declare(strict_types=1);

namespace wise\crontab\test;

use wise\crontab\Schedule;
use wise\crontab\test\TestCase;

/**
 * 语义化调度表达式测试（中英双语）
 */
class ScheduleTest extends TestCase
{
    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public static function cronCases(): array
    {
        return [
            'every minute'                 => ['every minute', '* * * * *'],
            'every 5 minutes'              => ['every 5 minutes', '*/5 * * * *'],
            'every 30 minutes between'     => ['every 30 minutes between 09:00 and 18:00', '*/30 9-18 * * *'],
            'every hour'                   => ['every hour', '0 * * * *'],
            'hourly'                       => ['hourly', '0 * * * *'],
            'every 2 hours'                => ['every 2 hours', '0 */2 * * *'],
            'daily'                        => ['daily', '0 0 * * *'],
            'daily at 02:30'               => ['daily at 02:30', '30 2 * * *'],
            'every day at 02:30'           => ['every day at 02:30', '30 2 * * *'],
            'weekdays at 09:00'            => ['weekdays at 09:00', '0 9 * * 1-5'],
            'weekly on monday'             => ['weekly on monday at 08:00', '0 8 * * 1'],
            'every friday at 18:30'        => ['every friday at 18:30', '30 18 * * 5'],
            'monthly on 1st'               => ['monthly on 1st at 00:00', '0 0 1 * *'],
            'monthly on the last day'      => ['monthly on the last day at 23:00', '0 23 L * *'],
            'monthly on the third friday'  => ['monthly on the third friday at 09:00', '0 9 * * 5#3'],
            'monthly on the last friday'   => ['monthly on the last friday at 18:00', '0 18 * * 5L'],
            'yearly'                       => ['yearly', '0 0 1 1 *'],

            '每分钟'          => ['每分钟', '* * * * *'],
            '每5分钟'         => ['每5分钟', '*/5 * * * *'],
            '每 10 分钟'      => ['每 10 分钟', '*/10 * * * *'],
            '每小时'          => ['每小时', '0 * * * *'],
            '每2小时'         => ['每2小时', '0 */2 * * *'],
            '每天凌晨2点'      => ['每天凌晨2点', '0 2 * * *'],
            '每天上午9点30分'  => ['每天上午9点30分', '30 9 * * *'],
            '每天晚上8点'      => ['每天晚上8点', '0 20 * * *'],
            '每天'            => ['每天', '0 0 * * *'],
            '工作日9点'        => ['工作日9点', '0 9 * * 1-5'],
            '每个工作日 09:30' => ['每个工作日 09:30', '30 9 * * 1-5'],
            '每周一8点'        => ['每周一8点', '0 8 * * 1'],
            '每周一多日'       => ['每周一、周三、周五 9点', '0 9 * * 1,3,5'],
            '每周日'          => ['每周日', '0 0 * * 0'],
            '每30分钟区间'     => ['每30分钟 从9点到18点', '*/30 9-18 * * *'],
            '每月1号凌晨'      => ['每月1号凌晨', '0 0 1 * *'],
            '每月15号'        => ['每月15号 10:30', '30 10 15 * *'],
            '每月最后一天'     => ['每月最后一天 23:00', '0 23 L * *'],
            '每月最后一个周五'  => ['每月最后一个周五 18:00', '0 18 * * 5L'],
            '每月第三个周五'    => ['每月第三个周五 09:00', '0 9 * * 5#3'],
            '每月15号工作日'    => ['每月15号最近的工作日 09:00', '0 9 15W * *'],
            '每年'            => ['每年', '0 0 1 1 *'],
            '每年3月15日'      => ['每年3月15日 10:00', '0 10 15 3 *'],

            '@daily'            => ['@daily', '0 0 * * *'],
            '@hourly'           => ['@hourly', '0 * * * *'],
            '原生表达式'         => ['*/5 * * * *', '*/5 * * * *'],
            'L 表达式'          => ['0 0 L * *', '0 0 L * *'],
            'W 表达式'          => ['0 9 15W * *', '0 9 15W * *'],
            '# 表达式'          => ['0 9 * * 5#3', '0 9 * * 5#3'],
            '别名带空格'         => ['  @daily  ', '0 0 * * *'],
        ];
    }

    /**
     * @dataProvider cronCases
     */
    public function testToCron(string $input, string $expected): void
    {
        $this->assertSame($expected, Schedule::toCron($input));
    }

    /**
     * @dataProvider cronCases
     */
    public function testParseReturnsCronType(string $input, string $expected): void
    {
        $parsed = Schedule::parse($input);

        $this->assertSame(Schedule::TYPE_CRON, $parsed['schedule_type']);
        $this->assertSame($expected, $parsed['cron_expression']);
        $this->assertNotSame('', $parsed['description']);
    }

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public static function onceCases(): array
    {
        return [
            'once at 英文'   => ['once at 2026-09-20 10:00', '2026-09-20 10:00:00'],
            'at 英文'        => ['at 2026-09-20 10:00', '2026-09-20 10:00:00'],
            '裸日期时间'      => ['2026-09-20 10:00', '2026-09-20 10:00:00'],
            '中文日期'        => ['2026-09-20 10:00 执行一次', '2026-09-20 10:00:00'],
            '中文月日'        => ['9月20日 10:00 执行一次', '2026-09-20 10:00:00'],
        ];
    }

    /**
     * @dataProvider onceCases
     */
    public function testParseOnce(string $input, string $expected): void
    {
        $parsed = Schedule::parse($input, '2026-09-01 00:00');

        $this->assertSame(Schedule::TYPE_ONCE, $parsed['schedule_type']);
        $this->assertSame($expected, $parsed['run_at']);
        $this->assertStringContainsString('仅执行一次', $parsed['description']);
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function invalidCases(): array
    {
        return [
            '空字符串'      => [''],
            '随便写'        => ['随便写点什么'],
            '仅时刻'        => ['at 2:30'],
            '字段不足'      => ['* * * *'],
            '日超界'        => ['每月32号'],
            '小时超界'      => ['每天25点'],
            '未知星期'      => ['每周八 9点'],
            '分钟步长越界'   => ['每61分钟 从9点到18点'],
            '小时区间倒置'   => ['每30分钟 从18点到9点'],
        ];
    }

    /**
     * @dataProvider invalidCases
     */
    public function testInvalidInputThrows(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Schedule::parse($input);
    }

    public function testIsValid(): void
    {
        $this->assertTrue(Schedule::isValid('每5分钟'));
        $this->assertTrue(Schedule::isValid('0 2 * * *'));
        $this->assertFalse(Schedule::isValid('随便写点什么'));
    }

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public static function describeCases(): array
    {
        return [
            '每分钟'          => ['* * * * *', '每分钟'],
            '每5分钟'         => ['*/5 * * * *', '每 5 分钟'],
            '每小时整点'       => ['0 * * * *', '每小时整点'],
            '每2小时'         => ['0 */2 * * *', '每 2 小时的 00 分'],
            '每天'            => ['0 2 * * *', '每天 02:00'],
            '工作日'          => ['0 9 * * 1-5', '工作日 09:00'],
            '每周一'          => ['0 8 * * 1', '每周一 08:00'],
            '每周多日'         => ['0 9 * * 1,3,5', '每周一、三、五 09:00'],
            '每月某号'         => ['0 0 1 * *', '每月 1 号 00:00'],
            '每月最后一天'     => ['0 23 L * *', '每月最后一天 23:00'],
            '每月第N个周X'     => ['0 9 * * 5#3', '每月第3个周五 09:00'],
            '每月最后一个周X'   => ['0 18 * * 5L', '每月最后一个周五 18:00'],
            '每月某号工作日'    => ['0 9 15W * *', '每月 15 号最近的工作日 09:00'],
            '每年'            => ['0 0 1 1 *', '每年 1 月 1 号 00:00'],
            '别名'            => ['@daily', '每天 00:00'],
        ];
    }

    /**
     * @dataProvider describeCases
     */
    public function testDescribe(string $expression, string $expected): void
    {
        $this->assertSame($expected, Schedule::describe($expression));
    }

    public function testDescribeNeverThrows(): void
    {
        $this->assertSame('not a cron', Schedule::describe('not a cron'));
        $this->assertSame('', Schedule::describe(''));
    }

    public function testNormalizeTaskFromSemanticSchedule(): void
    {
        $task = Schedule::normalizeTask([
            'name'     => '演示任务',
            'schedule' => '每5分钟',
        ]);

        $this->assertSame('*/5 * * * *', $task['cron_expression']);
        $this->assertSame(Schedule::TYPE_CRON, $task['schedule_type']);
        $this->assertSame('每 5 分钟', $task['schedule_description']);
    }

    public function testNormalizeTaskFromCronExpression(): void
    {
        $task = Schedule::normalizeTask([
            'name'            => '旧任务',
            'cron_expression' => '0 2 * * *',
        ]);

        $this->assertSame('0 2 * * *', $task['cron_expression']);
        $this->assertSame(Schedule::TYPE_CRON, $task['schedule_type']);
        $this->assertSame('每天 02:00', $task['schedule_description']);
    }

    public function testNormalizeTaskOnceOverridesSchedule(): void
    {
        $task = Schedule::normalizeTask([
            'name'     => '一次性任务',
            'schedule' => 'once at 2026-09-20 10:00',
        ]);

        $this->assertSame(Schedule::TYPE_ONCE, $task['schedule_type']);
        $this->assertSame('2026-09-20 10:00:00', $task['run_at']);
        $this->assertArrayNotHasKey('cron_expression', $task);
    }

    public function testNormalizeTaskKeepsExistingOnceTask(): void
    {
        $task = Schedule::normalizeTask([
            'name'          => '数据库一次性任务',
            'schedule_type' => 'once',
            'run_at'        => '2026-09-20 10:00:00',
        ]);

        $this->assertSame('once', $task['schedule_type']);
        $this->assertSame('2026-09-20 10:00:00', $task['run_at']);
        $this->assertStringContainsString('仅执行一次', $task['schedule_description']);
    }

    public function testNormalizeTaskWithoutScheduleIsUntouched(): void
    {
        $task = Schedule::normalizeTask(['name' => '无调度']);

        $this->assertSame(['name' => '无调度'], $task);
    }

    public function testNormalizeTaskThrowsOnInvalidSchedule(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Schedule::normalizeTask(['name' => '坏任务', 'schedule' => '随便写点什么']);
    }
}
