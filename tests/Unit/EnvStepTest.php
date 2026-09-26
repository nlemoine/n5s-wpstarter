<?php

declare(strict_types=1);

namespace n5s\WpStarter\Tests\Unit;

use n5s\WpStarter\Tests\Support\FakeProject;
use n5s\WpStarter\Tests\Support\Steps;
use PHPUnit\Framework\TestCase;
use WeCodeMore\WpStarter\Step\Step;

final class EnvStepTest extends TestCase
{
    public function testCopiesTheExampleWhenDotEnvIsMissing(): void
    {
        $project = FakeProject::create([
            '.env.example' => "DB_NAME=example\n",
        ]);
        $config = $project->config();
        $step = Steps::env($config);

        self::assertTrue($step->allowed($config, $project->paths()));
        self::assertSame(Step::SUCCESS, $step->run($config, $project->paths()));
        self::assertSame("DB_NAME=example\n", $project->read('.env'));
    }

    public function testIsNotAllowedWhenDotEnvExists(): void
    {
        $project = FakeProject::create([
            '.env.example' => "DB_NAME=example\n",
            '.env' => "DB_NAME=mine\n",
        ]);
        $config = $project->config();

        self::assertFalse(Steps::env($config)->allowed($config, $project->paths()));
    }

    public function testIsNotAllowedWithoutAnExample(): void
    {
        $project = FakeProject::create([]);
        $config = $project->config();

        self::assertFalse(Steps::env($config)->allowed($config, $project->paths()));
    }

    public function testResolvesTheFilesFromTheEnvDirAndEnvFileSettings(): void
    {
        $project = FakeProject::create([
            'env/.env.custom.example' => "DB_NAME=example\n",
        ]);
        $config = $project->config([
            'env-dir' => 'env',
            'env-file' => '.env.custom',
        ]);
        $step = Steps::env($config);

        self::assertTrue($step->allowed($config, $project->paths()));
        self::assertSame(Step::SUCCESS, $step->run($config, $project->paths()));
        self::assertSame("DB_NAME=example\n", $project->read('env/.env.custom'));
        self::assertFalse($project->has('.env'));
    }

    public function testNamesTheFileInItsMessages(): void
    {
        $project = FakeProject::create([]);
        $step = Steps::env($project->config([
            'env-file' => '.env.custom',
        ]));

        self::assertStringContainsString('.env.custom', $step->success());
        self::assertStringContainsString('.env.custom.example', $step->success());
        self::assertStringContainsString('.env.custom', $step->error());
    }
}
