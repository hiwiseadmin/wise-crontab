<?php

declare(strict_types=1);

namespace wise\crontab;

/**
 * Cron 表达式解析器（自研实现，零第三方依赖）
 *
 * 取代 dragonmantank/cron-expression，仅保留 wise-crontab 实际需要的 5 段标准语法。
 *
 * 支持的语法：
 *  - 通配 `*`、列表 `,`、范围 `-`、步长 `/`、`?`（等价 `*`，仅日/星期字段可用）
 *  - 月份英文缩写 `JAN`-`DEC`、星期英文缩写 `SUN`-`SAT`
 *  - 星期 `0` 与 `7` 均表示周日
 *  - `L`：日字段 = 当月最后一天；星期字段 = 当月最后一个指定星期（如 `5L`）
 *  - `W`：日字段 = 最接近指定日的工作日（如 `15W`）
 *  - `#`：星期字段 = 当月第 N 个指定星期（如 `5#3`）
 *  - 别名：`@yearly` `@annually` `@monthly` `@weekly` `@daily` `@midnight` `@hourly`
 *
 * 与原库保持一致的行为：
 *  - 仅 5 段表达式（不支持“秒”字段）
 *  - 日字段与星期字段同时被限定时按「或」匹配（标准 cron 语义）
 *  - `isDue()` 先丢弃秒，再判断“当前分钟是否恰好是下一次运行时间”
 *  - `getNextRunDate()` 默认不返回当前这一分钟（`$allowCurrentDate = false`）
 */
class CronExpression
{
    /** 内置别名 */
    public const ALIASES = [
        '@yearly'   => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly'  => '0 0 1 * *',
        '@weekly'   => '0 0 * * 0',
        '@daily'    => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly'   => '0 * * * *',
    ];

