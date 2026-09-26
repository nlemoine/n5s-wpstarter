<?php

declare(strict_types=1);

namespace n5s\WpStarter\Tests\Integration;

use n5s\WpStarter\Tests\Support\FakeProject;
use n5s\WpStarter\Tests\Support\Steps;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use WeCodeMore\WpStarter\Step\Step;
use WeCodeMore\WpStarter\Step\WpConfigStep as NativeWpConfigStep;

/**
 * Runs the generated wp-config.php the way WordPress would, in a fresh PHP process per request.
 */
final class WpConfigStepTest extends TestCase
{
    private const DUMP = '.env.cached.php';

    private const ENV = "DB_NAME=wp\nDB_USER=root\nDB_PASSWORD=secret\nSOME_KEY=azerty\n";

    public function testTheGeneratedFileLintsWithTheCacheOn(): void
    {
        $project = $this->project('development');

        self::assertSame(0, (new Process([PHP_BINARY, '-l', $project->wpConfigPath()]))->run());
    }

    public function testTheGeneratedFileLintsWithTheCacheOff(): void
    {
        $project = $this->project('development', cacheEnv: false);

        self::assertSame(0, (new Process([PHP_BINARY, '-l', $project->wpConfigPath()]))->run());
    }

    public function testARequestGetsTheEnvFromTheFilesWithoutPutenv(): void
    {
        $project = $this->project('development', [
            '.env.local' => "DB_NAME=local\n",
        ]);

        $request = $project->request();

        self::assertSame('local', $request['constants']['DB_NAME'], '.env.local overrides .env');
        self::assertSame('development', $request['constants']['WP_ENVIRONMENT_TYPE']);
        self::assertTrue($request['constants']['WP_DEBUG']);
        self::assertSame('azerty', $request['env']['SOME_KEY']);
        self::assertSame('azerty', $request['server']['SOME_KEY']);
        self::assertArrayNotHasKey('SOME_KEY', $request['getenv']);
        self::assertNull($request['lastError']);
    }

    public function testDevelopmentWritesNoDump(): void
    {
        $project = $this->project('development');

        $project->request();

        self::assertFalse($project->has(self::DUMP));
    }

    public function testProductionWritesACompleteDumpWithoutPutenv(): void
    {
        $project = $this->project('production');

        $request = $project->request();
        $dump = $project->read(self::DUMP);

        self::assertFalse($request['constants']['WP_DEBUG']);
        self::assertStringContainsString("define('DB_NAME', 'wp');", $dump);
        self::assertStringContainsString("\$_ENV['SOME_KEY'] = 'azerty';", $dump);
        self::assertStringContainsString("\$_SERVER['SOME_KEY'] = 'azerty';", $dump);
        self::assertStringNotContainsString('putenv(', $dump);
        self::assertSame(0, (new Process([PHP_BINARY, '-l', $project->path(self::DUMP)]))->run());
    }

    public function testARequestServedFromTheDumpSeesTheSameEnvironment(): void
    {
        $project = $this->project('production');
        $fromFiles = $project->request();
        $project->write('.env', str_replace('azerty', 'changed', self::ENV) . "WP_ENVIRONMENT_TYPE=production\n");

        $fromDump = $project->request();

        // Same constants and values; only the order they get defined in differs.
        ksort($fromFiles['constants']);
        ksort($fromDump['constants']);
        self::assertSame($fromFiles['constants'], $fromDump['constants']);
        self::assertSame('azerty', $fromDump['server']['SOME_KEY'], 'the dump is a snapshot, the changed file is not read');
        self::assertArrayNotHasKey('SOME_KEY', $fromDump['getenv']);
    }

    public function testAnUnusableDumpIsDroppedAndTheFilesLoaded(): void
    {
        $project = $this->project('development');
        $project->write(self::DUMP, '');

        $request = $project->request();

        self::assertSame('wp', $request['constants']['DB_NAME']);
        self::assertSame('azerty', $request['server']['SOME_KEY']);
        self::assertFalse($project->has(self::DUMP));
        self::assertNull($request['lastError']);
    }

    public function testAProcessSpawnedByComposerWritesNoDump(): void
    {
        $project = $this->project('production');

        $project->request([
            'COMPOSER_BINARY' => '/usr/local/bin/composer',
        ]);

        self::assertFalse($project->has(self::DUMP));
    }

    public function testAnEmptyDbDirFallsBackToTheDefault(): void
    {
        $project = $this->project('development', [
            '.env' => self::ENV . "WP_ENVIRONMENT_TYPE=development\nDB_DIR=\nDB_FILE=\n",
        ]);

        $request = $project->request();

        self::assertSame('../var/db', $request['constants']['DB_DIR']);
        self::assertSame('db.sqlite', $request['constants']['DB_FILE']);
        self::assertStringEndsWith('/var/db/', $request['constants']['FQDBDIR']);
    }

    public function testCacheEnvOffLeavesNoCacheCodeAndWritesNoDump(): void
    {
        $project = $this->project('production', cacheEnv: false);

        $request = $project->request();

        self::assertStringNotContainsString('SplFileInfo', $project->wpConfig());
        self::assertStringNotContainsString('register_shutdown_function', $project->wpConfig());
        self::assertSame('wp', $request['constants']['DB_NAME']);
        self::assertFalse($project->has(self::DUMP));
    }

