<?php

declare(strict_types=1);

namespace wise\crontab\event;

class BeforeTaskExecute
{
    public function __construct(
        public readonly string $taskName,
        public readonly string $command,
        public readonly string $cronExpression
    ) {
    }
}
