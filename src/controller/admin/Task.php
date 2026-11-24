<?php

declare(strict_types=1);

namespace wise\crontab\controller\admin;

use app\admin\controller\BaseController;
use app\common\view\ViewScope;
use wise\crontab\exception\CrontabException;
use wise\crontab\job\RunTaskJob;
use wise\crontab\service\TaskAdminService;

/**
 * 定时任务后台页面＼�
 *
 * 权限：节点由 `menu/CrontabMenuProvider` 声明并在 `menu:sync` 时按 path 派生，
 * 本控制器不写权限注解（admin 应用是 RBAC 白名单 + 方法敏感，见 RouteRegistrar 注释）。
 *
 * 破坏性动作（停用 / 删除）按 DoD 要求**后端校验一次性确认令牌**：
 * 前端先 POST `task/issuetoken` 拿 token，再随操作一起提交。
 * 令牌实现复用宿主 `app\common\service\ConfirmTokenService`（脱离宿主时不可用，
 * 但该页面本身依赖宿主 BaseController，属于同一前提）。
 *
 * 审计：保存 / 启停 / 立即执行 / 删除均写 `wise-operation-log`。
 */
class Task extends BaseController
{
    /** 包内视图 scope（view/admin/**） */
    protected const VIEW_SCOPE = 'wise-crontab';

    /** 单页最大条数 */
    private const MAX_PAGE_SIZE = 100;

    /** 允许签发确认令牌的场景（白名单，避免任意场景签发） */
    private const TOKEN_SCENES = ['crontab.task_disable', 'crontab.task_delete'];

    protected function initialize(): void
    {
        parent::initialize();

        ViewScope::activate(self::VIEW_SCOPE);
    }

    /**
     * 任务列表页
     */
    public function index()
    {
        $this->assign([
            'schedule_error_count' => count($this->service()->scheduleErrors()),
            'timezone'             => (string) config('app.default_timezone', date_default_timezone_get()),
        ]);

        return $this->fetch('admin/task/index');
    }

    /**
     * 任务列表数据（GET）
     *
     * 配置任务与数据库任务合并返回（与调度器同口径）；`errors` 为调度表达式错误面板数据。
     */
    public function getList()
    {
        $page  = max(1, (int) $this->request->param('page', 1));
        $limit = min(self::MAX_PAGE_SIZE, max(1, (int) $this->request->param('limit', 20)));

        $filters = [
            'status'  => $this->request->param('status', ''),
            'keyword' => trim((string) $this->request->param('keyword', '')),
        ];

        $data           = $this->service()->paginate($filters, $page, $limit);
        $data['errors'] = $this->service()->scheduleErrors();

        return $this->success('ok', $data);
    }

