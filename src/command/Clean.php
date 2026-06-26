<?php

declare(strict_types=1);

namespace wise\crontab\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\crontab\CrontabManager;

class Clean extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:crontab:clean')
            ->setDescription('清理过期的定时任务执行日志')
            ->addOption('days', 'd', Option::VALUE_OPTIONAL, '日志保留天数（默认读取配置）')
            ->addOption('force', 'f', Option::VALUE_NONE, '跳过确认，直接执行清理');
    }

    protected function execute(Input $input, Output $output): int
    {
        /** @var CrontabManager $manager */
        $manager = $this->app->make(CrontabManager::class);

        $customDays = $input->getOption('days');
        $force = $input->getOption('force');

        if ($customDays !== null) {
            $manager->setConfig('log_retention_days', (int)$customDays);
        }

        $deleted = $manager->cleanExpiredLogs();

        if ($deleted > 0) {
            $output->writeln("<info>成功清理 {$deleted} 条过期日志</info>");
        } else {
            $output->writeln('<info>没有需要清理的过期日志</info>');
        }

        return 0;
    }
}
