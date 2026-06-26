<?php

declare(strict_types=1);

namespace wise\crontab\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\crontab\trait\CommandTrait;

class Publish extends Command
{
    use CommandTrait;

    protected function configure(): void
    {
        $this->setName('wise:crontab:publish')
            ->setDescription('发布定时任务配置文件及迁移文件')
            ->addOption('force', 'f', Option::VALUE_NONE, '强制覆盖已存在的配置文件');
    }

    protected function getPackageConfigName(): string
    {
        return 'wise-crontab';
    }

    protected function execute(Input $input, Output $output): int
    {
        $force = $input->getOption('force');

        $this->printBanner($output, 'wise-crontab');

        $this->publishConfig($output, $force);

        $migrationsPublished = $this->publishMigrations($output);

        $this->printSuccessFooter($output, '定时任务', $migrationsPublished);

        return 0;
    }
}
