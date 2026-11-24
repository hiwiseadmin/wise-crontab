<?php

declare(strict_types=1);

namespace wise\crontab\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\crontab\CrontabManager;

class Run extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:crontab:run')
            ->setDescription('扫描并执行到期的定时任务（每分钟触发一次）')
            ->addOption('clean', 'c', Option::VALUE_NONE, '执行后自动清理过期日志');
    }

    protected function execute(Input $input, Output $output): int
    {
        $manager = $this->app->make(CrontabManager::class);

        if ($input->getOption('clean')) {
            $manager->setConfig('auto_clean_logs', true);
        }

        $stats = $manager->dispatch();

        $output->writeln('<info>=== 定时任务调度完成 ===</info>');
        $output->writeln("  任务总数: {$stats['total']}, 已执行: {$stats['executed']}, 成功: {$stats['success']}, 失败: {$stats['failure']}, 跳过: {$stats['skipped']}");

        if (!empty($stats['blocked_by_window'])) {
            $output->writeln("  <comment>维护窗口阻塞: {$stats['blocked_by_window']}</comment>");
        }
        if (!empty($stats['blocked_by_dependency'])) {
            $output->writeln("  <comment>依赖未满足: {$stats['blocked_by_dependency']}</comment>");
        }
        if (!empty($stats['blocked_by_semaphore'])) {
            $output->writeln("  <comment>并发限制: {$stats['blocked_by_semaphore']}</comment>");
        }

        foreach ($manager->getScheduleErrors() as $taskName => $message) {
            $output->writeln("  <error>调度表达式非法 — {$taskName}: {$message}</error>");
        }

        if ($stats['failure'] > 0 || !empty($manager->getScheduleErrors())) {
            return 1;
        }

        return 0;
    }
}
