<?php

namespace n5s\WpStarter;

use Composer\Util\Filesystem;
use WeCodeMore\WpStarter\Config\Config;
use WeCodeMore\WpStarter\Step\BlockingStep;
use WeCodeMore\WpStarter\Util\Locator;
use WeCodeMore\WpStarter\Util\Paths;
use WeCodeMore\WpStarter\Util\WpConfigSectionEditor;

class WpConfigStep implements BlockingStep
{
    private WpConfigSectionEditor $wpConfigSectionEditor;

    private Filesystem $composerFilesystem;

    public function __construct(Locator $locator)
    {
        $this->wpConfigSectionEditor = $locator->WpConfigSectionEditor();
        $this->composerFilesystem = $locator->composerFilesystem();
    }

    public function name(): string
    {
        return 'n5s-wp-config';
    }

    public function success(): string
    {
        return '<comment>n5s-wp-config</comment> applied successfully.';
    }

    public function error(): string
    {
        return 'n5s-wp-config failed.';
    }

    public function allowed(Config $config, Paths $paths): bool
    {
        return true;
    }

    public function run(Config $config, Paths $paths): int
    {
        $this->appendDotEnv($config, $paths);
        $this->appendConstants($config, $paths);
        $this->appendSkipCache();
        $this->removeUnneededSections();
        return self::SUCCESS;
    }

    /**
     * Replace WP Starter's env loading with Dotenv's
     */
    private function appendDotEnv(Config $config, Paths $paths): void
    {
        $from = $this->composerFilesystem->normalizePath($paths->wpParent());
        /** @var string $envDir */
        $envDir = $config[Config::ENV_DIR]->unwrapOrFallback($paths->root());
        $envRelDir = $this->relPath($from, $envDir);

        $envFile = $config[Config::ENV_FILE]->unwrapOrFallback('.env');

        // @codingStandardsIgnoreStart
        $dotenv = <<<PHP
// Prevent WP Starter to load from {$envFile} file
\$_ENV['WPSTARTER_ENV_LOADED'] = true;

// Only boot from {$envFile} files if not cached
if (!is_file(WPSTARTER_PATH . WordPressEnvBridge::CACHE_DUMP_FILE)) {
    try {
        (new \Symfony\Component\Dotenv\Dotenv('WP_ENVIRONMENT_TYPE', 'WP_DEBUG'))
            ->setProdEnvs(['production'])
            ->usePutenv()
            ->bootEnv(realpath(__DIR__ . '{$envRelDir}/{$envFile}'), 'development');
    } catch (\Symfony\Component\Dotenv\Exception\PathException \$e) {
        http_response_code(500);
        exit('Could not find a {$envFile} file. Please copy .env.example to {$envFile} and fill in the correct values.');
    }
}
PHP;
        // @codingStandardsIgnoreEnd
        $this->wpConfigSectionEditor->append('AUTOLOAD', $dotenv);
    }

    /**
     * Add a filter to skip caching in development
     */
    private function appendSkipCache(): void
    {
        // @codingStandardsIgnoreStart
        $skipCache = <<<PHP
add_filter('wpstarter.skip-cache-env', function (\$skip, \$envName) {
    return \$skip || \$envName === 'development';
}, 10, 2);
PHP;
        // @codingStandardsIgnoreEnd

        $this->wpConfigSectionEditor->append('BEFORE_BOOTSTRAP', $skipCache);
    }

    /**
     * Append constants to wp-config.php
     *
     * Adds a all.php file to the ENV_BOOTSTRAP_DIR that is loaded on all environments.
     */
    private function appendConstants(Config $config, Paths $paths): void
    {
        $from = $this->composerFilesystem->normalizePath($paths->wpParent());
        /** @var string $envBootstrapDir */
        $envBootstrapDir = $config[Config::ENV_BOOTSTRAP_DIR]->unwrapOrFallback('');
        if ($envBootstrapDir) {
            $envBootstrapDir = $this->relPath($from, $paths->root($envBootstrapDir));
        }

        /** @var string $envDir */
        $envDir = $config[Config::ENV_DIR]->unwrapOrFallback($paths->root());
        $envRelDir = $this->relPath($from, $envDir);

        $envDir = $envBootstrapDir ?: $envRelDir;

        // @codingStandardsIgnoreStart
        $constants = <<<PHP
        \$alwaysIncludedConstants = realpath(__DIR__ . "{$envDir}/all.php");
        \$hasAlwaysIncludedConstants = \$alwaysIncludedConstants && file_exists(\$alwaysIncludedConstants) && is_readable(\$alwaysIncludedConstants);
        if (\$hasAlwaysIncludedConstants) {
            require_once \$alwaysIncludedConstants;
        }
        unset(\$alwaysIncludedConstants, \$hasAlwaysIncludedConstants);
PHP;
        // @codingStandardsIgnoreEnd

        $this->wpConfigSectionEditor->prepend('ENV_VARIABLES', $constants);
    }

    /**
     * Remove theme registering
     */
    private function removeUnneededSections(): void
    {
        $this->wpConfigSectionEditor->delete('THEMES_REGISTER');
        $this->wpConfigSectionEditor->delete('ADMIN_COLOR');
    }

    private function relPath(string $from, string $to, bool $bothDirs = true): string
    {
        $path = $this->composerFilesystem->normalizePath(
            $this->composerFilesystem->findShortestPath($from, $to, $bothDirs)
        );

        return "/{$path}";
    }
}
