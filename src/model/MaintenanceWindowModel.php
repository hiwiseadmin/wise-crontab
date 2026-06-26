<?php

declare(strict_types=1);

namespace wise\crontab\model;

use think\Model;

/**
 * 定时任务维护窗口模型
 *
 * 对应表：crontab_maintenance_window
 * 定义任务不可执行的时间窗口，task_id 为 NULL 表示全局窗口。
 */
class MaintenanceWindowModel extends Model
{
    protected $name = 'crontab_maintenance_window';

    public function __construct($data = [])
    {
        parent::__construct($data);
        $this->setName(config('wise-crontab.table_names.maintenance_window', $this->name));
    }

    protected $autoWriteTimestamp = false;

    protected $schema = [
        'id'           => 'int',
        'task_id'      => 'int',
        'start_at'     => 'string',
        'end_at'       => 'string',
        'days_of_week' => 'string',
        'reason'       => 'string',
    ];

    /**
     * 检查当前时间是否落在维护窗口内
     *
     * @param int|null $taskId 任务ID，null 时检查全局窗口
     * @return array|null 命中的窗口，null 表示不在窗口内
     */
    public static function isInWindow(?int $taskId = null): ?array
    {
        $now = date('H:i');
        $dayOfWeek = date('w'); // 0=Sun, 6=Sat

        $query = self::where(function ($q) use ($taskId) {
            if ($taskId !== null) {
                $q->where('task_id', $taskId)->whereOrNull('task_id');
            }
        });

        $windows = $query->select()->toArray();

        foreach ($windows as $window) {
            // 检查时间范围
            if ($now < $window['start_at'] || $now > $window['end_at']) {
                continue;
            }

            // 检查星期
            $daysOfWeek = $window['days_of_week'] ?? '*';
            if ($daysOfWeek !== '*' && !in_array($dayOfWeek, explode(',', $daysOfWeek))) {
                continue;
            }

            return $window;
        }

        return null;
    }
}
