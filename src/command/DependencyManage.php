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
 * 任务依赖管理（此前只能通过直接写库维护）
 *
 * 用法：
 *   php think wise:crontab:dependency list   --task=任务名
 *   php think wise:crontab:dependency add    --task=任务名 --depends=前置任务名 --condition=success
 *   php think wise:crontab:dependency remove --task=任务名 --depends=前置任务名
 */
class DependencyManage extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:crontab:dependency')
            ->setDescription('任务依赖管理：list / add / remove')
            ->addArgument('action', Argument::REQUIRED, 'list|add|remove')
            ->addOption('task', null, Option::VALUE_OPTIONAL, '任务名称')
            ->addOption('depends', null, Option::VALUE_OPTIONAL, '前置任务名称')
            ->addOption('condition', null, Option::VALUE_OPTIONAL, '依赖条件：success / completed / any', 'success');
    }

    protected function execute(Input $input, Output $output): int
    {
        $action = strtolower((string) $input->getArgument('action'));
        $taskName = (string) ($input->getOption('task') ?? '');
        $dependsOn = (string) ($input->getOption('depends') ?? '');

        if ($taskName === '') {
            $output->writeln('<error>请使用 --task 指定任务名称</error>');

            return 1;
        }

        $service = new TaskAdminService($this->app->config->get('wise-crontab', []));

        try {
            switch ($action) {
                case 'list':
                    $deps = $service->dependencies($taskName);
                    if (empty($deps)) {
                        $output->writeln("<comment>任务 {$taskName} 暂无依赖</comment>");

                        return 0;
                    }

                    foreach ($deps as $dep) {
                        $output->writeln(sprintf(
                            '  %s 依赖 %s（条件：%s）',
                            $taskName,
                            (string) $dep['depends_on_name'],
                            (string) $dep['condition']
                        ));
                    }
                    break;

                case 'add':
                    if ($dependsOn === '') {
                        $output->writeln('<error>请使用 --depends 指定前置任务名称</error>');

                        return 1;
                    }

                    $service->addDependency($taskName, $dependsOn, (string) $input->getOption('condition'));
                    $output->writeln("<info>已设置依赖：{$taskName} ← {$dependsOn}</info>");
                    break;

                case 'remove':
                    if ($dependsOn === '') {
                        $output->writeln('<error>请使用 --depends 指定前置任务名称</error>');

                        return 1;
                    }

                    $output->writeln($service->removeDependency($taskName, $dependsOn)
                        ? "<info>已移除依赖：{$taskName} ← {$dependsOn}</info>"
                        : "<comment>未找到该依赖</comment>");
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
