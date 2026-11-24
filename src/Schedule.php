<?php

declare(strict_types=1);

namespace wise\crontab;

/**
 * 语义化调度表达式（中英双语）
 *
 * 让使用者不必记忆「星号斜杠 5」这类 cron 格式，直接书写自然语言即可：
 *
 *   Schedule::parse('每5分钟');                    // 每 5 分钟
 *   Schedule::parse('every 5 minutes');            // 每 5 分钟
 *   Schedule::parse('每天凌晨2点');                 // 每天 02:00
 *   Schedule::parse('工作日9点');                   // 工作日 09:00
 *   Schedule::parse('每周一8点');                   // 每周一 08:00
 *   Schedule::parse('每月最后一天 23:00');          // 每月最后一天 23:00
 *   Schedule::parse('每月第三个周五 09:00');        // 每月第 3 个周五 09:00
 *   Schedule::parse('每月15号最近的工作日 09:00');  // 每月 15 号最近的工作日 09:00
 *   Schedule::parse('once at 2026-09-20 10:00');   // 仅执行一次（2026-09-20 10:00:00）
 *   Schedule::parse('@daily');                     // 原生 cron / 别名原样透传
 *
 * 返回值中的 `cron_expression` 为归一化后的 5 段 cron，`description` 为中文描述。
 * 原生 5 段 cron 表达式（含 `@daily` 等别名）会被识别并归一化后透传，保证向后兼容。
 */
final class Schedule
{
    public const TYPE_CRON = 'cron';
    public const TYPE_ONCE = 'once';

    private const CN_WEEKDAY = ['日' => 0, '天' => 0, '一' => 1, '二' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6];

    private const EN_WEEKDAY = [
        'sunday' => 0, 'sun' => 0,
        'monday' => 1, 'mon' => 1,
        'tuesday' => 2, 'tue' => 2, 'tues' => 2,
        'wednesday' => 3, 'wed' => 3,
        'thursday' => 4, 'thu' => 4, 'thur' => 4, 'thurs' => 4,
        'friday' => 5, 'fri' => 5,
        'saturday' => 6, 'sat' => 6,
    ];

    private const EN_ORDINAL = [
        'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5,
        '1st' => 1, '2nd' => 2, '3rd' => 3, '4th' => 4, '5th' => 5,
    ];

    private const CN_ORDINAL = ['一' => 1, '二' => 2, '三' => 3, '四' => 4, '五' => 5];

    /** 时间前缀 → 需要加的小时偏移（12 表示下午/晚上） */
    private const TIME_PREFIX = [
        '凌晨' => 0, '清晨' => 0, '早上' => 0, '早晨' => 0, '上午' => 0, '深夜' => 0,
        '中午' => 12, '下午' => 12, '傍晚' => 12, '晚上' => 12, '夜里' => 12, '晚' => 12,
    ];

    private function __construct()
    {
    }

    /**
     * 解析调度表达式
     *
     * @param string $input 语义化文本或原生 cron 表达式
     * @param string $now   基准时间（用于相对时间描述）
     *
     * @return array{schedule_type:string, cron_expression?:string, run_at?:string, expression:string, description:string}
     *
     * @throws \InvalidArgumentException 无法识别
     */
    public static function parse(string $input, string $now = 'now'): array
    {
        $raw = trim($input);
        if ($raw === '') {
            throw new \InvalidArgumentException('调度表达式不能为空');
        }

        if (CronExpression::isValid($raw)) {
            $cron = (new CronExpression($raw))->getExpression();

            return [
                'schedule_type'   => self::TYPE_CRON,
                'cron_expression' => $cron,
                'expression'      => $raw,
                'description'     => self::describe($cron),
            ];
        }

        $text = self::normalize($raw);

        $onceText = self::matchOnce($text);
        if ($onceText !== null && self::isDateTimeLike($onceText)) {
            $timestamp = self::parseDateTime($onceText, $now);
            if ($timestamp !== null) {
                return [
                    'schedule_type' => self::TYPE_ONCE,
                    'run_at'        => date('Y-m-d H:i:s', $timestamp),
                    'expression'    => $raw,
                    'description'   => '仅执行一次（' . date('Y-m-d H:i:s', $timestamp) . '）',
                ];
            }
        }

        $cron = self::matchCronText($text);
        if ($cron !== null) {
            return [
                'schedule_type'   => self::TYPE_CRON,
                'cron_expression' => $cron,
                'expression'      => $raw,
                'description'     => self::describe($cron),
            ];
        }

        throw new \InvalidArgumentException("无法识别的调度表达式: {$input}");
    }

