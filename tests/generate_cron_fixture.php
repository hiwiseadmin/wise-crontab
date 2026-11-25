<?php
declare(strict_types=1);

/**
 * 【开发期脚本】用 dragonmantank/cron-expression 生成黄金用例，并与自研实现逐项比对。
 *
 * 用法：php tests/generate_cron_fixture.php
 *  - 全部一致 → 写出 tests/fixtures/cron_golden.php
 *  - 存在差异 → 打印差异明细并以非 0 退出
 *
 * 依赖 dragonmantank/cron-expression，故仅在依赖移除前使用；
 * 依赖移除后由 CronExpressionGoldenTest 读取 fixture 断言。
 */

$packageDir = dirname(__DIR__);
$srcDir     = $packageDir . '/src';
$vendorDir  = dirname($packageDir, 3) . '/vendor';
$autoload   = $vendorDir . '/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "未找到 vendor/autoload.php: {$autoload}\n");
    exit(1);
}

$loader = require $autoload;
$loader->addPsr4('wise\\crontab\\', $srcDir . '/');

if (!class_exists(\Cron\CronExpression::class)) {
    fwrite(STDERR, "dragonmantank/cron-expression 未安装，无法生成黄金用例\n");
    exit(1);
}

/**
 * 已知且刻意的行为差异（原库自身缺陷，自研实现按标准语义修正，不纳入对照）：
 *  - `0 0 31W * *`：原库在无 31 号的月份会因 DateTime 溢出而产生错误结果（会命中下月 1/2/3 号）；
 *    自研实现在无 31 号月份不触发（与 Quartz 语义一致）。
 *  - `0 0 * FEB-DEC/3 *`：原库对「文本常量区间 + 步长」会抛 RuntimeException（内部把 'FEB' 当数字运算）；
 *    自研实现可正确展开为 2/5/8/11 月。
 */
$knownDivergence = [
    '0 0 31W * *',
    '0 0 * FEB-DEC/3 *',
];

$expressions = [
    '* * * * *',
    '*/5 * * * *',
    '*/15 9-17 * * 1-5',
    '15,45 */6 * * *',
    '0 * * * *',
    '0 0 * * *',
    '0 2 * * *',
    '30 2 * * *',
    '5 0 * 8 *',
    '0 0 1 * *',
    '0 0 1,15 * *',
    '0 0 1-7/2 * *',
    '0 0 L * *',
    '0 0 L 2 *',
    '0 0 15W * *',
    '0 0 1W * *',
    '0 0 * FEB-MAR *',
    '0 0 * * 0',
    '0 0 * * 7',
    '0 0 * * SUN',
    '0 0 * * 1-5',
    '0 0 * * 1,3,5',
    '0 0 * * */2',
    '0 0 * * 5L',
    '0 0 * * 7L',
    '0 0 * * 5#3',
    '0 0 * * 1#1',
    '0 0 * JAN,JUL *',
    '0 0 * * MON-FRI',
    '0 0 1 1 *',
    '0 0 29 2 *',
    '0 0 31 1 *',
    '0 0 30 2 *',
    '@daily',
    '@hourly',
    '@weekly',
    '@monthly',
    '@yearly',
    '@midnight',
    '@annually',
    '0 9-18 * * 1-5',
    '0 0 1 1 0',
];

$times = [
    '2026-09-18 10:23:00',
    '2026-01-01 00:00:00',
    '2026-02-28 23:59:00',
    '2024-02-29 12:00:00',
    '2025-12-31 23:59:00',
    '2026-03-01 00:00:00',
    '2026-06-15 09:30:00',
    '2026-11-30 23:58:00',
    '2027-01-01 00:00:00',
    '2026-05-31 12:00:00',
    '2026-08-31 23:00:00',
];

$invalid = [
    'invalid cron',
    '* * * *',
    '* * * * * *',
    '60 * * * *',
    '* 24 * * *',
    '* * 32 * *',
    '* * * 13 *',
    '* * * * 8',
    '? * * * *',
    '* ? * * *',
    '* * * ? *',
    '* * ? * ?',
    '5/10 * * * *',
    '*/0 * * * *',
    '1-2-3 * * * *',
    '*-5 * * * *',
    '* * 1,15L * *',
    '* * * * L',
    '* * * * 5#6',
    '* * * * 5#0',
    '',
];

$fixture   = [
    'expressions'     => $expressions,
    'times'           => $times,
    'invalid'         => $invalid,
    'knownDivergence' => $knownDivergence,
    'cases'           => [],
    'invalidCases'    => [],
];
$mismatch  = 0;
$mismatchDetail = [];

foreach ($invalid as $expr) {
    $expected = \Cron\CronExpression::isValidExpression($expr);
    $actual   = \wise\crontab\CronExpression::isValid($expr);
    $fixture['invalidCases'][$expr] = $expected;

    if ($expected !== $actual) {
        $mismatch++;
        $mismatchDetail[] = sprintf(
            "[isValid] %-20s vendor=%s ours=%s",
            $expr,
            var_export($expected, true),
            var_export($actual, true)
        );
    }
}

