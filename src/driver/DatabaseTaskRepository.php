<?php

declare(strict_types=1);

namespace wise\crontab\driver;

use wise\crontab\exception\CrontabException;
use wise\crontab\TaskRepositoryInterface;
use wise\crontab\model\TaskModel;

class DatabaseTaskRepository implements TaskRepositoryInterface
{
    /** 表不存在时的 MySQL 错误号 / SQLSTATE（未执行迁移，属「功能未安装」） */
    private const TABLE_MISSING_MARKERS = ['42S02', '1146', "doesn't exist", 'no such table'];

    /**
     * 获取所有启用的任务
     */
    public function all(): array
    {
        $tasks = TaskModel::where('status', 1)->select();
        return $tasks->toArray();
    }

    /**
     * 根据任务名称查找启用的任务
     */
    public function findByName(string $name): ?array
    {
        $task = TaskModel::where('name', $name)
            ->where('status', 1)
            ->find();

        if ($task === null) {
            return null;
        }

        return $task->toArray();
    }

    /**
     * 检查数据库仓库是否可用
     *
     * 只有「表不存在」（未执行迁移）才允许返回 false —— 调度器这时跳过数据库任务，
     * 退回配置文件任务，属于合法的降级。
     *
     * 连接断开 / 密码错 / 权限不足 / 网络闪断这类故障必须抛出：旧实现把它们一并
     * 当成「不可用」返回 false，结果是数据库任务在调度里整批消失，CLI 仍显示
     * 「任务总数 1、全部成功」，运维完全无从察觉（Swoole/FPM 长连接被服务端
     * 掐断时最容易命中）。
     *
     * @throws CrontabException 数据库故障（非「表不存在」）
     */
    public function isAvailable(): bool
    {
        try {
            TaskModel::where('status', 1)->limit(1)->select();

            return true;
        } catch (\PDOException | \think\db\exception\PDOException $e) {
            $message = $e->getMessage();

            foreach (self::TABLE_MISSING_MARKERS as $marker) {
                if (stripos($message, $marker) !== false) {
                    return false;
                }
            }

            throw new CrontabException('数据库任务仓库不可用（调度已中止，避免整批任务被静默跳过）: ' . $message, 0, $e);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
