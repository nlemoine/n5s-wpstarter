<?php

namespace n5s\WpStarter;

use WeCodeMore\WpStarter\Config\Config;
use WeCodeMore\WpStarter\Step\BlockingStep;
use WeCodeMore\WpStarter\Util\Paths;
use WeCodeMore\WpStarter\Util\Locator;
use WeCodeMore\WpStarter\Util\WpConfigSectionEditor;

class WpConfigStep implements BlockingStep
{
    private WpConfigSectionEditor $wpConfigSectionEditor;

    public function __construct(Locator $locator)
    {
        $this->wpConfigSectionEditor = $locator->WpConfigSectionEditor();
    }

    public function name(): string
    {
        return 'custom-wp-config';
    }

    public function success(): string
    {
        return '<comment>custom-wp-config</comment> applied successfully.';
    }

    public function error(): string
    {
        return 'custom-wp-config failed.';
    }

    public function allowed(Config $config, Paths $paths): bool
    {
        return true;
    }

    public function run(Config $config, Paths $paths): int
    {
        $this->appendDotEnv();
        $this->appendSkipCache();
        $this->removeThemeRegister();
        return self::SUCCESS;
    }

    /**
     * Replace WP Starter's env loading with Dotenv's
     */
    private function appendDotEnv(): void
    {
        $dotenv = <<<PHP
// Prevent WP Starter to load from .env file
\$_ENV['WPSTARTER_ENV_LOADED'] = true;

// Only boot from .env files if not cached
if (!file_exists(__DIR__ . '/.env.cached.php')) {
    try {
        (new \Symfony\Component\Dotenv\Dotenv('WP_ENVIRONMENT_TYPE', 'WP_DEBUG'))
            ->setProdEnvs(['production', 'prod', 'live', 'public'])
            ->usePutenv()
            ->bootEnv(realpath(__DIR__ . '/..') . '/.env', 'development');
    } catch (\Symfony\Component\Dotenv\Exception\PathException \$e) {
        http_response_code(500);
        exit('Could not find a .env file. Please copy .env.example to .env and fill in the correct values.');
    }
}
PHP;
        $this->wpConfigSectionEditor->append('AUTOLOAD', $dotenv);
    }

    /**
     * Add a filter to skip caching in development
     */
    private function appendSkipCache(): void
    {
        $skipCache = <<<PHP
add_filter('wpstarter.skip-cache-env', function (\$skip, \$envName) {
    return \$skip || \$envName === 'development';
}, 10, 2);
PHP;
        $this->wpConfigSectionEditor->append('BEFORE_BOOTSTRAP', $skipCache);
    }

    /**
     * Remove theme registering
     */
    private function removeThemeRegister(): void
    {
        $this->wpConfigSectionEditor->delete('THEMES_REGISTER');
    }
}