foreach ($expressions as $expr) {
    $vendor = new \Cron\CronExpression($expr);
    $ours   = new \wise\crontab\CronExpression($expr);

    foreach ($times as $time) {
        $case = ['expr' => $expr, 'time' => $time];

        $case['isDue'] = $vendor->isDue($time);
        $actual = $ours->isDue($time);
        if ($case['isDue'] !== $actual) {
            $mismatch++;
            $mismatchDetail[] = sprintf('[isDue] %-18s @ %s vendor=%s ours=%s', $expr, $time, var_export($case['isDue'], true), var_export($actual, true));
        }

        try {
            $case['next'] = $vendor->getNextRunDate($time)->format('Y-m-d H:i:s');
            $actualNext   = $ours->getNextRunDate($time)->format('Y-m-d H:i:s');
            if ($case['next'] !== $actualNext) {
                $mismatch++;
                $mismatchDetail[] = sprintf('[next] %-18s @ %s vendor=%s ours=%s', $expr, $time, $case['next'], $actualNext);
            }
        } catch (\Throwable $e) {
            $case['next'] = 'ERR';
            try {
                $ours->getNextRunDate($time);
                $mismatch++;
                $mismatchDetail[] = sprintf('[next] %-18s @ %s vendor threw, ours ok', $expr, $time);
            } catch (\Throwable $e2) {
            }
        }

        try {
            $case['nextCurrent'] = $vendor->getNextRunDate($time, 0, true)->format('Y-m-d H:i:s');
            $actualNextCurrent   = $ours->getNextRunDate($time, 0, true)->format('Y-m-d H:i:s');
            if ($case['nextCurrent'] !== $actualNextCurrent) {
                $mismatch++;
                $mismatchDetail[] = sprintf('[nextCurrent] %-18s @ %s vendor=%s ours=%s', $expr, $time, $case['nextCurrent'], $actualNextCurrent);
            }
        } catch (\Throwable $e) {
            $case['nextCurrent'] = 'ERR';
        }

        try {
            $case['next2'] = $vendor->getNextRunDate($time, 1)->format('Y-m-d H:i:s');
            $actualNext2   = $ours->getNextRunDate($time, 1)->format('Y-m-d H:i:s');
            if ($case['next2'] !== $actualNext2) {
                $mismatch++;
                $mismatchDetail[] = sprintf('[next2] %-18s @ %s vendor=%s ours=%s', $expr, $time, $case['next2'], $actualNext2);
            }
        } catch (\Throwable $e) {
            $case['next2'] = 'ERR';
        }

        try {
            $case['prev'] = $vendor->getPreviousRunDate($time)->format('Y-m-d H:i:s');
            $actualPrev   = $ours->getPreviousRunDate($time)->format('Y-m-d H:i:s');
            if ($case['prev'] !== $actualPrev) {
                $mismatch++;
                $mismatchDetail[] = sprintf('[prev] %-18s @ %s vendor=%s ours=%s', $expr, $time, $case['prev'], $actualPrev);
            }
        } catch (\Throwable $e) {
            $case['prev'] = 'ERR';
        }

        try {
            $case['prevCurrent'] = $vendor->getPreviousRunDate($time, 0, true)->format('Y-m-d H:i:s');
            $actualPrevCurrent   = $ours->getPreviousRunDate($time, 0, true)->format('Y-m-d H:i:s');
            if ($case['prevCurrent'] !== $actualPrevCurrent) {
                $mismatch++;
                $mismatchDetail[] = sprintf('[prevCurrent] %-18s @ %s vendor=%s ours=%s', $expr, $time, $case['prevCurrent'], $actualPrevCurrent);
            }
        } catch (\Throwable $e) {
            $case['prevCurrent'] = 'ERR';
        }

        $fixture['cases'][] = $case;
    }
}

echo '表达式: ' . count($expressions) . ' × 时间点: ' . count($times) . ' = ' . count($fixture['cases']) . " 组\n";
echo '非法表达式校验: ' . count($invalid) . " 个\n";

if ($mismatch > 0) {
    echo "\n发现 {$mismatch} 处差异：\n";
    foreach (array_slice($mismatchDetail, 0, 60) as $line) {
        echo '  - ' . $line . "\n";
    }
    if (count($mismatchDetail) > 60) {
        echo '  ... 共 ' . count($mismatchDetail) . " 处\n";
    }
    exit(1);
}

$fixtureDir = $packageDir . '/tests/fixtures';
if (!is_dir($fixtureDir) && !mkdir($fixtureDir, 0777, true) && !is_dir($fixtureDir)) {
    fwrite(STDERR, "无法创建目录: {$fixtureDir}\n");
    exit(1);
}

$php = "<?php\n\n"
    . "declare(strict_types=1);\n\n"
    . "/**\n"
    . " * Cron 黄金用例（由 tests/generate_cron_fixture.php 基于 dragonmantank/cron-expression 生成）\n"
    . " *\n"
    . " * 该 fixture 是自研 CronExpression 的行为基线，请勿手工修改。\n"
    . " */\n\n"
    . 'return ' . var_export($fixture, true) . ";\n";

file_put_contents($fixtureDir . '/cron_golden.php', $php);

echo "\n全部一致，黄金用例已写入 tests/fixtures/cron_golden.php\n";
exit(0);