    public function testAnAliasOfProductionBehavesAsProduction(): void
    {
        $project = $this->project('prod');

        $request = $project->request();

        self::assertSame('production', $request['constants']['WP_ENVIRONMENT_TYPE'], 'WP Starter maps the alias');
        self::assertFalse($request['constants']['WP_DEBUG']);
        self::assertTrue($project->has(self::DUMP), 'the cache gate uses the mapped type');
    }

    public function testTheTestEnvironmentStillReadsDotEnvLocal(): void
    {
        $project = $this->project('test', [
            '.env.local' => "DB_NAME=local\n",
        ]);

        $request = $project->request();

        self::assertSame('staging', $request['constants']['WP_ENVIRONMENT_TYPE']);
        self::assertSame('local', $request['constants']['DB_NAME'], 'no Symfony "test env" special case');
    }

    public function testASymlinkedDotEnvKeepsItsSiblingsNextToTheLink(): void
    {
        $project = FakeProject::create([
            'shared/.env' => self::ENV . "WP_ENVIRONMENT_TYPE=development\n",
            '.env.local' => "DB_NAME=local\n",
        ]);
        symlink($project->path('shared/.env'), $project->path('.env'));
        $this->runStep($project);

        $request = $project->request();

        self::assertSame('local', $request['constants']['DB_NAME'], 'the .env.local next to the link, not next to its target');
    }

    public function testFallsBackToDotEnvDist(): void
    {
        $project = FakeProject::create([
            '.env.dist' => self::ENV . "WP_ENVIRONMENT_TYPE=development\n",
        ]);
        $this->runStep($project);

        $request = $project->request();

        self::assertSame('wp', $request['constants']['DB_NAME']);
    }

    public function testABrokenEnvFileFailsTheCliWithoutQuotingIt(): void
    {
        $project = FakeProject::create([
            '.env' => self::ENV . "WP_ENVIRONMENT_TYPE=development\nTHIS LINE IS BROKEN\n",
        ]);
        $this->runStep($project);

        $process = $project->requestProcess();
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Environment files could not be loaded', $process->getErrorOutput());
        self::assertStringNotContainsString('BROKEN', $process->getOutput() . $process->getErrorOutput(), "Symfony's message, which quotes the line, stays in the log");
    }

    public function testTheDefaultDbDirIsTheProjectVarDbWhateverTheLayout(): void
    {
        $project = $this->project('development', extra: [
            'wordpress-install-dir' => 'wp',
            'wordpress-content-dir' => 'app',
        ]);

        $request = $project->request();

        self::assertSame(realpath($project->root) . '/var/db/', $request['constants']['FQDBDIR']);
    }

    public function testAnAbsoluteWindowsDbDirIsKeptAsIs(): void
    {
        $project = $this->project('development', [
            '.env' => self::ENV . "WP_ENVIRONMENT_TYPE=development\nDB_DIR=C:/sites/db\n",
        ]);

        $request = $project->request();

        self::assertSame('C:/sites/db/', $request['constants']['FQDBDIR']);
    }

    public function testTheGeneratedCodeDoesNotRelyOnTheTemplateImport(): void
    {
        $project = $this->project('production');

        self::assertGreaterThanOrEqual(2, substr_count($project->wpConfig(), '\\WeCodeMore\\WpStarter\\Env\\WordPressEnvBridge::'));
    }

    public function testAProjectWithoutDbCredentialsIsCachedToo(): void
    {
        $project = $this->project('production', [
            '.env' => "SOME_KEY=azerty\nWP_ENVIRONMENT_TYPE=production\n",
        ]);

        $project->request();

        self::assertTrue($project->has(self::DUMP), 'SQLite projects have no DB_NAME/DB_USER');
    }

    public function testTakesThePlaceOfTheNativeStep(): void
    {
        $step = Steps::wpConfig(FakeProject::create([])->paths());

        self::assertSame(NativeWpConfigStep::NAME, $step->name());
        self::assertSame(NativeWpConfigStep::NAME, (new \ReflectionClass($step))->getConstant('NAME'));
    }

    public function testRunningTheStepTwiceDoesNotDuplicateThePatch(): void
    {
        $project = $this->project('production');

        $this->runStep($project);
        $wpConfig = $project->wpConfig();

        self::assertSame(1, substr_count($wpConfig, "\$_ENV['WPSTARTER_ENV_LOADED'] = true;"));
        self::assertSame(1, substr_count($wpConfig, 'register_shutdown_function('));
        self::assertSame(1, substr_count($wpConfig, "\$debugInfo['env-php-all-file']"));
        self::assertSame('wp', $project->request()['constants']['DB_NAME']);
    }

    /**
     * @param array<string, string> $files
     * @param array<string, string> $extra
     */
    private function project(string $envType, array $files = [], bool $cacheEnv = true, array $extra = FakeProject::LAYOUT): FakeProject
    {
        // The given files win over the default .env.
        $project = FakeProject::create($files + [
            '.env' => self::ENV . "WP_ENVIRONMENT_TYPE={$envType}\n",
        ], $extra);
        $this->runStep($project, $cacheEnv);

        return $project;
    }

    private function runStep(FakeProject $project, bool $cacheEnv = true): void
    {
        $result = Steps::wpConfig($project->paths())->run($project->config([
            'cache-env' => $cacheEnv,
        ]), $project->paths());
        self::assertSame(Step::SUCCESS, $result);
    }
}