    private const MONTH_LITERALS = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
    ];

    /** 星期缩写统一折算为 0=周日 … 6=周六 */
    private const WEEKDAY_LITERALS = [
        'SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6,
    ];

    private const POS_MINUTE  = 0;
    private const POS_HOUR    = 1;
    private const POS_DAY     = 2;
    private const POS_MONTH   = 3;
    private const POS_WEEKDAY = 4;

    private string $expression = '';

    /** @var string[] 归一化后的 5 个原始字段 */
    private array $parts = [];

    /** @var int[] 分钟集合 */
    private array $minutes = [];

    /** @var int[] 小时集合 */
    private array $hours = [];

    /** @var int[] 月份集合 */
    private array $months = [];

    /** @var int[] 日集合（仅普通值；L/W 由匹配器单独处理） */
    private array $days = [];

    /** @var int[] 星期集合（0=周日 … 6=周六） */
    private array $weekdays = [];

    private bool $dayRestricted = false;
    private bool $weekdayRestricted = false;

    private int $maxIterationCount = 1000;

    /**
     * @throws \InvalidArgumentException 表达式非法
     */
    public function __construct(string $expression)
    {
        $this->expression = trim($expression);

        try {
            $this->compile($this->expression);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException("无效的 Cron 表达式: {$expression}", 0, $e);
        }
    }

    /**
     * 校验 Cron 表达式是否有效（含别名）
     */
    public static function isValid(string $expression): bool
    {
        try {
            new self($expression);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 归一化后的表达式（别名已展开为 5 段）
     */
    public function getExpression(): string
    {
        return implode(' ', $this->parts);
    }

    /**
     * 原始表达式（可能是别名）
     */
    public function getRawExpression(): string
    {
        return $this->expression;
    }

    /**
     * 设置最大迭代次数，防止不可能表达式长时间占用 CPU
     */
    public function setMaxIterationCount(int $count): self
    {
        $this->maxIterationCount = max(1, $count);

        return $this;
    }

    /**
     * 判断当前时间是否匹配表达式
     */
    public function isDue(string $time = 'now'): bool
    {
        $base   = self::toDateTime($time);
        $minute = self::truncateToMinute($base);

        try {
            return $this->findRunDate($minute, 0, false, true)->getTimestamp() === $minute->getTimestamp();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 下一次执行时间
     *
     * @param string $time             基准时间（'now' 或可解析的时间字符串）
     * @param int    $nth              跳过的匹配次数（0=最近一次匹配）
     * @param bool   $allowCurrentDate 当前分钟匹配时是否直接返回
     */
    public function getNextRunDate(string $time = 'now', int $nth = 0, bool $allowCurrentDate = false): \DateTime
    {
        return $this->findRunDate(self::truncateToMinute(self::toDateTime($time)), $nth, false, $allowCurrentDate);
    }

    /**
     * 上一次执行时间
     */
    public function getPreviousRunDate(string $time = 'now', int $nth = 0, bool $allowCurrentDate = false): \DateTime
    {
        return $this->findRunDate(self::truncateToMinute(self::toDateTime($time)), $nth, true, $allowCurrentDate);
    }


    /**
     * @throws \InvalidArgumentException
     */
    private function compile(string $expression): void
    {
        $normalized = self::ALIASES[strtolower(trim($expression))] ?? trim($expression);
        $parts      = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($parts) || count($parts) !== 5) {
            throw new \InvalidArgumentException('Cron 表达式必须为 5 个字段');
        }

        if ($parts[self::POS_MINUTE] === '?' || $parts[self::POS_HOUR] === '?' || $parts[self::POS_MONTH] === '?') {
            throw new \InvalidArgumentException('`?` 只能用于日字段或星期字段');
        }
        if ($parts[self::POS_DAY] === '?' && $parts[self::POS_WEEKDAY] === '?') {
            throw new \InvalidArgumentException('日字段与星期字段不能同时为 `?`');
        }

        $this->parts    = $parts;
        $this->minutes  = self::expandField($parts[self::POS_MINUTE], 0, 59, []);
        $this->hours    = self::expandField($parts[self::POS_HOUR], 0, 23, []);
        $this->months   = self::expandField($parts[self::POS_MONTH], 1, 12, self::MONTH_LITERALS);
        $this->weekdays = self::compileWeekday($parts[self::POS_WEEKDAY]);
        $this->days     = self::compileDayOfMonth($parts[self::POS_DAY]);

        $this->dayRestricted     = !in_array($parts[self::POS_DAY], ['*', '?'], true);
        $this->weekdayRestricted = !in_array($parts[self::POS_WEEKDAY], ['*', '?'], true);
    }

    /**
     * 解析日字段（支持 `L`、`15W`，其余按普通集合展开）
     *
     * @return int[]
     *
     * @throws \InvalidArgumentException
     */
    private static function compileDayOfMonth(string $value): array
    {
        $value = trim($value);

        if ($value === 'L') {
            return [];
        }

        if (str_ends_with($value, 'W')) {
            $target = substr($value, 0, -1);
            if (!ctype_digit($target) || (int) $target < 1 || (int) $target > 31) {
                throw new \InvalidArgumentException("非法的 `W` 用法: {$value}");
            }

            return [];
        }

        return self::expandField($value, 1, 31, []);
    }

    /**
     * 解析星期字段（支持 `5L`、`5#3`，其余按普通集合展开并归并 7 => 0）
     *
     * @return int[]
     *
     * @throws \InvalidArgumentException
     */
    private static function compileWeekday(string $value): array
    {
        $value = trim($value);

        if (strlen($value) > 1 && str_ends_with($value, 'L')) {
            $weekday = self::literalToInt(substr($value, 0, -1), self::WEEKDAY_LITERALS);
            if ($weekday === null) {
                throw new \InvalidArgumentException("非法的 `L` 用法: {$value}");
            }

            return [];
        }

        if (str_contains($value, '#')) {
            [$weekdayPart, $nthPart] = explode('#', $value, 2);
            $weekday = self::literalToInt($weekdayPart, self::WEEKDAY_LITERALS, false);
            if ($weekday === null || (int) $nthPart < 1 || (int) $nthPart > 5 || !ctype_digit(trim($nthPart))) {
                throw new \InvalidArgumentException("非法的 `#` 用法: {$value}");
            }

            return [];
        }

        return self::expandField($value, 0, 7, self::WEEKDAY_LITERALS, true);
    }

    /**
     * 展开并校验单个字段
     *
     * @param array<string,int> $literals 文本常量映射（如 JAN => 1）
     * @param bool              $isWeekday 星期字段（需做 7 => 0 归并）
     *
     * @return int[] 升序去重后的取值集合
     *
     * @throws \InvalidArgumentException
     */
    private static function expandField(string $value, int $min, int $max, array $literals, bool $isWeekday = false): array
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException('Cron 字段不能为空');
        }

        if ($value === '*') {
            $range = range($min, $max);
            return self::normalizeValues($range, $isWeekday);
        }

        $result = [];
        foreach (explode(',', $value) as $item) {
            foreach (self::expandItem(trim($item), $min, $max, $literals) as $v) {
                $result[] = $v;
            }
        }

        return self::normalizeValues($result, $isWeekday);
    }

    /**
     * 展开单个列表项（不含逗号）
     *
     * @return int[]
     *
     * @throws \InvalidArgumentException
     */
    private static function expandItem(string $item, int $min, int $max, array $literals): array
    {
        if ($item === '') {
            throw new \InvalidArgumentException('Cron 字段存在空列表项');
        }

        if ($item === '*') {
            return range($min, $max);
        }

        if (str_contains($item, '/')) {
            $chunks = explode('/', $item, 2);
            $range  = trim($chunks[0]);
            $step   = trim($chunks[1] ?? '');

            if ($range === '' || !ctype_digit($step) || (int) $step < 1) {
                throw new \InvalidArgumentException("非法的步长: {$item}");
            }
            if (is_numeric($range)) {
                throw new \InvalidArgumentException("步长前不能是单个数字: {$item}");
            }

            $step = (int) $step;

            if ($range === '*') {
                $rangeStart = $min;
                $rangeEnd   = $max;
            } else {
                [$rangeStart, $rangeEnd] = self::parseRange($range, $min, $max, $literals);
            }

            if ($step > $max) {
                $full = range($min, $max);
                return [$full[$step % count($full)]];
            }
            if ($step > ($rangeEnd - $rangeStart)) {
                return [$rangeStart];
            }

            return range($rangeStart, $rangeEnd, $step);
        }

        if (str_contains($item, '-')) {
            [$start, $end] = self::parseRange($item, $min, $max, $literals);

            return range($start, $end);
        }

        $single = self::toInt($item, $literals);
        if ($single === null || $single < $min || $single > $max) {
            throw new \InvalidArgumentException("非法取值: {$item}");
        }

        return [$single];
    }

    /**
     * 解析 `a-b` 区间
     *
     * @return array{0:int,1:int}
     *
     * @throws \InvalidArgumentException
     */
    private static function parseRange(string $value, int $min, int $max, array $literals): array
    {
        if (substr_count($value, '-') !== 1) {
            throw new \InvalidArgumentException("非法的区间: {$value}");
        }

        [$rawStart, $rawEnd] = explode('-', $value, 2);
        $rawStart = trim($rawStart);
        $rawEnd   = trim($rawEnd);

        if ($rawStart === '*' || $rawEnd === '*' || $rawStart === '' || $rawEnd === '') {
            throw new \InvalidArgumentException("非法的区间: {$value}");
        }

        $start = self::toInt($rawStart, $literals);
        $end   = self::toInt($rawEnd, $literals);

        if ($start === null || $end === null || $start < $min || $start > $max || $end < $min || $end > $max) {
            throw new \InvalidArgumentException("区间越界: {$value}");
        }
        if ($start > $end) {
            throw new \InvalidArgumentException("区间起点大于终点: {$value}");
        }

        return [$start, $end];
    }

    /**
     * 文本常量/数字转 int，无法转换返回 null
     */
    private static function toInt(string $value, array $literals): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (!empty($literals)) {
            $upper = strtoupper($value);
            if (isset($literals[$upper])) {
                return $literals[$upper];
            }
        }

        if (!ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * 星期字段归并（7 => 0）并升序去重
     *
     * @param int[] $values
     *
     * @return int[]
     */
    private static function normalizeValues(array $values, bool $isWeekday): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = (int) $value;
            if ($isWeekday) {
                $value %= 7;
            }
            $result[$value] = $value;
        }

        ksort($result);

        return array_values($result);
    }


    /**
     * 查找运行时间
     *
     * @throws \RuntimeException 表达式不可能被满足
     */
    private function findRunDate(\DateTime $base, int $nth, bool $invert, bool $allowCurrentDate): \DateTime
    {
        $cursor    = clone $base;
        $anchor    = clone $base;
        $remaining = $nth;

        for ($i = 0; $i < $this->maxIterationCount; ++$i) {
            if (!in_array((int) $cursor->format('n'), $this->months, true)) {
                $this->advanceMonth($cursor, $invert);
                continue;
            }

            if (!$this->dayMatches($cursor)) {
                $this->advanceDay($cursor, $invert);
                continue;
            }

            if (!in_array((int) $cursor->format('G'), $this->hours, true)) {
                $this->advanceHour($cursor, $invert);
                continue;
            }

            if (!in_array((int) $cursor->format('i'), $this->minutes, true)) {
                $this->advanceMinute($cursor, $invert);
                continue;
            }

            if (!$allowCurrentDate && $cursor->getTimestamp() === $anchor->getTimestamp()) {
                $this->advanceMinute($cursor, $invert);
                continue;
            }

            if ($remaining-- > 0) {
                $this->advanceMinute($cursor, $invert);
                continue;
            }

            return $cursor;
        }

        throw new \RuntimeException('Impossible CRON expression: ' . $this->getExpression());
    }

    /**
     * 日字段匹配（日与星期同时限定时为「或」）
     */
    private function dayMatches(\DateTime $date): bool
    {
        $dayOk = !$this->dayRestricted || $this->matchDayOfMonth($date);
        $dowOk = !$this->weekdayRestricted || $this->matchDayOfWeek($date);

        if ($this->dayRestricted && $this->weekdayRestricted) {
            return $dayOk || $dowOk;
        }

        return $dayOk && $dowOk;
    }

    private function matchDayOfMonth(\DateTime $date): bool
    {
        $value = $this->parts[self::POS_DAY];

        if ($value === '?' || $value === '*') {
            return true;
        }

        if ($value === 'L') {
            return $date->format('j') === $date->format('t');
        }

        if (str_ends_with($value, 'W')) {
            $nearest = self::nearestWeekday($date, (int) substr($value, 0, -1));

            return $nearest !== null && (int) $date->format('j') === $nearest;
        }

        return in_array((int) $date->format('j'), $this->days, true);
    }

    private function matchDayOfWeek(\DateTime $date): bool
    {
        $value = $this->parts[self::POS_WEEKDAY];

        if ($value === '?' || $value === '*') {
            return true;
        }

        if (strlen($value) > 1 && str_ends_with($value, 'L')) {
            $weekday     = self::literalToInt(substr($value, 0, -1), self::WEEKDAY_LITERALS);
            $daysInMonth = (int) $date->format('t');
            $dayOfMonth  = (int) $date->format('j');

            return $weekday !== null
                && (int) $date->format('w') === $weekday
                && ($daysInMonth - $dayOfMonth) < 7;
        }

        if (str_contains($value, '#')) {
            [$weekdayPart, $nthPart] = explode('#', $value, 2);
            $weekday = self::literalToInt($weekdayPart, self::WEEKDAY_LITERALS);
            $nth     = (int) $nthPart;

            if ($weekday === null || (int) $date->format('w') !== $weekday) {
                return false;
            }

            return intdiv((int) $date->format('j') - 1, 7) + 1 === $nth;
        }

        return in_array((int) $date->format('w'), $this->weekdays, true);
    }

    private static function literalToInt(string $value, array $literals): ?int
    {
        $upper = strtoupper(trim($value));
        if (isset($literals[$upper])) {
            return $literals[$upper];
        }

        if (!ctype_digit($upper)) {
            return null;
        }

        $number = (int) $upper;
        if ($number < 0 || $number > 7) {
            return null;
        }

        return $number % 7;
    }

    /**
     * 最接近指定日的工作日（不跨月边界）
     */
    private static function nearestWeekday(\DateTime $date, int $targetDay): ?int
    {
        $daysInMonth = (int) $date->format('t');
        if ($targetDay < 1 || $targetDay > $daysInMonth) {
            return null;
        }

        foreach ([0, -1, 1, -2, 2] as $offset) {
            $candidate = $targetDay + $offset;
            if ($candidate < 1 || $candidate > $daysInMonth) {
                continue;
            }

            $probe = (clone $date)->setDate((int) $date->format('Y'), (int) $date->format('n'), $candidate);
            if ((int) $probe->format('N') < 6) {
                return $candidate;
            }
        }

        return null;
    }


    private function advanceMonth(\DateTime $date, bool $invert): void
    {
        if ($invert) {
            $date->modify('last day of previous month');
            $this->resetTime($date, false);
            return;
        }

        $date->modify('first day of next month');
        $this->resetTime($date, true);
    }

    private function advanceDay(\DateTime $date, bool $invert): void
    {
        $date->modify($invert ? '-1 day' : '+1 day');
        $this->resetTime($date, !$invert);
    }

    private function advanceHour(\DateTime $date, bool $invert): void
    {
        $current = (int) $date->format('G');

        if ($invert) {
            $candidates = array_filter($this->hours, static fn (int $h): bool => $h < $current);
            if (!empty($candidates)) {
                $date->setTime((int) max($candidates), 59, 0);
                return;
            }

            $date->modify('-1 day');
            $date->setTime((int) max($this->hours), 59, 0);
            return;
        }

        $candidates = array_filter($this->hours, static fn (int $h): bool => $h > $current);
        if (!empty($candidates)) {
            $date->setTime((int) min($candidates), 0, 0);
            return;
        }

        $date->modify('+1 day');
        $date->setTime((int) min($this->hours), 0, 0);
    }

    private function advanceMinute(\DateTime $date, bool $invert): void
    {
        $date->modify($invert ? '-1 minute' : '+1 minute');
        $date->setTime((int) $date->format('G'), (int) $date->format('i'), 0);
    }

    /**
     * 按天/月推进后，将时间贴近当日可命中的最早/最晚时刻，减少无效迭代
     */
    private function resetTime(\DateTime $date, bool $forward): void
    {
        if ($forward) {
            $date->setTime((int) min($this->hours), (int) min($this->minutes), 0);
            return;
        }

        $date->setTime((int) max($this->hours), (int) max($this->minutes), 0);
    }

    private static function toDateTime(string $time): \DateTime
    {
        if ($time === '' || $time === 'now') {
            return new \DateTime('now');
        }

        return new \DateTime($time);
    }

    private static function truncateToMinute(\DateTime $date): \DateTime
    {
        $truncated = \DateTime::createFromFormat('!Y-m-d H:i', $date->format('Y-m-d H:i'), $date->getTimezone());
        if ($truncated === false) {
            $truncated = clone $date;
            $truncated->setTime((int) $date->format('H'), (int) $date->format('i'), 0);
        }

        return $truncated;
    }
}