    /**
     * 语义化表达式 → cron（一次性任务会抛异常）
     *
     * @throws \InvalidArgumentException
     */
    public static function toCron(string $input): string
    {
        $parsed = self::parse($input);

        if ($parsed['schedule_type'] === self::TYPE_ONCE) {
            throw new \InvalidArgumentException("一次性任务无法转换为 cron 表达式: {$input}");
        }

        return (string) $parsed['cron_expression'];
    }

    /**
     * 表达式是否可解析
     */
    public static function isValid(string $input): bool
    {
        try {
            self::parse($input);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 归一化任务定义：把 `schedule`（语义化）折算为 `cron_expression` / `run_at` + `schedule_type`
     *
     * 优先级：`schedule_type=once` + `run_at` > `schedule` > `cron_expression`
     *
     * @throws \InvalidArgumentException
     */
    public static function normalizeTask(array $task): array
    {
        if (($task['schedule_type'] ?? null) === self::TYPE_ONCE && !empty($task['run_at'])) {
            $task['schedule_description'] = '仅执行一次（' . $task['run_at'] . '）';

            return $task;
        }

        $input = trim((string) ($task['schedule'] ?? ''));
        if ($input === '') {
            $input = trim((string) ($task['cron_expression'] ?? ''));
        }
        if ($input === '') {
            return $task;
        }

        $parsed = self::parse($input);

        if ($parsed['schedule_type'] === self::TYPE_ONCE) {
            $task['schedule_type'] = self::TYPE_ONCE;
            $task['run_at']        = $parsed['run_at'];
        } else {
            $task['schedule_type']   = self::TYPE_CRON;
            $task['cron_expression'] = $parsed['cron_expression'];
        }

        $task['schedule_description'] = $parsed['description'];

        return $task;
    }

    /**
     * cron 表达式 → 中文描述（用于后台展示 / MCP 输出，永不抛异常）
     */
    public static function describe(string $expression): string
    {
        $raw = trim($expression);
        if ($raw === '') {
            return '';
        }

        if (isset(CronExpression::ALIASES[strtolower($raw)])) {
            $raw = CronExpression::ALIASES[strtolower($raw)];
        }

        if (!CronExpression::isValid($raw)) {
            return $raw;
        }

        $cron = (new CronExpression($raw))->getExpression();
        [$minute, $hour, $dom, $month, $dow] = explode(' ', $cron);

        $when = self::describeTime($minute, $hour);
        $date = self::describeDate($dom, $month, $dow);

        if ($date === '每天' && str_starts_with($when, '每')) {
            return $when;
        }

        return trim($date . ' ' . $when);
    }


    /**
     * 按类别依次尝试，返回 cron 表达式
     */
    private static function matchCronText(string $text): ?string
    {
        foreach (['matchHourly', 'matchInterval', 'matchWeekdays', 'matchWeekly', 'matchDaily', 'matchMonthly', 'matchYearly'] as $method) {
            $cron = self::$method($text);
            if ($cron !== null) {
                return $cron;
            }
        }

        return null;
    }

    private static function matchHourly(string $text): ?string
    {
        if (preg_match('/^(?:every\s+hour|hourly|每(?:个)?小时|每小时一次)$/', $text)) {
            return '0 * * * *';
        }

        if (preg_match('/^every\s+(\d+)\s*hours?$/', $text, $m)) {
            return sprintf('0 */%d * * *', self::assertStep((int) $m[1], 23, $text));
        }

        if (preg_match('/^每\s*(\d+)\s*(?:个)?小时$/u', $text, $m)) {
            return sprintf('0 */%d * * *', self::assertStep((int) $m[1], 23, $text));
        }

        return null;
    }

    /**
     * 校验步长范围，避免「每 61 分钟」这类无效配置被静默接受
     *
     * @throws \InvalidArgumentException
     */
    private static function assertStep(int $step, int $max, string $text): int
    {
        if ($step < 1 || $step > $max) {
            throw new \InvalidArgumentException("步长必须在 1-{$max} 之间: {$text}");
        }

        return $step;
    }

    private static function matchInterval(string $text): ?string
    {
        if (preg_match('/^every\s+(\d+)\s*(?:minutes?|mins?)\s+between\s+(\d{1,2})(?::\d{2})?\s+and\s+(\d{1,2})(?::\d{2})?$/', $text, $m)) {
            $step = self::assertStep((int) $m[1], 59, $text);
            $from = (int) $m[2];
            $to   = (int) $m[3];
            if ($from > 23 || $to > 23 || $from > $to) {
                throw new \InvalidArgumentException('小时区间非法: ' . $text);
            }

            return sprintf('*/%d %d-%d * * *', $step, $from, $to);
        }

        if (preg_match('/^每\s*(\d+)\s*分钟\s*(?:在|从)\s*(\d{1,2})\s*点\s*(?:到|至|-)\s*(\d{1,2})\s*点$/u', $text, $m)) {
            $step = self::assertStep((int) $m[1], 59, $text);
            $from = (int) $m[2];
            $to   = (int) $m[3];
            if ($from > 23 || $to > 23 || $from > $to) {
                throw new \InvalidArgumentException('小时区间非法: ' . $text);
            }

            return sprintf('*/%d %d-%d * * *', $step, $from, $to);
        }

        if (preg_match('/^(?:every\s+(\d+)\s*(?:minutes?|mins?)|每\s*(\d+)\s*分钟)$/u', $text, $m)) {
            $step = self::assertStep((int) ($m[1] !== '' ? $m[1] : $m[2]), 59, $text);

            return $step === 1 ? '* * * * *' : sprintf('*/%d * * * *', $step);
        }

        if (preg_match('/^(?:every\s+minute|每(?:个)?分钟|每分钟)$/', $text)) {
            return '* * * * *';
        }

        return null;
    }

    private static function matchWeekdays(string $text): ?string
    {
        $rest = null;
        if (preg_match('/^weekdays(?:\s+at\s+(.+))?$/', $text, $m)) {
            $rest = $m[1] ?? '';
        } elseif (preg_match('/^(?:每个)?工作日(.*)$/u', $text, $m)) {
            $rest = trim($m[1]);
        } else {
            return null;
        }

        [$hour, $minute] = self::parseOptionalTime($rest);

        return sprintf('%d %d * * 1-5', $minute, $hour);
    }

    private static function matchWeekly(string $text): ?string
    {
        if (preg_match('/^(?:every\s+([a-z,\s]+?)|weekly\s+on\s+([a-z,\s]+?))(?:\s+at\s+(.+))?$/', $text, $m)) {
            $dayText = trim($m[1] !== '' ? $m[1] : $m[2]);
            $days    = self::parseWeekdayList($dayText);
            if ($days !== null) {
                [$hour, $minute] = self::parseOptionalTime($m[3] ?? '');

                return sprintf('%d %d * * %s', $minute, $hour, implode(',', $days));
            }
        }

        if (preg_match('/^每(?:周|星期|礼拜)(.+)$/u', $text, $m)) {
            $rest = trim($m[1]);

            $dayPart  = $rest;
            $timeText = '';
            if (preg_match('/^(.*?)\s*((?:凌晨|清晨|早上|早晨|上午|中午|下午|傍晚|晚上|夜里|深夜|晚)?\s*\d{1,2}\s*(?:[:：点时]|点\s*半).*)$/u', $rest, $tm)) {
                $dayPart  = trim($tm[1]);
                $timeText = trim($tm[2]);
            }

            $days = self::parseCnWeekdayList($dayPart);
            if ($days === null) {
                return null;
            }

            [$hour, $minute] = self::parseOptionalTime($timeText);

            return sprintf('%d %d * * %s', $minute, $hour, implode(',', $days));
        }

        return null;
    }

    private static function matchDaily(string $text): ?string
    {
        if (preg_match('/^(?:daily|every\s+day)(?:\s+at\s+(.+))?$/', $text, $m)) {
            $rest = $m[1] ?? '';
        } elseif (preg_match('/^每[天日](.*)$/u', $text, $m)) {
            $rest = trim($m[1]);
        } else {
            return null;
        }

        [$hour, $minute] = self::parseOptionalTime($rest);

        return sprintf('%d %d * * *', $minute, $hour);
    }

    private static function matchMonthly(string $text): ?string
    {
        if (preg_match('/^monthly\s+on\s+the\s+last\s+day(?:\s+at\s+(.+))?$/', $text, $m)
            || preg_match('/^每月(?:的)?最后一天(.*)$/u', $text, $m)) {
            [$hour, $minute] = self::parseOptionalTime($m[1] ?? '');

            return sprintf('%d %d L * *', $minute, $hour);
        }

        if (preg_match('/^monthly\s+on\s+the\s+last\s+([a-z]+)(?:\s+at\s+(.+))?$/', $text, $m)) {
            $weekday = self::parseWeekday($m[1]);
            if ($weekday === null) {
                throw new \InvalidArgumentException('无法识别的星期: ' . $m[1]);
            }
            [$hour, $minute] = self::parseOptionalTime($m[2] ?? '');

            return sprintf('%d %d * * %dL', $minute, $hour, $weekday);
        }

        if (preg_match('/^每月最后(?:一个)?(?:周|星期|礼拜)\s*([一二三四五六日天])(.*)$/u', $text, $m)) {
            $weekday = self::parseWeekday($m[1]);
            if ($weekday === null) {
                throw new \InvalidArgumentException('无法识别的星期: ' . $m[1]);
            }
            [$hour, $minute] = self::parseOptionalTime($m[2]);

            return sprintf('%d %d * * %dL', $minute, $hour, $weekday);
        }

        if (preg_match('/^monthly\s+on\s+the\s+([a-z0-9]+)\s+([a-z]+)(?:\s+at\s+(.+))?$/', $text, $m)) {
            $nth = self::EN_ORDINAL[$m[1]] ?? null;
            if ($nth !== null) {
                $weekday = self::parseWeekday($m[2]);
                if ($weekday === null) {
                    throw new \InvalidArgumentException('无法识别的星期: ' . $m[2]);
                }
                [$hour, $minute] = self::parseOptionalTime($m[3] ?? '');

                return sprintf('%d %d * * %d#%d', $minute, $hour, $weekday, $nth);
            }
        }

        if (preg_match('/^每月第\s*([一二三四五1-5])\s*个?(?:周|星期|礼拜)\s*([一二三四五六日天])(.*)$/u', $text, $m)) {
            $nth     = self::CN_ORDINAL[$m[1]] ?? (int) $m[1];
            $weekday = self::parseWeekday($m[2]);
            if ($weekday === null) {
                throw new \InvalidArgumentException('无法识别的星期: ' . $m[2]);
            }
            [$hour, $minute] = self::parseOptionalTime($m[3]);

            return sprintf('%d %d * * %d#%d', $minute, $hour, $weekday, $nth);
        }

        if (preg_match('/^每月\s*(\d{1,2})\s*[号日]\s*(?:最(?:近|接近)的?)?工作日(.*)$/u', $text, $m)) {
            $day = (int) $m[1];
            if ($day < 1 || $day > 31) {
                throw new \InvalidArgumentException("非法的日期: {$day}");
            }
            [$hour, $minute] = self::parseOptionalTime($m[2]);

            return sprintf('%d %d %dW * *', $minute, $hour, $day);
        }

        if (preg_match('/^monthly\s+on\s+(?:day\s+)?(\d{1,2})(?:st|nd|rd|th)?(?:\s+at\s+(.+))?$/', $text, $m)
            || preg_match('/^每月\s*(\d{1,2})\s*[号日](.*)$/u', $text, $m)) {
            $day = (int) $m[1];
            if ($day < 1 || $day > 31) {
                throw new \InvalidArgumentException("非法的日期: {$day}");
            }
            [$hour, $minute] = self::parseOptionalTime($m[2] ?? '');

            return sprintf('%d %d %d * *', $minute, $hour, $day);
        }

        return null;
    }

    private static function matchYearly(string $text): ?string
    {
        if (preg_match('/^(?:yearly|annually|每年|每年一次|一年一次)$/', $text)) {
            return '0 0 1 1 *';
        }

        if (preg_match('/^每年\s*(\d{1,2})\s*月\s*(\d{1,2})\s*[号日]?(.*)$/u', $text, $m)) {
            $month = (int) $m[1];
            $day   = (int) $m[2];
            if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
                throw new \InvalidArgumentException("非法的日期: {$m[1]}-{$m[2]}");
            }
            [$hour, $minute] = self::parseOptionalTime($m[3]);

            return sprintf('%d %d %d %d *', $minute, $hour, $day, $month);
        }

        return null;
    }

    /**
     * 一次性任务：提取时间文本（未做日期合法性判断）
     */
    private static function matchOnce(string $text): ?string
    {
        $patterns = [
            '/^(?:once\s+at|once|at)\s+(.+)$/',
            '/^(?:一次|单次|仅一次|只执行一次)\s*[:：]?\s*(.+)$/u',
            '/^(?:请|在)?\s*(.+?)\s*(?:执行一次|只执行一次|执行|运行)$/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $candidate = trim($m[1]);
                if (self::isDateTimeLike($candidate)) {
                    return $candidate;
                }
            }
        }

        return self::isDateTimeLike($text) ? $text : null;
    }

