<?php

declare(strict_types=1);

namespace n5s\WpStarter;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

final class Plugin implements PluginInterface
{
    /**
     * The layout hangs on one dir: the content dir. The WordPress dir sits next to it, and the
     * default installer paths go under it.
     */
    private const CONTENT_DIR = 'public/app';

    private const WP_DIR_NAME = 'wp';

    private const CONTENT_DIR_NAME = 'app';

    private const LAYOUT_DIRS = ['wordpress-install-dir', 'wordpress-content-dir'];

    private const INSTALLER_PATHS = [
        'mu-plugins' => 'type:wordpress-muplugin',
        'plugins' => 'type:wordpress-plugin',
        'themes' => 'type:wordpress-theme',
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
     * Fills in the layout from what the project defines, so the parts never disagree:
     * - content dir: the project's, else `app` next to the project's WordPress dir,
     *   else public/app;
     * - WordPress dir: the project's, else `wp` next to the content dir (WP Starter wants
     *   both to share a parent);
     * - installer paths: the project's first (composer/installers takes the first match),
     *   then mu-plugins, plugins and themes under the content dir. On a path the project also
     *   declares, the default type is appended to the project's list, so a project only
     *   lists the packages it adds.
     *
     * A dir that is not a non-empty path relative to the project root (absolute, empty, not a
     * string) is rejected: nothing is derived from it, the layout is left as the project wrote
     * it, and a warning names it. Derived from `/app`, the installer paths would be absolute
     * and composer/installers would write outside the project.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function injectLayout(array $extra, IOInterface $io): array
    {
        $rejected = array_filter(
            self::LAYOUT_DIRS,
            fn (string $key): bool => array_key_exists($key, $extra) && ! $this->isRelativeDir($extra[$key])
        );
        if ($rejected !== []) {
            $io->writeError(sprintf(
                '<warning>n5s/wpstarter: %s must be a non-empty path relative to the project root, so no layout is injected.</warning>',
                implode(', ', $rejected)
            ));

            return $extra;
        }

        $wpDir = $this->dirValue($extra, 'wordpress-install-dir');
        $contentDir = $this->dirValue($extra, 'wordpress-content-dir')
            ?? ($wpDir !== null ? $this->sibling($wpDir, self::CONTENT_DIR_NAME) : self::CONTENT_DIR);

        $extra['wordpress-content-dir'] ??= $contentDir;
        $extra['wordpress-install-dir'] ??= $this->sibling($contentDir, self::WP_DIR_NAME);

        $installerPaths = $extra['installer-paths'] ?? [];
        if (! is_array($installerPaths)) {
            return $extra;
        }

        foreach (self::INSTALLER_PATHS as $dir => $type) {
            $path = "{$contentDir}/{$dir}/{\$name}";
            $current = $installerPaths[$path] ?? [];
            if (is_array($current) && ! in_array($type, $current, true)) {
                $installerPaths[$path] = [...$current, $type];
            }
        }
        $extra['installer-paths'] = $installerPaths;

        return $extra;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function dirValue(array $extra, string $key): ?string
    {
        $value = $extra[$key] ?? null;

        return is_string($value) ? rtrim($value, '/') : null;
    }

    private function isRelativeDir(mixed $value): bool
    {
        return is_string($value)
            && trim($value, '/\\') !== ''
            && preg_match('~^([/\\\\]|[a-zA-Z]:)~', $value) !== 1;
    }

    private function sibling(string $dir, string $name): string
    {
        $parent = dirname($dir);

        return $parent === '.' ? $name : "{$parent}/{$name}";
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
