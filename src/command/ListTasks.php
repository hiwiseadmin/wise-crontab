<?php

declare(strict_types=1);

namespace wise\crontab\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\crontab\service\TaskAdminService;

class ListTasks extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:crontab:list')
            ->setDescription('查看定时任务列表（含调度说明与下次执行时间）')
            ->addOption('status', 's', Option::VALUE_OPTIONAL, '按状态过滤：0 禁用 / 1 启用')
            ->addOption('keyword', 'k', Option::VALUE_OPTIONAL, '按名称/标题模糊搜索')
            ->addOption('limit', null, Option::VALUE_OPTIONAL, '返回条数', 50)
            ->addOption('refresh', null, Option::VALUE_NONE, '回写下次执行时间到数据库');
    }

    protected function execute(Input $input, Output $output): int
    {
        $service = new TaskAdminService($this->app->config->get('wise-crontab', []));

        if ($input->getOption('refresh')) {
            $output->writeln('<info>已回写 ' . $service->refreshNextRun() . ' 个任务的下次执行时间</info>');
        }

        $tasks = $service->list([
            'status'  => $input->getOption('status'),
            'keyword' => $input->getOption('keyword'),
            'limit'   => (int) $input->getOption('limit'),
        ]);

        if (empty($tasks)) {
            $output->writeln('<comment>暂无定时任务</comment>');

            return 0;
        }

        $output->writeln(sprintf('%-4s %-22s %-24s %-22s %-20s %-6s', 'ID', '名称', '调度', '说明', '下次执行', '状态'));
        $output->writeln(str_repeat('-', 108));

        foreach ($tasks as $task) {
            $output->writeln(sprintf(
                '%-4s %-22s %-24s %-22s %-20s %-6s',
                (string) ($task['id'] ?? '-'),
                mb_substr((string) ($task['name'] ?? ''), 0, 20),
                mb_substr((string) ($task['schedule'] ?: ($task['cron_expression'] ?? '')), 0, 22),
                mb_substr((string) ($task['schedule_description'] ?? ($task['schedule_error'] ?? '-')), 0, 20),
                (string) ($task['next_run_at'] ?? '-'),
                ((int) ($task['status'] ?? 0) === 1) ? '启用' : '禁用'
            ));
        }

        return 0;
    }
}