    /**
     * 是否包含明确的日期信息（避免把 `at 2:30` 误判为一次性任务）
     */
    private static function isDateTimeLike(string $text): bool
    {
        if (preg_match('/\d{4}\s*[-\/.]\s*\d{1,2}\s*[-\/.]\s*\d{1,2}/', $text)) {
            return true;
        }

        if (preg_match('/\d{1,2}\s*月\s*\d{1,2}\s*[日号]/u', $text)) {
            return true;
        }

        return (bool) preg_match('/(?:today|tomorrow|tonight|今天|明天|后天|今晚|明晚|下周)/u', $text);
    }

    private static function parseDateTime(string $text, string $now): ?int
    {
        $relative = strtotime($now === '' ? 'now' : $now) ?: time();
        $text     = trim($text);

        $timestamp = strtotime($text, $relative);
        if ($timestamp !== false) {
            return $timestamp;
        }

        $time = null;
        if (preg_match('/^(?<date>.*?)\s*(?<time>(?:凌晨|清晨|早上|早晨|上午|中午|下午|傍晚|晚上|夜里|深夜|晚)?\s*\d{1,2}\s*(?:[:：点时]|点\s*半).*)$/u', $text, $m)) {
            $time = self::parseTimeOfDay($m['time']);
            if ($time !== null) {
                $text = trim($m['date']);
            }
        }

        $dateText = $text;
        $dateText = (string) preg_replace('/(\d{4})\s*年\s*(\d{1,2})\s*月\s*(\d{1,2})\s*[日号]?/u', '$1-$2-$3', $dateText);
        $dateText = (string) preg_replace('/(\d{1,2})\s*月\s*(\d{1,2})\s*[日号]/u', '$1-$2', $dateText);
        if (preg_match('/^(\d{1,2})-(\d{1,2})$/', $dateText, $md)) {
            $dateText = sprintf('%s-%d-%d', date('Y', $relative), (int) $md[1], (int) $md[2]);
        } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $dateText, $ymd)) {
            $dateText = sprintf('%d-%02d-%02d', (int) $ymd[1], (int) $ymd[2], (int) $ymd[3]);
        }

        $dateText = str_replace(['今天', '今日', '今晚', 'tonight', 'today'], 'today', $dateText);
        $dateText = str_replace(['明天', '明日', '明晚', 'tomorrow'], 'tomorrow', $dateText);
        $dateText = str_replace(['后天'], '+2 days', $dateText);
        $dateText = trim($dateText);

        $dateTimestamp = $dateText === '' ? $relative : strtotime($dateText, $relative);
        if ($dateTimestamp === false) {
            return null;
        }

        if ($time === null) {
            return $dateTimestamp;
        }

        $composed = strtotime(
            date('Y-m-d', $dateTimestamp) . sprintf(' %02d:%02d:00', $time[0], $time[1]),
            $relative
        );

        return $composed === false ? null : $composed;
    }


    /**
     * 归一化文本：小写、全角转半角、去空白与口语化前后缀
     */
    private static function normalize(string $input): string
    {
        $text = str_replace(
            ['：', '，', '、', '。', '（', '）', '　'],
            [':', ',', '、', '.', '(', ')', ' '],
            $input
        );

        $text = strtolower($text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text);

        $text = preg_replace('/^(?:请|麻烦|帮我|定时|计划)/u', '', $text) ?? $text;
        $text = preg_replace('/(?:触发|执行|运行)$/u', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * 解析可选时间文本，缺省为 00:00
     *
     * @param mixed $text
     *
     * @return array{0:int,1:int} [hour, minute]
     *
     * @throws \InvalidArgumentException
     */
    private static function parseOptionalTime($text): array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return [0, 0];
        }

        $time = self::parseTimeOfDay($text);
        if ($time === null) {
            throw new \InvalidArgumentException("无法识别的时间: {$text}");
        }

        return $time;
    }

    /**
     * 解析时刻文本，如 `02:30`、`2点半`、`凌晨2点`、`晚上8点`、`9am`、`2:30 pm`
     *
     * @return array{0:int,1:int}|null [hour, minute]
     */
    private static function parseTimeOfDay(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        if (in_array($text, ['noon', '中午', '正午'], true)) {
            return [12, 0];
        }
        if (in_array($text, ['midnight', '午夜', '零点'], true)) {
            return [0, 0];
        }

        $prefix = null;
        $offset = 0;
        foreach (self::TIME_PREFIX as $word => $add) {
            if (str_starts_with($text, $word)) {
                $prefix = $word;
                $offset = $add;
                $text   = ltrim(substr($text, strlen($word)), ' 的');
                break;
            }
        }

        if ($text === '') {
            return in_array($prefix, ['凌晨', '深夜', '夜里'], true) ? [0, 0] : null;
        }

        $meridiem = null;
        if (preg_match('/(am|pm)$/', $text, $m)) {
            $meridiem = $m[1];
            $text     = trim(substr($text, 0, -2));
        }

        $text  = rtrim($text, '的');
        $hour  = null;
        $minute = 0;

        if (preg_match('/^(\d{1,2}):(\d{1,2})$/', $text, $m)) {
            $hour   = (int) $m[1];
            $minute = (int) $m[2];
        } elseif (preg_match('/^(\d{1,2})\s*[点时]\s*半$/u', $text, $m)) {
            $hour   = (int) $m[1];
            $minute = 30;
        } elseif (preg_match('/^(\d{1,2})\s*[点时]\s*(\d{1,2})?\s*分?$/u', $text, $m)) {
            $hour   = (int) $m[1];
            $minute = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
        } elseif (preg_match('/^(\d{1,2})$/', $text, $m)) {
            $hour = (int) $m[1];
        } else {
            return null;
        }

        if ($meridiem === 'pm' && $hour < 12) {
            $hour += 12;
        }
        if ($meridiem === 'am' && $hour === 12) {
            $hour = 0;
        }
        if ($offset === 12 && $hour < 12) {
            $hour += 12;
        }

        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }

        return [$hour, $minute];
    }

    /**
     * 单个星期文本 → 0-6
     */
    private static function parseWeekday(string $text): ?int
    {
        $text = strtolower(trim($text));
        if ($text === '') {
            return null;
        }

        if (isset(self::EN_WEEKDAY[$text])) {
            return self::EN_WEEKDAY[$text];
        }

        $cn = str_replace(['周', '星期', '礼拜'], '', $text);
        if (isset(self::CN_WEEKDAY[$cn])) {
            return self::CN_WEEKDAY[$cn];
        }

        return null;
    }

    /**
     * 英文星期列表 → 去重升序数组
     *
     * @return int[]|null
     */
    private static function parseWeekdayList(string $text): ?array
    {
        $tokens = preg_split('/[,\s]+/', strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return null;
        }

        $days = [];
        foreach ($tokens as $token) {
            $weekday = self::parseWeekday($token);
            if ($weekday === null) {
                return null;
            }
            $days[] = $weekday;
        }

        sort($days);
        $days = array_values(array_unique($days));

        return $days === [] ? null : $days;
    }

    /**
     * 中文星期列表（已去掉「每周」前缀），如 `一、周三、周五`
     *
     * @return int[]|null
     */
    private static function parseCnWeekdayList(string $text): ?array
    {
        $text   = trim($text);
        $tokens = preg_split('/[、,和及\s]+/u', str_replace(['周', '星期', '礼拜'], '', $text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return null;
        }

        $days = [];
        foreach ($tokens as $token) {
            if (!isset(self::CN_WEEKDAY[$token])) {
                return null;
            }
            $days[] = self::CN_WEEKDAY[$token];
        }

        sort($days);

        return array_values(array_unique($days));
    }


    private static function describeTime(string $minute, string $hour): string
    {
        if ($minute === '*' && $hour === '*') {
            return '每分钟';
        }

        if (preg_match('/^\*\/(\d+)$/', $minute, $m) && $hour === '*') {
            return '每 ' . $m[1] . ' 分钟';
        }

        if (preg_match('/^\*\/(\d+)$/', $hour, $m) && ctype_digit($minute)) {
            return '每 ' . $m[1] . ' 小时的 ' . str_pad($minute, 2, '0', STR_PAD_LEFT) . ' 分';
        }

        if (ctype_digit($minute) && ctype_digit($hour)) {
            return sprintf('%02d:%02d', (int) $hour, (int) $minute);
        }

        if (ctype_digit($minute) && $hour === '*') {
            return (int) $minute === 0 ? '每小时整点' : '每小时的第 ' . (int) $minute . ' 分';
        }

        if (ctype_digit($minute) && preg_match('/^(\d+)-(\d+)$/', $hour, $m)) {
            return sprintf('%d-%d 点之间每小时的第 %d 分', (int) $m[1], (int) $m[2], (int) $minute);
        }

        return $minute . ' ' . $hour;
    }

    private static function describeDate(string $dom, string $month, string $dow): string
    {
        $yearly = $month !== '*';
        $core   = self::describeDateCore($dom, $dow);

        if (!$yearly) {
            return $core;
        }

        if (str_starts_with($core, '每月')) {
            $core = substr($core, 6);
        }

        return sprintf('每年 %d 月%s', (int) $month, $core);
    }

    private static function describeDateCore(string $dom, string $dow): string
    {
        if ($dow !== '*') {
            if (preg_match('/^([0-7])L$/', $dow, $m)) {
                return '每月最后一个周' . self::weekdayName((int) $m[1]);
            }
            if (preg_match('/^([0-7])#([1-5])$/', $dow, $m)) {
                return '每月第' . $m[2] . '个周' . self::weekdayName((int) $m[1]);
            }
            if ($dow === '1-5') {
                return '工作日';
            }
            if (preg_match('/^[0-7](,[0-7])*$/', $dow)) {
                $names = array_map(static fn (string $d): string => self::weekdayName((int) $d), explode(',', $dow));

                return '每周' . implode('、', $names);
            }
        }

        if ($dom === 'L') {
            return '每月最后一天';
        }
        if (preg_match('/^(\d{1,2})W$/', $dom, $m)) {
            return sprintf('每月 %d 号最近的工作日', (int) $m[1]);
        }
        if (ctype_digit($dom)) {
            return sprintf('每月 %d 号', (int) $dom);
        }
        if (preg_match('/^[\d,\-\/]+$/', $dom)) {
            return '每月 ' . $dom . ' 号';
        }

        if ($dow !== '*') {
            return '每周 ' . $dow;
        }

        return $dom === '*' ? '每天' : '每月 ' . $dom;
    }

    private static function weekdayName(int $weekday): string
    {
        $names = ['日', '一', '二', '三', '四', '五', '六'];

        return $names[$weekday % 7];
    }
}