    /**
     * 新增 / 编辑保存（POST）
     */
    #[\wise\operationlog\attribute\OperationLog(
        template: '保存定时任务 {context.crontab_task}（{context.crontab_task_op}）',
        tags: ['admin', 'crontab']
    )]
    public function save()
    {
        $id     = (int) $this->request->param('id', 0);
        $name   = trim((string) $this->request->param('name', ''));
        $status = $this->request->param('status', 1);

        $payload = [
            'name'             => $name,
            'title'            => trim((string) $this->request->param('title', '')) ?: $name,
            'description'      => (string) $this->request->param('description', ''),
            'command'          => trim((string) $this->request->param('command', '')),
            'schedule'         => trim((string) $this->request->param('schedule', '')),
            'timeout'          => (int) $this->request->param('timeout', 300),
            'max_retries'      => (int) $this->request->param('max_retries', 0),
            'allow_concurrent' => (int) $this->request->param('allow_concurrent', 0),
            'status'           => $status,
        ];

        if ($id > 0) {
            $current = $this->service()->findById($id);
            if ($current === null) {
                return $this->error('任务不存在或已被删除');
            }
            $payload['name'] = (string) $current['name'];
            $name            = $payload['name'];
        }

        try {
            $task = $id > 0
                ? $this->service()->update($name, $payload)
                : $this->service()->create($payload);
        } catch (CrontabException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            return $this->error('保存失败，请稍后重试');
        }

        $this->setLogContext([
            'crontab_task'    => (string) ($task['name'] ?? $name),
            'crontab_task_op' => $id > 0 ? '编辑' : '新增',
        ]);

        return $this->success($id > 0 ? '已保存' : '已创建', $task);
    }

    /**
     * 启停（POST）
     *
     * 停用属破坏性动作 → 必须携带 `confirm_token`（scene=crontab.task_disable）；
     * 启用无副作用，不要求令牌。
     */
    #[\wise\operationlog\attribute\OperationLog(
        template: '启停定时任务 {context.crontab_task} → {context.crontab_task_op}',
        tags: ['admin', 'crontab'],
        level: 'WARNING'
    )]
    public function toggle()
    {
        $name   = trim((string) $this->request->param('name', ''));
        $status = (int) $this->request->param('status', 0);

        if ($name === '') {
            return $this->error('请指定任务');
        }

        if ($status === 0) {
            $denied = $this->verifyConfirmToken('crontab.task_disable', ['name' => $name]);
            if ($denied !== null) {
                return $denied;
            }
        }

        try {
            $status === 1 ? $this->service()->enable($name) : $this->service()->disable($name);
        } catch (CrontabException $e) {
            return $this->error($e->getMessage());
        }

        $this->setLogContext([
            'crontab_task'    => $name,
            'crontab_task_op' => $status === 1 ? '启用' : '停用',
        ]);

        return $this->success($status === 1 ? '已启用' : '已停用');
    }

    /**
     * 立即执行（POST，异步）
     *
     * HTTP 路径只做「限流 + 投队列」，真正执行在 worker（见 RunTaskJob）。
     */
    #[\wise\operationlog\attribute\OperationLog(
        template: '立即执行定时任务 {context.crontab_task}',
        tags: ['admin', 'crontab'],
        level: 'WARNING'
    )]
    public function run()
    {
        $name = trim((string) $this->request->param('name', ''));
        if ($name === '') {
            return $this->error('请指定任务');
        }

        $task = $this->service()->find($name);
        if ($task === null) {
            return $this->error('该任务来自配置文件，暂不支持手动执行');
        }

        $throttle = max(0, (int) config('wise-crontab.manual_run_throttle', 60));
        $cacheKey = 'crontab:manual_run:' . md5($name);
        if ($throttle > 0) {
            $last = \think\facade\Cache::get($cacheKey);
            if ($last) {
                $wait = max(1, $throttle - (time() - (int) $last));

                return $this->error("该任务刚触发过，请 {$wait} 秒后再试");
            }
        }

        try {
            $async = \app\common\service\QueueService::instance()->dispatch(
                RunTaskJob::HANDLER,
                ['name' => $name],
                ['name' => '定时任务：' . $name, 'created_by' => $this->currentAdminId()]
            );
        } catch (\Throwable $e) {
            return $this->error('入队失败：' . $e->getMessage());
        }

        if ($throttle > 0) {
            \think\facade\Cache::set($cacheKey, time(), $throttle);
        }

        $this->setLogContext([
            'crontab_task'    => $name,
            'crontab_task_op' => '立即执行',
        ]);

        return $this->success('已提交执行，稍后可在执行日志查看结果', [
            'task_name'     => $name,
            'async_task_id' => $async?->id,
            'task_no'       => $async?->task_no,
        ]);
    }

    /**
     * 删除任务（POST，需确认令牌）
     */
    #[\wise\operationlog\attribute\OperationLog(
        template: '删除定时任务 {context.crontab_task}',
        tags: ['admin', 'crontab'],
        level: 'WARNING'
    )]
    public function delete()
    {
        $name = trim((string) $this->request->param('name', ''));
        if ($name === '') {
            return $this->error('请指定任务');
        }

        $denied = $this->verifyConfirmToken('crontab.task_delete', ['name' => $name]);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $this->service()->delete($name);
        } catch (CrontabException $e) {
            return $this->error($e->getMessage());
        }

        $this->setLogContext([
            'crontab_task'    => $name,
            'crontab_task_op' => '删除',
        ]);

        return $this->success('已删除');
    }

    /**
     * 签发一次性确认令牌（POST）
     */
    public function issueToken()
    {
        $scene = trim((string) $this->request->param('scene', ''));
        $name  = trim((string) $this->request->param('name', ''));

        if (!in_array($scene, self::TOKEN_SCENES, true)) {
            return $this->error('不支持的确认场景');
        }
        if ($name === '') {
            return $this->error('请指定任务');
        }

        $normalized = $scene === 'crontab.task_disable' ? 'crontab.task_disable' : 'crontab.task_delete';

        $service = $this->confirmTokenService();
        if ($service === null) {
            return $this->error('确认令牌服务不可用');
        }

        return $this->success('ok', [
            'confirm_token' => $service->issue($normalized, ['name' => $name]),
            'scene'         => $normalized,
        ]);
    }


    private function service(): TaskAdminService
    {
        return app(TaskAdminService::class);
    }

    /**
     * 校验一次性确认令牌
     *
     * @return \think\response\Json|null 失败时返回错误响应，通过返回 null
     */
    private function verifyConfirmToken(string $scene, array $context)
    {
        $service = $this->confirmTokenService();
        if ($service === null) {
            return $this->error('确认令牌服务不可用，已拒绝该操作');
        }

        $token = trim((string) $this->request->param('confirm_token', ''));
        if ($token === '' || !$service->verify($scene, $token, $context)) {
            return $this->error('确认已失效，请重新确认后操作');
        }

        return null;
    }

    /** 宿主确认令牌服务（脱离宿主时为 null） */
    private function confirmTokenService(): ?object
    {
        if (!class_exists(\app\common\service\ConfirmTokenService::class)) {
            return null;
        }

        return new \app\common\service\ConfirmTokenService();
    }

    /**
     * 写入操作日志变量池（供 #[OperationLog] 模板渲染）
     *
     * @param array<string,string> $vars
     */
    private function setLogContext(array $vars): void
    {
        if (!class_exists(\wise\operationlog\context\LogContext::class)) {
            return;
        }

        foreach ($vars as $key => $value) {
            \wise\operationlog\context\LogContext::set($key, $value);
        }
    }

}
