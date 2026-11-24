<?php

declare(strict_types=1);

namespace wise\crontab\test;

use wise\crontab\model\MaintenanceWindowModel;

/**
 * 维护窗口匹配测试
 */
class MaintenanceWindowTest extends DatabaseTestCase
{
    private function addWindow(array $data): void
    {
        MaintenanceWindowModel::create(array_merge([
            'task_id'      => null,
            'start_at'     => '02:00:00',
            'end_at'       => '04:00:00',
            'days_of_week' => '*',
            'reason'       => '维护',
        ], $data));
    }

    public function testNoWindowReturnsNull(): void
    {
        $this->assertNull(MaintenanceWindowModel::isInWindow(null, new \DateTime('2026-09-18 03:00:00')));
    }

    public function testGlobalWindowBlocksAnyTask(): void
    {
        $this->addWindow([]);

        $at = new \DateTime('2026-09-18 03:00:00');

        $this->assertNotNull(MaintenanceWindowModel::isInWindow(null, $at), '全局窗口应拦截无 ID 任务');
        $this->assertNotNull(MaintenanceWindowModel::isInWindow(999, $at), '全局窗口应拦截任意任务');
    }

    /**
     * 回归：任务专用窗口不应拦截其它任务（旧实现在 taskId=null 时会返回全部窗口）
     */
    public function testTaskSpecificWindowDoesNotBlockOtherTasks(): void
    {
        $this->addWindow(['task_id' => 7, 'start_at' => '02:00:00', 'end_at' => '04:00:00']);

        $at = new \DateTime('2026-09-18 03:00:00');

        $this->assertNotNull(MaintenanceWindowModel::isInWindow(7, $at), '窗口所属任务应被拦截');
        $this->assertNull(MaintenanceWindowModel::isInWindow(8, $at), '其它任务不应被拦截');
        $this->assertNull(MaintenanceWindowModel::isInWindow(null, $at), '无 ID 任务不应被任务专用窗口拦截');
    }

    public function testCrossMidnightWindow(): void
    {
        $this->addWindow(['start_at' => '22:00:00', 'end_at' => '02:00:00']);

        $this->assertNotNull(MaintenanceWindowModel::isInWindow(null, new \DateTime('2026-09-18 23:00:00')));
        $this->assertNotNull(MaintenanceWindowModel::isInWindow(null, new \DateTime('2026-09-18 01:00:00')));
        $this->assertNull(MaintenanceWindowModel::isInWindow(null, new \DateTime('2026-09-18 12:00:00')));
    }

    public function testDayOfWeekRange(): void
    {
        $this->addWindow(['days_of_week' => '1-5', 'start_at' => '00:00:00', 'end_at' => '23:59:00']);

        $this->assertNotNull(MaintenanceWindowModel::isInWindow(null, new \DateTime('2026-09-21 10:00:00')));
        $this->assertNull(MaintenanceWindowModel::isInWindow(null, new \DateTime('2026-09-20 10:00:00')));
    }

    public function testDayOfWeekList(): void
    {
        $this->addWindow(['days_of_week' => '0,6', 'start_at' => '00:00:00', 'end_at' => '23:59:00']);

        $this->assertNotNull(MaintenanceWindowModel::isInWindow(null, new \DateTime('2026-09-20 10:00:00')));
        $this->assertNull(MaintenanceWindowModel::isInWindow(null, new \DateTime('2026-09-21 10:00:00')));
    }
}
