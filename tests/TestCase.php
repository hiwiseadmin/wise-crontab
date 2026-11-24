<?php

declare(strict_types=1);

namespace wise\crontab\test;

use PHPUnit\Framework\TestCase as PhpUnitTestCase;

abstract class TestCase extends PhpUnitTestCase
{
    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
