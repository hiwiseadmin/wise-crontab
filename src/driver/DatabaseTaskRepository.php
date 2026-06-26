<?php

declare(strict_types=1);

namespace wise\crontab\driver;

use wise\crontab\TaskRepositoryInterface;
use wise\crontab\model\TaskModel;

class DatabaseTaskRepository implements TaskRepositoryInterface
{
    /**
     * 获取所有启用的任务
     */
    public function all(): array
    {
        $tasks = TaskModel::where('status', 1)->select();
        return $tasks->toArray();
    }

    /**
     * 根据任务名称查找启用的任务
     */
    public function findByName(string $name): ?array
    {
        $task = TaskModel::where('name', $name)
            ->where('status', 1)
            ->find();

        if ($task === null) {
            return null;
        }

        return $task->toArray();
    }

    /**
     * 检查数据库仓库是否可用
     *
     * 通过尝试获取 ThinkPHP 数据库连接来验证可用性。
     * 如果未配置数据库或连接失败，返回 false。
     */
    public function isAvailable(): bool
    {
        try {
            \think\facade\Db::connect()->getConnection();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
