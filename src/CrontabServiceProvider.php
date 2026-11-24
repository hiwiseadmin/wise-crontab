<?php

declare(strict_types=1);

namespace wise\crontab;


use wise\support\traits\RegistersMigrations;

use think\facade\Event;
use wise\support\traits\ServiceTrait;
use wise\crontab\command\Clean;
use wise\crontab\command\DependencyManage;
use wise\crontab\command\ListTasks;
use wise\crontab\command\Run;
use wise\crontab\command\Stats;
use wise\crontab\command\TaskManage;
use wise\crontab\command\WindowManage;
use wise\crontab\event\CrontabAlertEvent;
use wise\crontab\service\TaskAdminService;

class CrontabServiceProvider extends \think\Service
{

    use \wise\support\traits\RegistersMigrations;
    use ServiceTrait;

    /** 请求级状态重置器注册名 */
    private const STATE_RESETTER_NAME = 'wise-crontab';

    public function register(): void
    {
        $this->mergeConfig('wise-crontab');

        $this->app->bind(CrontabManager::class, function () {
            return new CrontabManager($this->app->config->get('wise-crontab', []));
        });

        $this->app->bind(TaskAdminService::class, function () {
            return new TaskAdminService($this->app->config->get('wise-crontab', []));
        });

        $this->commands([
            Run::class,
            Clean::class,
            Stats::class,
            ListTasks::class,
            TaskManage::class,
            WindowManage::class,
            DependencyManage::class,
        ]);
    }

    public function boot(): void
    {
        $this->registerMigrations();
        Event::listen(CrontabAlertEvent::class, function (CrontabAlertEvent $event) {
        });

        $this->registerAdminMenu();

        $this->registerAdminRoutes();

        $this->registerQueueHandlers();

        $this->registerRequestStateResetter();

        $this->registerMcpProvider();
    }

    /**
     * 注册请求级状态重置器
     *
     * CrontabManager 持有「一轮调度」维度的实例状态（scheduleErrors / windowWarningLogged），
     * 而容器绑定是单例：常驻 worker 里不重置就会跨请求残留（详见 CrontabStateResetter 注释）。
     */
    protected function registerRequestStateResetter(): void
    {
        if (!class_exists(\wise\support\context\RequestStateRegistry::class)
            || !interface_exists(\wise\support\contracts\RequestStateResetterInterface::class)) {
            return;
        }

        try {
            \wise\support\context\RequestStateRegistry::register(
                self::STATE_RESETTER_NAME,
                \wise\crontab\RequestState\CrontabStateResetter::class,
                350
            );
        } catch (\Throwable $e) {
            $this->warn('请求级状态重置器注册失败: ' . $e->getMessage());
        }
    }

    /**
     * 声明后台菜单（任务列表 / 执行日志）
     *
     * 菜单必须在 boot() 注册 —— 扩展点由 wise-support 在 register 阶段 definePoint，
     * register() 期注册会因「契约未定义」失败。
     */
    protected function registerAdminMenu(): void
    {
        if (!interface_exists(\wise\support\contracts\MenuItemProvider::class)
            || !class_exists(\wise\support\ExtPoint::class)
            || !class_exists(\wise\support\ExtPoints::class)) {
            return;
        }

        try {
            \wise\support\ExtPoint::register(
                \wise\support\ExtPoints::MENU_ITEM,
                'wise-crontab',
                \wise\crontab\menu\CrontabMenuProvider::class
            );
        } catch (\Throwable $e) {
            $this->warn('菜单注册失败: ' . $e->getMessage());
        }
    }

    /**
     * 注册后台路由（/admin/crontab/**）
     */
    protected function registerAdminRoutes(): void
    {
        if (!class_exists(\wise\support\router\RouteRegistrar::class)) {
            return;
        }

        try {
            RouteRegistrar::register();
        } catch (\Throwable $e) {
            $this->warn('后台路由注册失败: ' . $e->getMessage());
        }
    }

    /**
     * 注册「立即执行」队列 handler
     *
     * 宿主队列服务不可用时静默跳过（脱离 WiseAdmin 的独立使用时无后台入口，不影响调度）。
     */
    protected function registerQueueHandlers(): void
    {
        if (!class_exists(\app\common\service\QueueService::class)
            || !class_exists(\wise\crontab\job\RunTaskJob::class)) {
            return;
        }

        try {
            \app\common\service\QueueService::registerHandler(
                \wise\crontab\job\RunTaskJob::HANDLER,
                static function (array $data, $context = null): array {
                    return (new \wise\crontab\job\RunTaskJob())->run($data, $context);
                }
            );
        } catch (\Throwable $e) {
            $this->warn('队列 handler 注册失败: ' . $e->getMessage());
        }
    }

    /**
     * 统一告警输出（优先内核日志，缺失时退 error_log）
     */
    protected function warn(string $message): void
    {
        try {
            if (class_exists(\wise\support\context\ContextualLogger::class)) {
                \wise\support\context\ContextualLogger::warning('[wise-crontab] ' . $message, ['tag' => 'crontab']);
                return;
            }
        } catch (\Throwable) {
        }

        error_log('[wise-crontab] ' . $message);
    }

    /**
     * 将定时任务能力以 MCP 工具暴露（可选集成）
     *
     * 仅当 wiseadmin/wise-mcp 已安装时生效；未安装时静默跳过。
     */
    protected function registerMcpProvider(): void
    {
        if (!interface_exists(\wise\mcp\contract\ToolProviderInterface::class)) {
            return;
        }
        if (!class_exists(\wise\support\ExtPoint::class)) {
            return;
        }

        try {
            \wise\support\ExtPoint::register(
                \wise\mcp\contract\ToolProviderInterface::EXT_POINT,
                'crontab',
                \wise\crontab\mcp\CrontabToolProvider::class
            );
        } catch (\Throwable $e) {
            $this->warn('注册 MCP 提供者失败: ' . $e->getMessage());
        }
    }
}
