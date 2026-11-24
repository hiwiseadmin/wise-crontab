<?php

declare(strict_types=1);

namespace wise\crontab\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use wise\crontab\service\TaskAdminService;

/**
 * 维护窗口管理（此前只能通过直接写库维护）
 *
 * 用法：
 *   php think wise:crontab:window list
 *   php think wise:crontab:window add --start=22:00 --end=02:00 --days=1-5 --reason="夜间发版"
 *   php think wise:crontab:window add --start=02:00 --end=04:00 --task=任务名
 *   php think wise:crontab:window remove --id=1
 */
class WindowManage extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:crontab:window')
            ->setDescription('维护窗口管理：list / add / remove')
            ->addArgument('action', Argument::REQUIRED, 'list|add|remove')
            ->addOption('start', null, Option::VALUE_OPTIONAL, '开始时间 HH:MM')
            ->addOption('end', null, Option::VALUE_OPTIONAL, '结束时间 HH:MM（支持跨午夜）')
            ->addOption('days', null, Option::VALUE_OPTIONAL, '星期：* / 1-5 / 0,6')
            ->addOption('task', null, Option::VALUE_OPTIONAL, '任务名称（不填表示全局窗口）')
            ->addOption('reason', null, Option::VALUE_OPTIONAL, '维护原因')
            ->addOption('id', null, Option::VALUE_OPTIONAL, '窗口 ID（remove 时使用）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $action = strtolower((string) $input->getArgument('action'));
        $service = new TaskAdminService($this->app->config->get('wise-crontab', []));

        try {
            switch ($action) {
                case 'list':
                    $windows = $service->windows();
                    if (empty($windows)) {
                        $output->writeln('<comment>暂无维护窗口</comment>');

                        return 0;
                    }

                    $output->writeln(sprintf('%-4s %-22s %-16s %-10s %s', 'ID', '任务', '时间', '星期', '原因'));
                    $output->writeln(str_repeat('-', 90));

                    foreach ($windows as $window) {
                        $taskName = empty($window['task_id']) ? '（全局）' : '#' . (int) $window['task_id'];
                        $output->writeln(sprintf(
                            '%-4s %-22s %-16s %-10s %s',
                            (string) ($window['id'] ?? '-'),
                            $taskName,
                            (string) $window['start_at'] . '-' . (string) $window['end_at'],
                            (string) ($window['days_of_week'] ?? '*'),
                            (string) ($window['reason'] ?? '')
                        ));
                    }
                    break;

                case 'add':
                    $taskId = null;
                    $taskName = (string) ($input->getOption('task') ?? '');
                    if ($taskName !== '') {
                        $task = $service->find($taskName);
                        if ($task === null) {
                            $output->writeln("<error>任务不存在: {$taskName}</error>");

                            return 1;
                        }
                        $taskId = (int) $task['id'];
                    }

                    $id = $service->addWindow([
                        'task_id'      => $taskId,
                        'start_at'     => (string) ($input->getOption('start') ?? ''),
                        'end_at'       => (string) ($input->getOption('end') ?? ''),
                        'days_of_week' => (string) ($input->getOption('days') ?? '*'),
                        'reason'       => (string) ($input->getOption('reason') ?? ''),
                    ]);

                    $output->writeln("<info>已新增维护窗口 #{$id}</info>");
                    break;

                case 'remove':
                    $id = (int) ($input->getOption('id') ?? 0);
                    if ($id <= 0) {
                        $output->writeln('<error>请使用 --id 指定窗口 ID</error>');

                        return 1;
                    }

                    $output->writeln($service->removeWindow($id)
                        ? "<info>已删除维护窗口 #{$id}</info>"
                        : "<comment>未找到维护窗口 #{$id}</comment>");
                    break;

                default:
                    $output->writeln('<error>未知的 action: ' . $action . '</error>');

                    return 1;
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return 1;
        }

        return 0;
    }
}
