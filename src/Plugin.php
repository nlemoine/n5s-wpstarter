<?php

declare(strict_types=1);

namespace n5s\WpStarter;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

final class Plugin implements PluginInterface
{
    /**
     * The layout: injected as a whole, or not at all when the project defines any of it.
     */
    private const LAYOUT = [
        'installer-paths' => [
            'public/app/mu-plugins/{$name}' => ['type:wordpress-muplugin'],
            'public/app/plugins/{$name}' => ['type:wordpress-plugin'],
            'public/app/themes/{$name}' => ['type:wordpress-theme'],
        ],
        'wordpress-content-dir' => 'public/app',
        'wordpress-install-dir' => 'public/wp',
    ];

    private const WPSTARTER_DEFAULTS = [
        'custom-steps' => [
            // Same names as WP Starter's native steps, so ours take their place instead of
            // running after them: build-wp-config is decorated (native run, then patched),
            // build-wp-cli-yml is replaced (the native one rewrites wp-cli.yml from its
            // template, dropping aliases etc.). Class names as strings on purpose: reading a
            // constant of these classes here (X::NAME) would autoload them before WP Starter
            // registers the autoloader for its Step interfaces.
            'build-wp-config' => 'n5s\\WpStarter\\WpConfigStep',
            'build-wp-cli-yml' => 'n5s\\WpStarter\\WpCliConfigStep',
            'n5s/env' => 'n5s\\WpStarter\\EnvStep',
        ],
        'env-bootstrap-dir' => 'config',
        // WP Starter's own build-env-example step, on by default, copies its generic
        // templates/.env.example over the project's one whenever .env is missing, right before
        // n5s/env copies .env.example into .env.
        'env-example' => false,
    ];

    public function activate(Composer $composer, IOInterface $io): void
    {
        $package = $composer->getPackage();
        $extra = $package->getExtra();

        $extra = $this->injectLayout($extra, $io);
        $extra = $this->injectWpStarterDefaults($extra, $io);
        $this->warnAboutWpStarterJson($composer, $extra, $io);

        $package->setExtra($extra);
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function injectLayout(array $extra, IOInterface $io): array
    {
        $defined = array_intersect_key(self::LAYOUT, $extra);
        if ($defined === []) {
            return $extra + self::LAYOUT;
        }

        // A layout is one whole: partial defaults would mix two of them (S-07 of the review).
        $missing = array_keys(array_diff_key(self::LAYOUT, $extra));
        if ($missing !== []) {
            $io->writeError(sprintf(
                '<warning>n5s/wpstarter: the project defines part of the layout (%s), so none of it is injected; %s must be set too.</warning>',
                implode(', ', array_keys($defined)),
                implode(', ', $missing)
            ));
        }

        return $extra;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function injectWpStarterDefaults(array $extra, IOInterface $io): array
    {
        $wpstarterExtra = $extra['wpstarter'] ?? [];

        if (! is_array($wpstarterExtra)) {
            $io->writeError(sprintf(
                '<warning>n5s/wpstarter: extra.wpstarter points to %s, so the plugin injects nothing; set %s in that file.</warning>',
                is_string($wpstarterExtra) ? $wpstarterExtra : 'a file',
                implode(', ', array_keys(self::WPSTARTER_DEFAULTS))
            ));

            return $extra;
        }

        foreach (self::WPSTARTER_DEFAULTS as $key => $value) {
            if (! array_key_exists($key, $wpstarterExtra)) {
                $wpstarterExtra[$key] = $value;
            } elseif (is_array($value) && is_array($wpstarterExtra[$key])) {
                $wpstarterExtra[$key] = array_merge($value, $wpstarterExtra[$key]);
            }
        }

        $extra['wpstarter'] = $wpstarterExtra;

        return $extra;
    }

    /**
     * WP Starter merges a root wpstarter.json over extra.wpstarter key by key, silently: a
     * `custom-steps` there would drop every step injected above.
     *
     * @param array<string, mixed> $extra
     */
    private function warnAboutWpStarterJson(Composer $composer, array $extra, IOInterface $io): void
    {
        if (! is_array($extra['wpstarter'] ?? null)) {
            return;
        }

        $file = dirname($composer->getConfig()->getConfigSource()->getName()) . '/wpstarter.json';
        if (! is_file($file)) {
            return;
        }

        $settings = json_decode((string) file_get_contents($file), true);
        $overridden = is_array($settings) ? array_keys(array_intersect_key(self::WPSTARTER_DEFAULTS, $settings)) : [];
        if ($overridden === []) {
            return;
        }

        $io->writeError(sprintf(
            '<warning>n5s/wpstarter: %s overrides %s, which the plugin sets in extra.wpstarter; WP Starter merges that file over extra.wpstarter key by key.</warning>',
            $file,
            implode(', ', $overridden)
        ));
    }
}
