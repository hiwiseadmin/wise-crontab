<?php

declare(strict_types=1);

namespace wise\crontab\event;

class AfterTaskExecute
{
    public function __construct(
        public readonly string $taskName,
        public readonly string $command,
        public readonly int $exitCode,
        public readonly float $duration,
        public readonly string $output
    ) {
    }
}
