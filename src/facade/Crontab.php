<?php

declare(strict_types=1);

namespace wise\crontab\facade;

use think\Facade as BaseFacade;

/**
 * CrontabManager 门面
 *
 * @method static array  dispatch()
 * @method static array  getTasks()
 * @method static array  getUpcomingTasks(int $limit = 10)
 * @method static int    cleanExpiredLogs()
 * @method static void   setConfig(string $key, $value)
 * @method static array  getConfig()
 * @see \wise\crontab\CrontabManager
 */
class Crontab extends BaseFacade
{
    protected static function getFacadeClass(): string
    {
        return \wise\crontab\CrontabManager::class;
    }
}
