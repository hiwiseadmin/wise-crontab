<?php

declare(strict_types=1);

namespace wise\crontab\menu;

use wise\support\contracts\MenuItemProvider;

/**
 * 定时任务后台菜单声明（ExtPoints::MENU_ITEM）
 *
 * 由宿主 `app\admin\service\MenuItemSync` 幂等落库到 `menus` 表，
 * 并按 `path` 末段派生权限节点（白名单模式下，未声明节点的路径非超管一律 403）。
 *
 * 落库命令：`php think menu:sync --package=wise-crontab`（后台也会懒同步）。
 *
 * ⚠️ 每个被前端调用的路径都必须有节点，且 path 与 RouteRegistrar 注册的规则**逐字一致**
 *    （全小写，见 RouteRegistrar 类注释第 2 条）。
 */
class CrontabMenuProvider implements MenuItemProvider
{
    /** 容器 key（仅在声明内使用，不落库） */
    private const CONTAINER = 'crontab';

    public function ident(): string
    {
        return 'wise-crontab';
    }

    public function containers(): array
    {
        return [
            [
                'key'    => self::CONTAINER,
                'name'   => '定时任务',
                'icon'   => 'bi bi-alarm',
                'anchor' => 'console',
                'sort'   => 780,
            ],
        ];
    }

    public function items(): array
    {
        return [
            [
                'container' => self::CONTAINER,
                'name'      => '任务列表',
                'icon'      => 'bi bi-list-task',
                'path'      => '/admin/crontab/task/index',
                'type'      => 'menu',
                'sort'      => 10,
                'children'  => [
                    ['name' => '任务数据', 'path' => '/admin/crontab/task/getlist', 'type' => 'button', 'sort' => 10],
                    ['name' => '保存任务', 'path' => '/admin/crontab/task/save', 'type' => 'button', 'sort' => 20],
                    ['name' => '启停任务', 'path' => '/admin/crontab/task/toggle', 'type' => 'button', 'sort' => 30],
                    ['name' => '立即执行', 'path' => '/admin/crontab/task/run', 'type' => 'button', 'sort' => 40],
                    ['name' => '删除任务', 'path' => '/admin/crontab/task/delete', 'type' => 'button', 'sort' => 50],
                    ['name' => '签发确认令牌', 'path' => '/admin/crontab/task/issuetoken', 'type' => 'button', 'sort' => 60],
                ],
            ],
            [
                'container' => self::CONTAINER,
                'name'      => '执行日志',
                'icon'      => 'bi bi-journal-text',
                'path'      => '/admin/crontab/log/index',
                'type'      => 'menu',
                'sort'      => 20,
                'children'  => [
                    ['name' => '日志数据', 'path' => '/admin/crontab/log/getlist', 'type' => 'button', 'sort' => 10],
                    ['name' => '日志详情', 'path' => '/admin/crontab/log/detail', 'type' => 'button', 'sort' => 20],
                ],
            ],
        ];
    }
}
