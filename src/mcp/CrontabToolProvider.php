<?php
declare(strict_types=1);

namespace wise\crontab\mcp;

use wise\crontab\model\TaskModel;
use wise\crontab\Schedule;
use wise\mcp\contract\ToolProviderInterface;
use wise\mcp\dto\ToolDefinition;

/**
 * wise-crontab 的 MCP 工具提供者（只读）
 *
 * 把定时任务以 MCP 工具暴露给外部 MCP 客户端，使其能够查询任务定义与调度表达式。
 *
 * 由 CrontabServiceProvider::boot() 注册到 ToolProviderInterface::EXT_POINT；
 * 需安装 wiseadmin/wise-mcp 才会生效。
 *
 * 说明：执行器直接返回数组即可，ToolDefinition::call() 会自动 JSON 编码；
 * 失败时返回 ToolDefinition::error()，无需自定义 json()/error() 辅助方法。
 */
final class CrontabToolProvider implements ToolProviderInterface
{
    public function getNamespace(): string
    {
        return 'crontab';
    }

    /**
     * @return ToolDefinition[]
     */
    public function listTools(): array
    {
        return [
            new ToolDefinition(
                name: 'crontab.list',
                description: '列出系统定时任务（名称、标题、命令、语义化调度/cron 表达式、状态）',
                inputSchema: [
                    'type'       => 'object',
                    'properties' => [
                        'status' => ['type' => 'integer', 'description' => '按状态过滤（可选）'],
                        'limit'  => ['type' => 'integer', 'description' => '返回条数上限（默认 50）'],
                    ],
                ],
                handler: fn (array $args): array => $this->listTasks($args),
                meta: ['source' => 'wise-crontab'],
            ),
            new ToolDefinition(
                name: 'crontab.get',
                description: '按名称获取单个定时任务的详细信息',
                inputSchema: [
                    'type'       => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => '任务名称'],
                    ],
                    'required' => ['name'],
                ],
                handler: fn (array $args): array => $this->getTask((string) ($args['name'] ?? '')),
                meta: ['source' => 'wise-crontab'],
            ),
        ];
    }

    /**
     * @return array{tasks: array, count: int}|array{error: string}
     */
    private function listTasks(array $args): array
    {
        try {
            $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));

            $query = TaskModel::order('id', 'asc');
            if (isset($args['status']) && $args['status'] !== '') {
                $query = $query->where('status', (int) $args['status']);
            }

            $rows  = $query->limit($limit)->select()->toArray();
            $tasks = array_map(fn (array $row): array => $this->normalize($row), $rows);

            return ['tasks' => $tasks, 'count' => count($tasks)];
        } catch (\Throwable $e) {
            return ToolDefinition::error($e->getMessage());
        }
    }

    /**
     * @return array|array{error: string}
     */
    private function getTask(string $name): array
    {
        if ($name === '') {
            return ToolDefinition::error('缺少参数 name');
        }

        try {
            $row = TaskModel::where('name', $name)->find();
            if ($row === null) {
                return ToolDefinition::error("任务不存在: {$name}");
            }

            return $this->normalize($row->toArray());
        } catch (\Throwable $e) {
            return ToolDefinition::error($e->getMessage());
        }
    }

    /**
     * 只暴露非敏感字段
     */
    private function normalize(array $row): array
    {
        $schedule = (string) ($row['schedule'] ?? '');
        $cron     = (string) ($row['cron_expression'] ?? '');

        return [
            'id'              => $row['id'] ?? null,
            'name'            => (string) ($row['name'] ?? ''),
            'title'           => (string) ($row['title'] ?? ''),
            'description'     => (string) ($row['description'] ?? ''),
            'command'         => (string) ($row['command'] ?? ''),
            'schedule'        => $schedule,
            'cron_expression' => $cron,
            'schedule_human'  => Schedule::describe($schedule !== '' ? $schedule : $cron),
            'schedule_type'   => (string) ($row['schedule_type'] ?? 'cron'),
            'status'          => (int) ($row['status'] ?? 0),
        ];
    }
}
