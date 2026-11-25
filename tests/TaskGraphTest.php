<?php

declare(strict_types=1);

namespace wise\crontab\test;

use wise\crontab\model\TaskDependencyModel;
use wise\crontab\TaskGraph;

/**
 * 任务依赖图测试
 */
class TaskGraphTest extends DatabaseTestCase
{
    public function testConfigTasksWithoutIdAreAlwaysRunnable(): void
    {
        $tasks = [
            ['name' => 'a', 'cron_expression' => '* * * * *'],
            ['name' => 'b', 'cron_expression' => '* * * * *'],
        ];

        $this->assertCount(2, (new TaskGraph())->getRunnableTasks($tasks));
    }

    public function testTasksWithoutDependenciesAreRunnable(): void
    {
        $task = $this->createTask();

        $this->assertCount(1, (new TaskGraph())->getRunnableTasks([$task]));
    }

    public function testSuccessDependencyBlockedWhenDependencyFailed(): void
    {
        $dep = $this->createTask();
        $task = $this->createTask();

        TaskDependencyModel::create([
            'task_id'            => (int) $task['id'],
            'depends_on_task_id' => (int) $dep['id'],
            'condition'          => 'success',
        ]);

        $this->createLog(['task_id' => (int) $dep['id'], 'task_name' => $dep['name'], 'status' => 'failure']);

        $this->assertSame([], (new TaskGraph())->getRunnableTasks([$task]));
    }

    public function testSuccessDependencyPassedWhenDependencySucceeded(): void
    {
        $dep = $this->createTask();
        $task = $this->createTask();

        TaskDependencyModel::create([
            'task_id'            => (int) $task['id'],
            'depends_on_task_id' => (int) $dep['id'],
            'condition'          => 'success',
        ]);

        $this->createLog(['task_id' => (int) $dep['id'], 'task_name' => $dep['name'], 'status' => 'success']);

        $this->assertCount(1, (new TaskGraph())->getRunnableTasks([$task]));
    }

    public function testCompletedDependencyPassesAfterFailure(): void
    {
        $dep = $this->createTask();
        $task = $this->createTask();

        TaskDependencyModel::create([
            'task_id'            => (int) $task['id'],
            'depends_on_task_id' => (int) $dep['id'],
            'condition'          => 'completed',
        ]);

        $this->createLog(['task_id' => (int) $dep['id'], 'task_name' => $dep['name'], 'status' => 'failure']);

        $this->assertCount(1, (new TaskGraph())->getRunnableTasks([$task]));
    }

    public function testUnmetDependencyRequiresAtLeastOneExecution(): void
    {
        $dep = $this->createTask();
        $task = $this->createTask();

        TaskDependencyModel::create([
            'task_id'            => (int) $task['id'],
            'depends_on_task_id' => (int) $dep['id'],
            'condition'          => 'any',
        ]);

        $this->assertSame([], (new TaskGraph())->getRunnableTasks([$task]));
    }

    public function testDetectCycles(): void
    {
        $a = $this->createTask();
        $b = $this->createTask();

        TaskDependencyModel::create(['task_id' => (int) $a['id'], 'depends_on_task_id' => (int) $b['id']]);
        TaskDependencyModel::create(['task_id' => (int) $b['id'], 'depends_on_task_id' => (int) $a['id']]);

        $cycles = (new TaskGraph())->detectCycles();

        $this->assertCount(1, $cycles);
        $this->assertSame([(int) $a['id'], (int) $b['id']], $cycles[0]);
    }

    public function testDetectCyclesReturnsEmptyForAcyclicGraph(): void
    {
        $a = $this->createTask();
        $b = $this->createTask();

        TaskDependencyModel::create(['task_id' => (int) $a['id'], 'depends_on_task_id' => (int) $b['id']]);

        $this->assertSame([], (new TaskGraph())->detectCycles());
    }
}
