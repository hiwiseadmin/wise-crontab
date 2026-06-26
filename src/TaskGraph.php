<?php

declare(strict_types=1);

namespace wise\crontab;

use wise\crontab\model\TaskDependencyModel;
use wise\crontab\model\TaskLogModel;

/**
 * 任务依赖图 — 拓扑排序 + 循环依赖检测
 *
 * 支持条件依赖：
 * - 'success'   : 仅依赖任务成功时方可执行（默认）
 * - 'completed' : 依赖任务无论成功失败，只要执行完毕即可
 * - 'any'       : 不检查依赖任务的执行状态
 */
class TaskGraph
{
    /**
     * 从到期任务列表中筛选可执行的任务（满足依赖条件）
     *
     * @param array $dueTasks 到期任务列表
     * @return array 可执行的任务列表
     */
    public function getRunnableTasks(array $dueTasks): array
    {
        if (empty($dueTasks)) {
            return [];
        }

        $taskIds = array_column($dueTasks, 'id');
        $taskIds = array_filter($taskIds, fn($id) => $id > 0);

        // 无数据库任务 ID 时，全部可执行（config 源任务）
        if (empty($taskIds)) {
            return $dueTasks;
        }

        // 查询所有依赖关系
        $dependencies = TaskDependencyModel::whereIn('task_id', $taskIds)->select()->toArray();

        if (empty($dependencies)) {
            return $dueTasks; // 无依赖，全部可执行
        }

        // 建立任务 ID → task 的索引
        $taskMap = [];
        foreach ($dueTasks as $task) {
            $id = $task['id'] ?? 0;
            if ($id > 0) {
                $taskMap[$id] = $task;
            } else {
                // 无 DB ID 的任务无依赖，直接可执行
                $taskMap['config_' . ($task['name'] ?? uniqid())] = $task;
            }
        }

        // 查询依赖任务的最新执行结果
        $dependsOnIds = array_unique(array_column($dependencies, 'depends_on_task_id'));
        $lastResults = $this->getLastResults($dependsOnIds);

        $runnable = [];
        foreach ($dueTasks as $task) {
            $taskId = $task['id'] ?? 0;
            if ($taskId <= 0) {
                $runnable[] = $task;
                continue;
            }

            // 查找该任务的所有依赖
            $taskDeps = array_filter($dependencies, fn($d) => (int)$d['task_id'] === (int)$taskId);

            if (empty($taskDeps)) {
                $runnable[] = $task;
                continue;
            }

            // 检查所有依赖是否满足
            $allDepsSatisfied = true;
            foreach ($taskDeps as $dep) {
                $depTaskId = (int)$dep['depends_on_task_id'];
                $condition = $dep['condition'] ?? 'success';

                if (!$this->isDependencySatisfied($depTaskId, $condition, $lastResults)) {
                    $allDepsSatisfied = false;
                    break;
                }
            }

            if ($allDepsSatisfied) {
                $runnable[] = $task;
            }
        }

        return $runnable;
    }

    /**
     * 检测循环依赖
     *
     * @return array 循环依赖列表，每项为任务 ID 环 []
     */
    public function detectCycles(): array
    {
        $allDeps = TaskDependencyModel::select()->toArray();
        if (empty($allDeps)) {
            return [];
        }

        // 构建邻接表
        $graph = [];
        foreach ($allDeps as $dep) {
            $from = (int)$dep['depends_on_task_id'];
            $to = (int)$dep['task_id'];
            $graph[$from][] = $to;
        }

        $cycles = [];
        $allNodes = array_unique(array_merge(array_keys($graph), ...array_values($graph)));

        // DFS 检测环
        $visited = [];
        $recStack = [];

        foreach ($allNodes as $node) {
            if ($this->dfs($node, $graph, $visited, $recStack, [], $cycles)) {
                // 发现环
            }
        }

        return $cycles;
    }

    private function dfs(int $node, array $graph, array &$visited, array &$recStack, array $path, array &$cycles): bool
    {
        if (in_array($node, $recStack)) {
            // 找到环
            $cycleStart = array_search($node, $path);
            $cycle = array_slice($path, $cycleStart);
            $cycle[] = $node;
            $cycles[] = $cycle;
            return true;
        }

        if (in_array($node, $visited)) {
            return false;
        }

        $visited[] = $node;
        $recStack[] = $node;
        $path[] = $node;

        if (isset($graph[$node])) {
            foreach ($graph[$node] as $neighbor) {
                $this->dfs($neighbor, $graph, $visited, $recStack, $path, $cycles);
            }
        }

        array_pop($recStack);
        return false;
    }

    /**
     * 获取任务的最新执行结果
     *
     * @param int[] $taskIds
     * @return array<int, array> task_id → latest log
     */
    private function getLastResults(array $taskIds): array
    {
        if (empty($taskIds)) {
            return [];
        }

        $results = [];
        foreach ($taskIds as $taskId) {
            $log = TaskLogModel::where('task_id', $taskId)
                ->whereIn('status', ['success', 'failure'])
                ->order('id', 'desc')
                ->find();

            if ($log) {
                $results[$taskId] = $log->toArray();
            }
        }
        return $results;
    }

    /**
     * 检查依赖是否满足
     */
    private function isDependencySatisfied(int $taskId, string $condition, array $lastResults): bool
    {
        // 无执行记录 → 认为未满足（需要至少执行过一次）
        if (!isset($lastResults[$taskId])) {
            return false;
        }

        $last = $lastResults[$taskId];

        return match ($condition) {
            'success'   => $last['status'] === 'success',
            'completed' => in_array($last['status'], ['success', 'failure'], true),
            'any'       => true,
            default     => true,
        };
    }
}
