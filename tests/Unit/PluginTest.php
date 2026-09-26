<?php

declare(strict_types=1);

namespace n5s\WpStarter\Tests\Unit;

use Composer\Composer;
use Composer\Config;
use Composer\Config\JsonConfigSource;
use Composer\IO\BufferIO;
use Composer\Json\JsonFile;
use Composer\Package\RootPackage;
use n5s\WpStarter\EnvStep;
use n5s\WpStarter\Plugin;
use n5s\WpStarter\Tests\Support\FakeProject;
use n5s\WpStarter\WpCliConfigStep;
use n5s\WpStarter\WpConfigStep;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    private FakeProject $project;

    private BufferIO $io;

    protected function setUp(): void
    {
        $this->project = FakeProject::create([]);
        $this->io = new BufferIO();
    }

    public function testFillsTheLayoutAndWpStarterDefaults(): void
    {
        $extra = $this->activate([]);

        self::assertSame('public/app', $extra['wordpress-content-dir']);
        self::assertSame('public/wp', $extra['wordpress-install-dir']);
        self::assertSame(
            ['public/app/mu-plugins/{$name}', 'public/app/plugins/{$name}', 'public/app/themes/{$name}'],
            array_keys($extra['installer-paths'])
        );
        self::assertSame('config', $extra['wpstarter']['env-bootstrap-dir']);
        self::assertFalse($extra['wpstarter']['env-example']);
        self::assertSame('', $this->io->getOutput());
    }

    public function testRegistersTheStepsUnderTheirOwnNames(): void
    {
        $steps = $this->activate([])['wpstarter']['custom-steps'];

        foreach ([WpCliConfigStep::class, WpConfigStep::class, EnvStep::class] as $class) {
            $name = (new \ReflectionClass($class))->getConstant('NAME');
            self::assertSame($class, $steps[$name], "{$class} is registered under its NAME");
        }
    }

    public function testLeavesTheWholeLayoutToAProjectThatDefinesAnyOfIt(): void
    {
        $extra = $this->activate([
            'wordpress-install-dir' => 'wp',
        ]);

        self::assertSame('wp', $extra['wordpress-install-dir']);
        self::assertArrayNotHasKey('wordpress-content-dir', $extra);
        self::assertArrayNotHasKey('installer-paths', $extra);
        self::assertStringContainsString('layout', $this->io->getOutput());
        self::assertStringContainsString('installer-paths', $this->io->getOutput());
    }

    public function testUserWpStarterSettingsWin(): void
    {
        $extra = $this->activate([
            'wpstarter' => [
                'env-bootstrap-dir' => 'env',
                'env-example' => true,
                'custom-steps' => [
                    'n5s/env' => 'App\\OwnEnvStep',
                ],
            ],
        ]);

        self::assertSame('env', $extra['wpstarter']['env-bootstrap-dir']);
        self::assertTrue($extra['wpstarter']['env-example']);
        self::assertSame('App\\OwnEnvStep', $extra['wpstarter']['custom-steps']['n5s/env']);
        self::assertSame(WpConfigStep::class, $extra['wpstarter']['custom-steps']['build-wp-config']);
    }

    public function testWarnsWhenExtraWpStarterPointsToAFile(): void
    {
        $extra = $this->activate([
            'wpstarter' => 'wpstarter.json',
        ]);

        self::assertSame('wpstarter.json', $extra['wpstarter']);
        self::assertStringContainsString('wpstarter.json', $this->io->getOutput());
        self::assertStringContainsString('custom-steps', $this->io->getOutput());
    }

    public function testWarnsWhenARootWpStarterJsonOverridesAnInjectedSetting(): void
    {
        $this->project->write('wpstarter.json', '{"custom-steps": {"mine": "App\\\\Step"}, "dropins": []}');

        $this->activate([]);

        self::assertStringContainsString('wpstarter.json', $this->io->getOutput());
        self::assertStringContainsString('custom-steps', $this->io->getOutput());
        self::assertStringNotContainsString('dropins', $this->io->getOutput());
    }

    public function testStaysQuietWhenARootWpStarterJsonTouchesNothingInjected(): void
    {
        $this->project->write('wpstarter.json', '{"dropins": []}');

        $this->activate([]);

        self::assertSame('', $this->io->getOutput());
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function activate(array $extra): array
    {
        $package = new RootPackage('vendor/project', '1.0.0.0', '1.0.0');
        $package->setExtra($extra);
        $config = new Config(false, $this->project->root);
        $config->setConfigSource(new JsonConfigSource(new JsonFile($this->project->path('composer.json'))));
        $composer = new Composer();
        $composer->setPackage($package);
        $composer->setConfig($config);

        (new Plugin())->activate($composer, $this->io);

        return $package->getExtra();
    }
}
