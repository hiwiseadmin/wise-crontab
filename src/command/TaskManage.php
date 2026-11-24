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
 * 定时任务增删改与启停
 *
 * 用法：
 *   php think wise:crontab:task add  <name> --command="php think demo" --schedule="每5分钟"
 *   php think wise:crontab:task update <name> --schedule="每天凌晨2点"
 *   php think wise:crontab:task enable|disable|delete|run <name>
 */
class TaskManage extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:crontab:task')
            ->setDescription('管理定时任务：add / update / delete / enable / disable / run')
            ->addArgument('action', Argument::REQUIRED, 'add|update|delete|enable|disable|run')
            ->addArgument('name', Argument::OPTIONAL, '任务名称')
            ->addOption('command', null, Option::VALUE_OPTIONAL, '执行命令（需命中命令白名单）')
            ->addOption('schedule', null, Option::VALUE_OPTIONAL, '语义化调度，如「每5分钟」「daily at 02:30」')
            ->addOption('cron', null, Option::VALUE_OPTIONAL, '原生 cron 表达式（schedule 为空时生效）')
            ->addOption('title', null, Option::VALUE_OPTIONAL, '任务标题')
            ->addOption('timeout', null, Option::VALUE_OPTIONAL, '超时（秒）')
            ->addOption('max-retries', null, Option::VALUE_OPTIONAL, '失败重试次数')
            ->addOption('allow-concurrent', null, Option::VALUE_NONE, '允许并发执行')
            ->addOption('force', 'f', Option::VALUE_NONE, 'delete 时跳过二次确认（供脚本/CI 使用）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $action = strtolower((string) $input->getArgument('action'));
        $name = (string) ($input->getArgument('name') ?? '');

        if (!in_array($action, ['add', 'update', 'delete', 'enable', 'disable', 'run'], true)) {
            $output->writeln('<error>未知的 action: ' . $action . '</error>');

            return 1;
        }

        if ($name === '') {
            $output->writeln('<error>缺少任务名称</error>');

            return 1;
        }

        $service = new TaskAdminService($this->app->config->get('wise-crontab', []));

        try {
            switch ($action) {
                case 'add':
                    $task = $service->create($this->payload($input, true));
                    $output->writeln(sprintf(
                        '<info>已创建任务：%s（%s → %s）</info>',
                        (string) $task['name'],
                        (string) ($task['schedule'] ?: $task['cron_expression']),
                        (string) ($task['schedule_description'] ?? '-')
                    ));
                    break;

                case 'update':
                    $task = $service->update($name, $this->payload($input, false));
                    $output->writeln(sprintf(
                        '<info>已更新任务：%s（%s → %s）</info>',
                        (string) $task['name'],
                        (string) ($task['schedule'] ?: $task['cron_expression']),
                        (string) ($task['schedule_description'] ?? '-')
                    ));
                    break;

                case 'delete':
                    if (!$input->getOption('force')
                        && !$output->confirm($input, "确认删除任务「{$name}」？该操作不可恢复（加 --force 可跳过确认）", false)
                    ) {
                        $output->writeln('<comment>已取消删除。非交互模式需显式加 --force。</comment>');

                        return 0;
                    }

                    $output->writeln($service->delete($name)
                        ? "<info>已删除任务：{$name}</info>"
                        : "<comment>删除失败：{$name}</comment>");
                    break;

                case 'enable':
                    $output->writeln($service->enable($name)
                        ? "<info>已启用任务：{$name}</info>"
                        : "<comment>启用失败：{$name}</comment>");
                    break;

                case 'disable':
                    $output->writeln($service->disable($name)
                        ? "<info>已禁用任务：{$name}</info>"
                        : "<comment>禁用失败：{$name}</comment>");
                    break;

                case 'run':
                    $result = $service->runNow($name);
                    $output->writeln(sprintf(
                        '<info>执行完成：%s（状态=%s，退出码=%s，耗时=%.2fs）</info>',
                        $name,
                        (string) $result['status'],
                        (string) $result['exit_code'],
                        (float) $result['duration']
                    ));
                    if (!empty($result['error_output'])) {
                        $output->writeln('<error>' . (string) $result['error_output'] . '</error>');
                    }

                    return $result['status'] === 'success' ? 0 : 1;
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return 1;
        }

        return 0;
    }

    /**
     * 收集命令行参数（空值不参与更新）
     */
    private function payload(Input $input, bool $forCreate): array
    {
        $data = ['name' => (string) $input->getArgument('name')];

        $map = [
            'command'          => 'command',
            'schedule'         => 'schedule',
            'cron'             => 'cron_expression',
            'title'            => 'title',
            'timeout'          => 'timeout',
            'max-retries'      => 'max_retries',
        ];

        foreach ($map as $option => $key) {
            $value = $input->getOption($option);
            if ($value !== null && $value !== '') {
                $data[$key] = $value;
            }
        }

        if ($input->getOption('allow-concurrent')) {
            $data['allow_concurrent'] = true;
        }

        if ($forCreate) {
            $data['enabled'] = true;
        }

        return $data;
    }
}
