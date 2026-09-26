<?php

declare(strict_types=1);

namespace n5s\WpStarter;

use WeCodeMore\WpStarter\Config\Config;
use WeCodeMore\WpStarter\Step\FileCreationStepInterface;
use WeCodeMore\WpStarter\Util\Filesystem;
use WeCodeMore\WpStarter\Util\Locator;
use WeCodeMore\WpStarter\Util\Paths;

/**
 * Bootstraps the project env file from its `.example` sibling on first install, wherever the
 * `env-dir` and `env-file` settings put it (root and `.env` by default).
 */
final class EnvStep implements FileCreationStepInterface
{
    public const NAME = 'n5s/env';

    private readonly Filesystem $filesystem;

    private readonly Config $config;

    private string $error = '';

    public function __construct(Locator $locator)
    {
        $this->filesystem = $locator->filesystem();
        $this->config = $locator->config();
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function targetPath(Paths $paths): string
    {
        /** @var string $envDir */
        $envDir = $this->config[Config::ENV_DIR]->unwrapOrFallback($paths->root());

        return rtrim($envDir, '/') . '/' . $this->envFile();
    }

    public function allowed(Config $config, Paths $paths): bool
    {
        return ! is_file($this->targetPath($paths))
            && is_file($this->examplePath($paths));
    }

    public function run(Config $config, Paths $paths): int
    {
        $source = $this->examplePath($paths);
        $target = $this->targetPath($paths);

        if (! $this->filesystem->copyFile($source, $target)) {
            $this->error = "Error copying {$source} to {$target}.";

            return self::ERROR;
        }

        return self::SUCCESS;
    }

    public function error(): string
    {
        return $this->error !== '' ? $this->error : "Error creating {$this->envFile()} from {$this->envFile()}.example.";
    }

    public function success(): string
    {
        return sprintf(
            '<comment>%s</comment> created from <comment>%s.example</comment>.',
            $this->envFile(),
            $this->envFile()
        );
    }

    private function examplePath(Paths $paths): string
    {
        return $this->targetPath($paths) . '.example';
    }

    private function envFile(): string
    {
        /** @var string $envFile */
        $envFile = $this->config[Config::ENV_FILE]->unwrapOrFallback('.env');

        return $envFile;
    }
}
