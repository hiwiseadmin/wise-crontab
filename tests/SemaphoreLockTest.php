<?php

declare(strict_types=1);

namespace wise\crontab\test;

use wise\crontab\SemaphoreLock;
use wise\crontab\TaskLock;

/**
 * 全局信号量测试
 */
class SemaphoreLockTest extends TestCase
{
    private string $lockDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockDir = sys_get_temp_dir() . '/wise-crontab-sem-' . uniqid();
        mkdir($this->lockDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->lockDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->lockDir);

        parent::tearDown();
    }

    public function testAcquireAndReleaseSlots(): void
    {
        $lock = new TaskLock($this->lockDir, 3600);
        $semaphore = new SemaphoreLock($lock->getLock(), 2);

        $this->assertSame(2, $semaphore->availableSlots());

        $first = $semaphore->acquireSlot();
        $second = $semaphore->acquireSlot();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
        $this->assertSame(0, $semaphore->availableSlots());

        $this->assertNull($semaphore->acquireSlot());

        $semaphore->releaseSlot('global', $first);
        $this->assertSame(1, $semaphore->availableSlots());
        $this->assertNotNull($semaphore->acquireSlot());
    }

    public function testReleaseAllSlots(): void
    {
        $lock = new TaskLock($this->lockDir, 3600);
        $semaphore = new SemaphoreLock($lock->getLock(), 3);

        $semaphore->acquireSlot();
        $semaphore->acquireSlot();

        $semaphore->releaseAllSlots('global');

        $this->assertSame(3, $semaphore->availableSlots());
    }
}
