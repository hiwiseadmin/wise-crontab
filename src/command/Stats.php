<?php

declare(strict_types=1);

namespace wise\crontab\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\crontab\metrics\TaskMetrics;

class Stats extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:crontab:stats')
            ->setDescription('查看定时任务执行统计')
            ->addOption('task', 't', Option::VALUE_OPTIONAL, '指定任务名称查看详情')
            ->addOption('trend', null, Option::VALUE_NONE, '查看过去7天趋势')
            ->addOption('anomalies', null, Option::VALUE_NONE, '检测异常（连续失败任务）')
            ->addOption('hours', null, Option::VALUE_OPTIONAL, '统计时间范围（小时），默认24', 24);
    }

    protected function execute(Input $input, Output $output): int
    {
        $metrics = new TaskMetrics();
        $taskName = $input->getOption('task');
        $showTrend = $input->getOption('trend');
        $showAnomalies = $input->getOption('anomalies');
        $hours = (int)$input->getOption('hours');

        if ($showAnomalies) {
            $output->writeln('<info>=== 异常检测（连续失败 >= 3 次） ===</info>');
            $anomalies = $metrics->detectAnomalies(3);
            if (empty($anomalies)) {
                $output->writeln('<info>未发现异常任务</info>');
            } else {
                foreach ($anomalies as $a) {
                    $output->writeln("<error>⚠ {$a['task_name']} — 连续失败 {$a['consecutive_failures']} 次 (自 {$a['since']})</error>");
                }
            }
            return 0;
        }

        if ($taskName && $showTrend) {
            $output->writeln("<info>=== {$taskName} 过去7天趋势 ===</info>");
            $trend = $metrics->getTrend($taskName, 7);
            $output->writeln(' 日期         | 总量 | 成功 | 失败 | 成功率');
            $output->writeln('--------------|------|------|------|--------');
            foreach ($trend as $t) {
                $rate = $t['success_rate'] . '%';
                $output->writeln(sprintf(' %s | %4d | %4d | %4d | %s',
                    $t['day'], $t['total'], $t['success'], $t['failure'], $rate));
            }
            return 0;
        }

        if ($taskName) {
            $output->writeln("<info>=== {$taskName} 最近{$hours}小时统计 ===</info>");
            $stats = $metrics->getDailyStats($hours);
            foreach ($stats as $s) {
                if ($s['task_name'] === $taskName) {
                    $this->printStat($output, $s);
                    return 0;
                }
            }
            $output->writeln("<comment>未找到任务 {$taskName} 的执行记录</comment>");
            return 0;
        }

        $overview = $metrics->getOverview();
        $output->writeln('<info>=== 定时任务全局概览 ===</info>');
        $output->writeln("  任务种类: {$overview['total_tasks']}");
        $output->writeln("  最近24h执行: {$overview['recent_24h_total']}");
        $output->writeln("  最近24h失败: {$overview['recent_24h_failed']}");
        $output->writeln("  平均成功率: {$overview['avg_success_rate']}%");

        if (!empty($overview['anomalies'])) {
            $output->writeln('');
            $output->writeln('<error>⚠ 异常任务:</error>');
            foreach ($overview['anomalies'] as $a) {
                $output->writeln("<error>  - {$a['task_name']}: 连续失败 {$a['consecutive_failures']} 次</error>");
            }
        }

        if (!empty($overview['per_task_stats'])) {
            $output->writeln('');
            $output->writeln('<info> 最近24h各任务明细:</info>');
            $output->writeln('  任务名 | 总数 | 成功 | 失败 | 成功率 | 平均耗时');
            $output->writeln('  -------|------|------|------|--------|----------');
            foreach ($overview['per_task_stats'] as $s) {
                $rate = is_numeric($s['success_rate']) ? $s['success_rate'] . '%' : $s['success_rate'];
                $output->writeln(sprintf('  %s | %d | %d | %d | %s | %.2fs',
                    $s['task_name'], $s['total'], $s['success'], $s['failure'], $rate, $s['avg_duration']));
            }
        }

        return 0;
    }

    private function printStat(Output $output, array $s): void
    {
        $rate = is_numeric($s['success_rate']) ? $s['success_rate'] . '%' : $s['success_rate'];
        $output->writeln("  执行次数: {$s['total']}");
        $output->writeln("  成功/失败: {$s['success']}/{$s['failure']}");
        $output->writeln("  成功率: {$rate}");
        $output->writeln("  平均耗时: {$s['avg_duration']}s");
        $output->writeln("  最大耗时: {$s['max_duration']}s");
        $output->writeln("  最近执行: {$s['last_run']}");
    }
}
