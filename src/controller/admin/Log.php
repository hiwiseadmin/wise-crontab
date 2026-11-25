<?php

declare(strict_types=1);

namespace wise\crontab\controller\admin;

use app\admin\controller\BaseController;
use app\common\view\ViewScope;
use wise\crontab\model\TaskLogModel;
use wise\crontab\service\TaskAdminService;

/**
 * 定时任务执行日志＼�
 *
 * 数据源 `crontab_task_log`（由 `TaskExecutor` 在每次执行时写入，含
 * running / success / failure / skipped 四种状态）。
 *
 * 只读页面：列表 + 详情（耗时 / 输出 / 异常），不提供删除入口
 * （清理走 `php think wise:crontab:clean`，避免后台误删审计证据）。
 */
class Log extends BaseController
{
    protected const VIEW_SCOPE = 'wise-crontab';

    private const MAX_PAGE_SIZE = 100;

    protected function initialize(): void
    {
        parent::initialize();

        ViewScope::activate(self::VIEW_SCOPE);
    }

    /**
     * 日志列表页
     */
    public function index()
    {
        $this->assign([
            'task_names' => $this->taskNames(),
            'timezone'   => (string) config('app.default_timezone', date_default_timezone_get()),
        ]);

        return $this->fetch('admin/log/index');
    }

    /**
     * 日志数据（GET）
     */
    public function getList()
    {
        $page  = max(1, (int) $this->request->param('page', 1));
        $limit = min(self::MAX_PAGE_SIZE, max(1, (int) $this->request->param('limit', 20)));

        $filters = [
            'task_name' => trim((string) $this->request->param('task_name', '')),
            'status'    => trim((string) $this->request->param('status', '')),
            'keyword'   => trim((string) $this->request->param('keyword', '')),
        ];

        return $this->success('ok', $this->service()->logs($filters, $page, $limit));
    }

    /**
     * 单条日志详情（GET，弹层展示）
     */
    public function detail()
    {
        $id  = (int) $this->request->param('id', 0);
        $log = $this->service()->logDetail($id);

        if ($log === null) {
            return $this->error('日志不存在或已被清理');
        }

        return $this->success('ok', $log);
    }


    private function service(): TaskAdminService
    {
        return app(TaskAdminService::class);
    }

    /**
     * 出现过的任务名（筛选下拉）
     *
     * @return array<int,string>
     */
    private function taskNames(): array
    {
        try {
            $names = TaskLogModel::distinct(true)->limit(500)->column('task_name');
        } catch (\Throwable) {
            return [];
        }

        $names = array_values(array_unique(array_filter(array_map('strval', (array) $names))));
        sort($names);

        return $names;
    }
}
