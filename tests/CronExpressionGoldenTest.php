<?php

declare(strict_types=1);

namespace wise\crontab\test;

use wise\crontab\CronExpression;
use wise\crontab\test\TestCase;

/**
 * 黄金用例回归测试
 *
 * fixture 由 tests/generate_cron_fixture.php 基于 dragonmantank/cron-expression 生成，
 * 用于保证自研解析器与原库行为完全一致（含 L/W/# 与 DOM+DOW 的「或」语义）。
 */
class CronExpressionGoldenTest extends TestCase
{
    /** @var array<string,mixed> */
    private static array $golden = [];

    public static function setUpBeforeClass(): void
    {
        self::$golden = require __DIR__ . '/fixtures/cron_golden.php';
    }

    public function testFixtureIsLoaded(): void
    {
        $this->assertNotEmpty(self::$golden['cases']);
        $this->assertSame(42, count(self::$golden['expressions']));
    }

    /**
     * 非法表达式判定与原库一致
     */
    public function testInvalidExpressionsMatchBaseline(): void
    {
        foreach (self::$golden['invalidCases'] as $expression => $expected) {
            $this->assertSame(
                $expected,
                CronExpression::isValid((string) $expression),
                "isValid 判定不一致: {$expression}"
            );
        }
    }

    public function testIsDueMatchesBaseline(): void
    {
        foreach (self::$golden['cases'] as $case) {
            $cron = new CronExpression($case['expr']);

            $this->assertSame(
                $case['isDue'],
                $cron->isDue($case['time']),
                "isDue 不一致: {$case['expr']} @ {$case['time']}"
            );
        }
    }

    public function testNextRunDateMatchesBaseline(): void
    {
        $this->assertRunDateMatchesBaseline('next', false, 0, false);
    }

    public function testNextRunDateAllowCurrentMatchesBaseline(): void
    {
        $this->assertRunDateMatchesBaseline('nextCurrent', false, 0, true);
    }

    public function testSecondNextRunDateMatchesBaseline(): void
    {
        $this->assertRunDateMatchesBaseline('next2', false, 1, false);
    }

    public function testPreviousRunDateMatchesBaseline(): void
    {
        $this->assertRunDateMatchesBaseline('prev', true, 0, false);
    }

    public function testPreviousRunDateAllowCurrentMatchesBaseline(): void
    {
        $this->assertRunDateMatchesBaseline('prevCurrent', true, 0, true);
    }

    /**
     * @param string $key               fixture 字段名
     * @param bool   $previous          true=调用 getPreviousRunDate
     * @param int    $nth              跳过次数
     * @param bool   $allowCurrentDate 是否允许当前分钟
     */
    private function assertRunDateMatchesBaseline(string $key, bool $previous, int $nth, bool $allowCurrentDate): void
    {
        foreach (self::$golden['cases'] as $case) {
            $cron     = new CronExpression($case['expr']);
            $expected = $case[$key];

            if ($expected === 'ERR') {
                $this->expectException(\RuntimeException::class);

                if ($previous) {
                    $cron->getPreviousRunDate($case['time'], $nth, $allowCurrentDate);
                } else {
                    $cron->getNextRunDate($case['time'], $nth, $allowCurrentDate);
                }

                return;
            }

            $actual = $previous
                ? $cron->getPreviousRunDate($case['time'], $nth, $allowCurrentDate)
                : $cron->getNextRunDate($case['time'], $nth, $allowCurrentDate);

            $this->assertSame(
                $expected,
                $actual->format('Y-m-d H:i:s'),
                "{$key} 不一致: {$case['expr']} @ {$case['time']}"
            );
        }
    }


    /**
     * `31W`：原库在无 31 号的月份会因 DateTime 溢出命中下月 1/2/3 号；
     * 自研实现按 Quartz 语义，仅在有 31 号的月份触发。
     */
    public function testLastDayWeekdayDoesNotOverflowIntoNextMonth(): void
    {
        $cron = new CronExpression('0 0 31W * *');

        $this->assertSame('2026-08-31 00:00:00', $cron->getNextRunDate('2026-08-30 12:00')->format('Y-m-d H:i:s'));

        $this->assertSame('2026-10-30 00:00:00', $cron->getNextRunDate('2026-08-31 23:00')->format('Y-m-d H:i:s'));

        $this->assertSame('2027-03-31 00:00:00', $cron->getNextRunDate('2027-02-01 00:00')->format('Y-m-d H:i:s'));
    }

    /**
     * 文本常量区间 + 步长：原库会抛 RuntimeException，自研可正确展开
     */
    public function testLiteralRangeWithStepIsSupported(): void
    {
        $cron = new CronExpression('0 0 * FEB-DEC/3 *');

        $this->assertSame('2026-11-01 00:00:00', $cron->getNextRunDate('2026-09-18 10:23')->format('Y-m-d H:i:s'));
        $this->assertSame('2027-02-01 00:00:00', $cron->getNextRunDate('2026-12-01 00:00')->format('Y-m-d H:i:s'));
    }


    public function testAliasIsExpandedToFiveFields(): void
    {
        $this->assertSame('0 0 * * *', (new CronExpression('@daily'))->getExpression());
        $this->assertSame('@daily', (new CronExpression('@daily'))->getRawExpression());
    }

    public function testImpossibleExpressionThrowsRuntimeException(): void
    {
        $cron = new CronExpression('0 0 30 2 *');

        $this->expectException(\RuntimeException::class);
        $cron->getNextRunDate('2026-01-01 00:00');
    }

    public function testIsDueIsFalseForImpossibleExpression(): void
    {
        $this->assertFalse((new CronExpression('0 0 30 2 *'))->isDue('2026-01-01 00:00'));
    }

    public function testInvalidExpressionMessageIsStable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('无效的 Cron 表达式');

        new CronExpression('not a cron');
    }

    public function testMaxIterationCountCanBeTuned(): void
    {
        $cron = (new CronExpression('0 0 29 2 *'))->setMaxIterationCount(3);

        $this->expectException(\RuntimeException::class);
        $cron->getNextRunDate('2026-03-01 00:00');
    }

    public function testDayOfWeekSupportsSevenAsSunday(): void
    {
        $seven = new CronExpression('0 0 * * 7');
        $zero  = new CronExpression('0 0 * * 0');

        $this->assertSame('2026-09-20 00:00:00', $seven->getNextRunDate('2026-09-19 00:00')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-20 00:00:00', $zero->getNextRunDate('2026-09-19 00:00')->format('Y-m-d H:i:s'));
    }

    public function testDayOfMonthAndDayOfWeekAreOrCombined(): void
    {
        $cron = new CronExpression('0 0 15 * 1');

        $this->assertSame('2026-09-21 00:00:00', $cron->getNextRunDate('2026-09-16 00:00')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-05 00:00:00', $cron->getNextRunDate('2026-10-01 00:00')->format('Y-m-d H:i:s'));
    }
}
