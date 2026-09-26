<?php

declare(strict_types=1);

namespace n5s\WpStarter\Tests\Unit;

use n5s\WpStarter\Tests\Support\FakeProject;
use n5s\WpStarter\Tests\Support\Steps;
use n5s\WpStarter\WpCliConfigStep;
use PHPUnit\Framework\TestCase;
use WeCodeMore\WpStarter\Step\FileCreationStepInterface;
use WeCodeMore\WpStarter\Step\Step;

final class WpCliConfigStepTest extends TestCase
{
    private FakeProject $project;

    protected function setUp(): void
    {
        $this->project = FakeProject::create([]);
    }

    public function testReplacesTheNativeStepByName(): void
    {
        self::assertSame('build-wp-cli-yml', (new \ReflectionClass(WpCliConfigStep::class))->getConstant('NAME'));
    }

    public function testIsAFileCreationStepSoPreventOverwriteApplies(): void
    {
        $step = Steps::wpCliConfig();

        self::assertInstanceOf(FileCreationStepInterface::class, $step);
        self::assertSame($this->project->path('wp-cli.yml'), $step->targetPath($this->project->paths()));
    }

    public function testCreatesTheFileWhenMissing(): void
    {
        self::assertSame(Step::SUCCESS, $this->runStep());
        self::assertSame("path: public/wp\n", $this->project->read('wp-cli.yml'));
    }

    public function testRewritesOnlyTheFirstPathLineAndKeepsTheRest(): void
    {
        $this->project->write('wp-cli.yml', "path: old/wp\n\n'@production':\n  ssh: user@host/site\n  path: old/wp\n");

        self::assertSame(Step::SUCCESS, $this->runStep());
        self::assertSame(
            "path: public/wp\n\n'@production':\n  ssh: user@host/site\n  path: old/wp\n",
            $this->project->read('wp-cli.yml')
        );
    }

    public function testPrependsThePathLineWhenThereIsNone(): void
    {
        $this->project->write('wp-cli.yml', "'@production':\n  ssh: user@host/site\n");

        self::assertSame(Step::SUCCESS, $this->runStep());
        self::assertSame("path: public/wp\n'@production':\n  ssh: user@host/site\n", $this->project->read('wp-cli.yml'));
    }

    public function testDoesNothingWhenThePathIsAlreadyRight(): void
    {
        $this->project->write('wp-cli.yml', "path: public/wp\nurl: https://example.com\n");

        self::assertSame(Step::NONE, $this->runStep());
        self::assertSame("path: public/wp\nurl: https://example.com\n", $this->project->read('wp-cli.yml'));
    }

    public function testAnEmptyPathValueDoesNotSwallowTheNextLine(): void
    {
        $this->project->write('wp-cli.yml', "path: \nurl: https://example.com\n");

        self::assertSame(Step::SUCCESS, $this->runStep());
        self::assertSame("path: public/wp\nurl: https://example.com\n", $this->project->read('wp-cli.yml'));
    }

    private function runStep(): int
    {
        return Steps::wpCliConfig()->run($this->project->config(), $this->project->paths());
    }
}
