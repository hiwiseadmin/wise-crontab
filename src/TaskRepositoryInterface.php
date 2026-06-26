<?php

declare(strict_types=1);

namespace wise\crontab;

interface TaskRepositoryInterface
{
    /**
     * 获取所有已启用的任务
     *
     * @return array
     */
    public function all(): array;

    /**
     * 根据任务名称查找任务
     *
     * @param string $name
     * @return array|null
     */
    public function findByName(string $name): ?array;

    /**
     * 检查仓库是否可用
     *
     * @return bool
     */
    public function isAvailable(): bool;
}
