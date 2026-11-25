<?php

declare(strict_types=1);

namespace wise\crontab\metrics;

use wise\crontab\model\TaskLogModel;

/**
 * 任务执行度量与统计
 *
 * 提供任务级和全局级的执行统计、成功率、耗时趋势等分析。
 * 支持异常检测（连续失败告警）。
 */
class TaskMetrics
{
    /**
     * 获取过去 N 小时每个任务的统计数据
     *
     * @param int $hours 统计时间范围（小时），默认 24
     * @return array
     */
    public function getDailyStats(int $hours = 24): array
    {
        $since = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

        $rows = TaskLogModel::where('create_time', '>=', $since)
            ->whereIn('status', ['success', 'failure'])
            ->fieldRaw(
                "task_name, COUNT(*) AS total,"
                . " SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success_cnt,"
                . " SUM(CASE WHEN status = 'failure' THEN 1 ELSE 0 END) AS failure_cnt,"
                . " SUM(duration) AS total_duration, MAX(duration) AS max_duration,"
                . " MAX(create_time) AS last_run"
            )
            ->group('task_name')
            ->select()
            ->toArray();

        $result = [];
        foreach ($rows as $row) {
            $total   = (int) ($row['total'] ?? 0);
            $success = (int) ($row['success_cnt'] ?? 0);
            $failure = (int) ($row['failure_cnt'] ?? 0);
            $seconds = (float) ($row['total_duration'] ?? 0);

            $result[] = [
                'task_name'    => (string) ($row['task_name'] ?? ''),
                'total'        => $total,
                'success'      => $success,
                'failure'      => $failure,
                'max_duration' => (float) ($row['max_duration'] ?? 0),
                'last_run'     => $row['last_run'] ?? null,
                'avg_duration' => $total > 0 ? round($seconds / $total, 4) : 0,
                'success_rate' => $total > 0 ? round($success / $total * 100, 2) : 'N/A',
            ];
        }

        usort($result, fn($a, $b) => $b['total'] <=> $a['total']);
        return $result;
    }

    /**
     * 获取指定任务过去 N 天的每日成功率趋势
     *
     * @param string $taskName
     * @param int    $days
     * @return array
     */
    public function getTrend(string $taskName, int $days = 7): array
    {
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $logs = TaskLogModel::where('task_name', $taskName)
            ->where('create_time', '>=', $since)
            ->whereIn('status', ['success', 'failure'])
            ->select()
            ->toArray();

        $daily = [];
        foreach ($logs as $log) {
            $day = substr($log['create_time'], 0, 10);
            if (!isset($daily[$day])) {
                $daily[$day] = ['total' => 0, 'success' => 0, 'failure' => 0];
            }
            $daily[$day]['total']++;
            if ($log['status'] === 'success') {
                $daily[$day]['success']++;
            } else {
                $daily[$day]['failure']++;
            }
        }

        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $d = $daily[$day] ?? ['total' => 0, 'success' => 0, 'failure' => 0];
            $trend[] = [
                'day'          => $day,
                'total'        => $d['total'],
                'success'      => $d['success'],
                'failure'      => $d['failure'],
                'success_rate' => $d['total'] > 0 ? round($d['success'] / $d['total'] * 100, 2) : 0,
            ];
        }

        return $trend;
    }

    /**
     * 异常检测：连续失败 N 次的任务
     *
     * @param int $consecutiveFailures 连续失败阈值，默认 3
     * @return array 异常任务列表
     */
    public function detectAnomalies(int $consecutiveFailures = 3): array
    {
        $anomalies = [];

        $consecutiveFailures = max(1, $consecutiveFailures);

        $taskNames = TaskLogModel::distinct(true)
            ->column('task_name');

        if (empty($taskNames)) {
            return [];
        }

        $limit = min(5000, max(50, count($taskNames) * $consecutiveFailures * 4));

        $rows = TaskLogModel::whereIn('task_name', $taskNames)
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        $recentByTask = [];
        foreach ($rows as $row) {
            $name = (string) ($row['task_name'] ?? '');
            if ($name === '' || count($recentByTask[$name] ?? []) >= $consecutiveFailures) {
                continue;
            }
            $recentByTask[$name][] = $row;
        }

        foreach ($recentByTask as $name => $recentLogs) {
            if (count($recentLogs) < $consecutiveFailures) {
                continue;
            }

            $allFailed = true;
            foreach ($recentLogs as $log) {
                if ($log['status'] !== 'failure') {
                    $allFailed = false;
                    break;
                }
            }

            if ($allFailed) {
                $anomalies[] = [
                    'task_name'          => $name,
                    'consecutive_failures' => $consecutiveFailures,
                    'since'              => $recentLogs[0]['create_time'] ?? 'unknown',
                ];
            }
        }

        return $anomalies;
    }

    /**
     * 全局概览统计
     */
    public function getOverview(): array
    {
        $totalTasks = TaskLogModel::distinct(true)->count('task_name');
        $recent24h = $this->getDailyStats(24);

        $totalExecutions = array_sum(array_column($recent24h, 'total'));
        $totalSuccess = array_sum(array_column($recent24h, 'success'));
        $avgSuccessRate = $totalExecutions > 0 ? round($totalSuccess / $totalExecutions * 100, 2) : 0;

        $anomalies = $this->detectAnomalies(3);

        return [
            'total_tasks'       => $totalTasks,
            'recent_24h_total'  => $totalExecutions,
            'recent_24h_failed' => array_sum(array_column($recent24h, 'failure')),
            'avg_success_rate'  => $avgSuccessRate,
            'anomalies'         => $anomalies,
            'per_task_stats'    => $recent24h,
        ];
    }
}
