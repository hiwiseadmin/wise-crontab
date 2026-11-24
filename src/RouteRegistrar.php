<?php

declare(strict_types=1);

namespace wise\crontab;

use think\facade\Route;
use wise\support\router\RouteRegistrar as BaseRouteRegistrar;

/**
 * 定时任务后台路由＼�
 *
 * 由 `CrontabServiceProvider::boot()` 调用 `RouteRegistrar::register()`；
 * 前缀 / 中间件一律走 `config('wise-crontab.web')`，**包源码不写死**。
 *
 * 最终 URL：`/admin/crontab/**`（admin 应用内相对前缀 `crontab`）
 *
 * ── 两条硬约束（同 wise-market）───────────────────────────────────────
 * 1. **权限方法由菜单 path 末段推断**（`config/menu_permission.php` 的 pattern）：
 *    缺省 GET。因此写入动作的末段必须是 pattern 里已声明为 POST 的词
 *    （save/toggle/delete 已声明；`run` / `issuetoken` 已在 pattern 中补登），
 *    否则非超管会 403。
 * 2. **路径一律小写**：`RbacMiddleware` 用 dispatche 后的 route 全小写匹配权限，
 *    路由规则、菜单 path、前端请求三处必须完全一致（PHP 方法名大小写不敏感，
 *    `Task@getlist` 可正常调用 `getList()`）。
 *
 * 后台页面只能挂在 admin 组 —— 投到其它应用会静默失去 RBAC 保护。
 *
 * @see \wise\crontab\CrontabServiceProvider::boot()
 * @see \wise\crontab\menu\CrontabMenuProvider
 */
class RouteRegistrar extends BaseRouteRegistrar
{
    /** 后台控制器命名空间前缀 */
    private const ADMIN = '\\wise\\crontab\\controller\\admin\\';

    protected string $configKey = 'wise-crontab';

    protected function define(array $config): void
    {
        if (!class_exists(\app\admin\controller\BaseController::class)) {
            return;
        }

        $this->group('admin', function (): void {
            Route::get('task/index', self::ADMIN . 'Task@index');
            Route::get('task/getlist', self::ADMIN . 'Task@getlist');
            Route::get('log/index', self::ADMIN . 'Log@index');
            Route::get('log/getlist', self::ADMIN . 'Log@getlist');
            Route::get('log/detail', self::ADMIN . 'Log@detail');

            Route::post('task/save', self::ADMIN . 'Task@save');
            Route::post('task/toggle', self::ADMIN . 'Task@toggle');
            Route::post('task/run', self::ADMIN . 'Task@run');
            Route::post('task/delete', self::ADMIN . 'Task@delete');
            Route::post('task/issuetoken', self::ADMIN . 'Task@issuetoken');
        });
    }
}
