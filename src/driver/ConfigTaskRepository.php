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
     * 获取所有启用的任务（过滤 status=0 / enabled=false 的禁用任务）
     */
    public function all(): array
    {
        $result = [];
        foreach ($this->tasks as $task) {
            if (!$this->isEnabled($task)) {
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
                if (!$this->isEnabled($task)) {
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

    /**
     * 启用判定
     *
     * 配置模板声明 `'enabled' => false` 等价于 `status = 0`，旧实现只看 status，
     * 导致按文档写成 `enabled=false` 的任务仍会被每分钟执行。优先级：status > enabled > 默认启用。
     *
     * @param array $task 任务定义
     */
    private function isEnabled(array $task): bool
    {
        if (isset($task['status']) && $task['status'] !== '') {
            return (int) $task['status'] !== 0;
        }

        if (array_key_exists('enabled', $task) && $task['enabled'] !== '') {
            return (bool) $task['enabled'];
        }

        return true;
    }
}
