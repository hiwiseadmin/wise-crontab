<?php

declare(strict_types=1);

namespace wise\crontab\test;

use wise\crontab\metrics\TaskMetrics;

/**
 * 执行度量与统计测试
 */
class TaskMetricsTest extends DatabaseTestCase
{
    public function testGetDailyStatsAggregatesSuccessAndFailure(): void
    {
        $this->createLog(['task_name' => 'a', 'status' => 'success', 'duration' => 1.0]);
        $this->createLog(['task_name' => 'a', 'status' => 'failure', 'duration' => 3.0]);
        $this->createLog(['task_name' => 'b', 'status' => 'success', 'duration' => 2.0]);

        $stats = (new TaskMetrics())->getDailyStats(24);

        $this->assertCount(2, $stats);

        $a = $stats[0];
        $this->assertSame('a', $a['task_name']);
        $this->assertSame(2, $a['total']);
        $this->assertSame(1, $a['success']);
        $this->assertSame(1, $a['failure']);
        $this->assertSame(2.0, $a['avg_duration']);
        $this->assertSame(3.0, $a['max_duration']);
        $this->assertSame(50.0, $a['success_rate']);
    }

    public function testGetTrendFillsMissingDays(): void
    {
        $this->createLog(['task_name' => 'a', 'status' => 'success']);

        $trend = (new TaskMetrics())->getTrend('a', 7);

        $this->assertCount(7, $trend);
        $this->assertSame(1, $trend[6]['total']);
        $this->assertSame(100.0, $trend[6]['success_rate']);
        $this->assertSame(0, $trend[0]['total']);
    }

    public function testDetectAnomalies(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->createLog(['task_name' => 'bad', 'status' => 'failure']);
        }
        $this->createLog(['task_name' => 'good', 'status' => 'success']);

        $anomalies = (new TaskMetrics())->detectAnomalies(3);

        $this->assertCount(1, $anomalies);
        $this->assertSame('bad', $anomalies[0]['task_name']);
        $this->assertSame(3, $anomalies[0]['consecutive_failures']);
    }

    public function testDetectAnomaliesIgnoresInterruptedFailures(): void
    {
        $this->createLog(['task_name' => 'x', 'status' => 'failure']);
        $this->createLog(['task_name' => 'x', 'status' => 'success']);
        $this->createLog(['task_name' => 'x', 'status' => 'failure']);

        $this->assertSame([], (new TaskMetrics())->detectAnomalies(3));
    }

    public function testOverview(): void
    {
        $this->createLog(['task_name' => 'a', 'status' => 'success']);
        $this->createLog(['task_name' => 'b', 'status' => 'failure']);

        $overview = (new TaskMetrics())->getOverview();

        $this->assertSame(2, $overview['total_tasks']);
        $this->assertSame(2, $overview['recent_24h_total']);
        $this->assertSame(1, $overview['recent_24h_failed']);
        $this->assertSame(50.0, $overview['avg_success_rate']);
        $this->assertCount(2, $overview['per_task_stats']);
    }
}
