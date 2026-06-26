<?php

declare(strict_types=1);

namespace wise\crontab\driver;

use wise\crontab\TaskRepositoryInterface;

class ConfigTaskRepository implements TaskRepositoryInterface
{
    /**
     * @param array $tasks 配置文件中的任务数组
     */
    public function __construct(
        private array $tasks = []
    ) {
    }

    /**
     * 获取所有启用的任务（过滤 status=0 的禁用任务）
     */
    public function all(): array
    {
        $result = [];
        foreach ($this->tasks as $task) {
            if (isset($task['status']) && (int)$task['status'] === 0) {
                continue;
            }
            $task['source'] = 'config';
            $result[] = $task;
        }
        return $result;
    }

    /**
     * 根据任务名称查找
     */
    public function findByName(string $name): ?array
    {
        foreach ($this->tasks as $task) {
            if (($task['name'] ?? '') === $name) {
                if (isset($task['status']) && (int)$task['status'] === 0) {
                    return null;
                }
                $task['source'] = 'config';
                return $task;
            }
        }
        return null;
    }

    /**
     * 配置仓库始终可用
     */
    public function isAvailable(): bool
    {
        return true;
    }
}
