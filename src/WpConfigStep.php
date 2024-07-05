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
        $this->dotEnv($config, $paths);
        $this->loadPhpEnv($config, $paths);
        $this->alwaysForceSslFix($config, $paths);
        $this->skipCacheFilter();
        $this->wpCliHostFix();
        $this->removeUnneededSections();
        return self::SUCCESS;
    }

    /**
     * Fix missing HTTP_HOST on WP CLI
     */
    public function wpCliHostFix(): void
    {
        $wpCliHostFix = <<<PHP
if (defined('WP_CLI') && WP_CLI && !isset(\$_SERVER['HTTP_HOST'])) {
    \$_SERVER['HTTP_HOST'] = parse_url(defined('WP_HOME') ? WP_HOME : (\$_ENV['WP_HOME'] ?? ''), PHP_URL_HOST);
}
PHP;
        $this->wpConfigSectionEditor->prepend('WP_CLI_HACK', $wpCliHostFix);
    }

    /**
     * Replace WP Starter's env loading with Dotenv's
     */
    private function dotEnv(Config $config, Paths $paths): void
    {
        $from = $config[Config::WP_CONFIG_PATH]->unwrap();

        /** @var string $envBootstrapDir */
        $envBootstrapDir = $config[Config::ENV_BOOTSTRAP_DIR]->unwrapOrFallback('');
        if ($envBootstrapDir) {
            $envBootstrapDir = $this->relPath($from, $paths->root($envBootstrapDir));
        }

        /** @var string $envDirName */
        $envDirName = $config[Config::ENV_DIR]->unwrapOrFallback('');
        $envDir = $paths->root($envDirName);
        $envRelDir = $this->relPath($from, $envDir);

        $envBootDir = $envBootstrapDir ?: $envRelDir;

        $envFile = $config[Config::ENV_FILE]->unwrapOrFallback('.env');

        $cacheEnv = (string) $config[Config::CACHE_ENV]->unwrapOrFallback(true);
        $usePutEnv = (string) $config[Config::ENV_USE_PUTENV]->unwrapOrFallback(false);
        $usePutEnvBool = $usePutEnv ? 'true' : 'false';

        // @codingStandardsIgnoreStart
        $dotenv = <<<PHP
// Only boot from $envFile files if not cached
if (!is_file(WPSTARTER_PATH . WordPressEnvBridge::CACHE_DUMP_FILE)) {
    if (!is_file(WPSTARTER_PATH . '/$envFile')) {
        http_response_code(500);
        exit('Could not find a $envFile file. Please copy .env.example to $envFile and fill in the correct values.');
    }

    // Load environment variables from file
    // see: https://symfony.com/doc/current/configuration.html#overriding-environment-values-via-env-local
    (new \Symfony\Component\Dotenv\Dotenv('WP_ENVIRONMENT_TYPE', 'WP_DEBUG'))
        ->setProdEnvs(['production'])
        ->usePutenv($usePutEnvBool)
        ->bootEnv(
            path: realpath(__DIR__ . '/$envFile'),
            defaultEnv: 'development'
        );

    // Fill WPSTARTER_DOTENV_VARS env variable with keys loaded by Symfony
    // WPStarter will use this to determine if env vars were loaded from file and will cache them
    \$loadedVars = \$_SERVER['SYMFONY_DOTENV_VARS'] ?? \$_ENV['SYMFONY_DOTENV_VARS'] ?? '';
    \$_SERVER['WPSTARTER_DOTENV_VARS'] = \$loadedVars;
    \$_ENV['WPSTARTER_DOTENV_VARS'] = \$loadedVars;
    unset(\$loadedVars);
}

/**
 * Environment variables will be loaded from file, unless `WPSTARTER_ENV_LOADED` env var is
 * already setup e.g. via webserver configuration.
 * In that case all environment variables are assumed to be set.
 * Environment variables that are set in the *real* environment (e.g. via webserver) will not be
 * overridden from file, even if `WPSTARTER_ENV_LOADED` is not set.
 */
filter_var('$cacheEnv', FILTER_VALIDATE_BOOLEAN) and Helpers::enableCache();
filter_var('$usePutEnv', FILTER_VALIDATE_BOOLEAN) and Helpers::usePutenv();
[\$envType, \$envIsCached] = Helpers::loadEnvFiles('.missing_on_purpose_file', WPSTARTER_ENV_PATH);

/**
 * Define all WordPress constants from environment variables.
 *
 * Core wp_get_environment_type() only supports a pre-defined list of environments types.
 * WP Starter tries to map different environments to values supported by core, for example
 * "dev" (or "develop", or even "develop-1") will be mapped to "development" accepted by WP.
 * In that case, `wp_get_environment_type()` will return "development", but `WP_ENV` will still
 * be "dev" (or "develop", or "develop-1").
 */
defined('WP_ENVIRONMENT_TYPE') or define('WP_ENVIRONMENT_TYPE', 'production');

\$envCacheFile = realpath(WPSTARTER_ENV_PATH . WordPressEnvBridge::CACHE_DUMP_FILE);
\$envCacheEnabled = Helpers::isEnvCacheEnabled();
\$debugInfo['env-cache-config'] = [
    'label' => 'Env cache enabled in configuration',
    'value' => \$envCacheEnabled ? 'Yes' : 'No',
    'debug' => \$envCacheEnabled,
];
\$debugInfo['env-loaded-from-cache'] = [
    'label' => 'Is env loaded from cache',
    'value' => \$envIsCached ? 'Yes' : 'No',
    'debug' => \$envIsCached,
];
\$debugInfo['env-loaded-cache-file'] = [
    'label' => 'Env cache file',
    'value' => \$envCacheFile ?: '*None*',
    'debug' => \$envCacheFile,
];
\$debugInfo['wpstarter-env-type'] = [
    'label' => 'WP Starter env type',
    'value' => \$envType,
    'debug' => \$envType,
];
\$debugInfo['wp-env-type'] = [
    'label' => 'WordPress env type',
    'value' => WP_ENVIRONMENT_TYPE,
    'debug' => WP_ENVIRONMENT_TYPE,
];

unset(\$envCacheEnabled, \$envIsCached, \$envCacheFile);

\$phpEnvFilePath = realpath(__DIR__ . "$envBootDir/{\$envType}.php");
\$hasPhpEnvFile = \$phpEnvFilePath && file_exists(\$phpEnvFilePath) && is_readable(\$phpEnvFilePath);
if (\$hasPhpEnvFile) {
    require_once \$phpEnvFilePath;
}
\$debugInfo['env-php-file'] = [
    'label' => 'Env-specific PHP file',
    'value' => \$hasPhpEnvFile ? \$phpEnvFilePath : '*None*',
    'debug' => \$hasPhpEnvFile ? \$phpEnvFilePath : '',
];
unset(\$phpEnvFilePath, \$hasPhpEnvFile, \$envType);

PHP;
        // @codingStandardsIgnoreEnd
        $this->wpConfigSectionEditor->replace('ENV_VARIABLES', $dotenv);
    }

    /**
     * Add a filter to skip caching in development
     */
    private function skipCacheFilter(): void
    {
        // @codingStandardsIgnoreStart
        $skipCache = <<<PHP
add_filter('wpstarter.skip-cache-env', static fn (\$skip, \$envName) => \$skip || \$envName === 'development', 10, 2);
PHP;
        // @codingStandardsIgnoreEnd

        $this->wpConfigSectionEditor->prepend('ENV_CACHE', $skipCache);
    }

    /**
     * Force SSK fix, not enabled by default in WP Starter
     *
     * @return void
     */
    public function alwaysForceSslFix(): void
    {
        $forceSslProto = <<<PHP
/**
 * Allow WordPress to detect HTTPS when used behind a reverse proxy or a load balancer
 * See https://codex.wordpress.org/Function_Reference/is_ssl#Notes
 */
\$_ENV['WP_FORCE_SSL_FORWARDED_PROTO'] = true;
PHP;
        $this->wpConfigSectionEditor->prepend('SSL_FIX', $forceSslProto);
        $unsetVar = <<<PHP
unset(\$_ENV['WP_FORCE_SSL_FORWARDED_PROTO']);
PHP;
        $this->wpConfigSectionEditor->append('SSL_FIX', $unsetVar);
    }

    /**
     * Append constants to wp-config.php
     *
     * Adds a all.php file to the ENV_BOOTSTRAP_DIR that is loaded on all environments.
     *
     * @param Paths $paths
     *
     * @return void
     */
    private function loadPhpEnv(Config $config, Paths $paths): void
    {
        $from = $config[Config::WP_CONFIG_PATH]->unwrap();
        /** @var string $envBootstrapDir */
        $envBootstrapDir = $config[Config::ENV_BOOTSTRAP_DIR]->unwrapOrFallback('');
        if ($envBootstrapDir) {
            $envBootstrapDir = $this->relPath($from, $paths->root($envBootstrapDir));
        }

        /** @var string $envDirName */
        $envDirName = $config[Config::ENV_DIR]->unwrapOrFallback('');
        $envDir = $paths->root($envDirName);
        $envRelDir = $this->relPath($from, $envDir);

        $dir = $envBootstrapDir ?: $envRelDir;

        // @codingStandardsIgnoreStart
        $constants = <<<PHP
\$alwaysIncludedConstants = realpath(__DIR__ . "$dir/all.php");
\$hasAlwaysIncludedConstants = \$alwaysIncludedConstants && file_exists(\$alwaysIncludedConstants) && is_readable(\$alwaysIncludedConstants);
if (\$hasAlwaysIncludedConstants) {
    require_once \$alwaysIncludedConstants;
}
unset(\$alwaysIncludedConstants, \$hasAlwaysIncludedConstants);
PHP;
        // @codingStandardsIgnoreEnd

        $this->wpConfigSectionEditor->append('ENV_VARIABLES', $constants);
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
