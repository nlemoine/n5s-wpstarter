<?php

declare(strict_types=1);

namespace n5s\WpStarter;

use WeCodeMore\WpStarter\Config\Config;
use WeCodeMore\WpStarter\Step\FileCreationStepInterface;
use WeCodeMore\WpStarter\Util\Filesystem;
use WeCodeMore\WpStarter\Util\Locator;
use WeCodeMore\WpStarter\Util\Paths;

/**
 * Replaces WP Starter's build-wp-cli-yml step, registered under its name: the native one
 * rewrites wp-cli.yml from its template, dropping aliases and everything else; this one only
 * sets the `path:` line, and creates the file when there is none.
 */
final class WpCliConfigStep implements FileCreationStepInterface
{
    public const NAME = 'build-wp-cli-yml';

    private readonly Filesystem $filesystem;

    public function __construct(Locator $locator)
    {
        $this->filesystem = $locator->filesystem();
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function targetPath(Paths $paths): string
    {
        return $paths->root('/wp-cli.yml');
    }

    public function allowed(Config $config, Paths $paths): bool
    {
        return true;
    }

    public function run(Config $config, Paths $paths): int
    {
        $targetPath = $this->targetPath($paths);
        $wpPath = $paths->relativeToRoot(Paths::WP);

        if (! file_exists($targetPath)) {
            $content = "path: {$wpPath}\n";

            if (! $this->filesystem->writeContent($content, $targetPath)) {
                return self::ERROR;
            }

            return self::SUCCESS;
        }

        $content = file_get_contents($targetPath);
        if ($content === false) {
            return self::ERROR;
        }

        // Callback, not a replacement string: the path is not a pattern. And no `\s`, which
        // would cross the line break of an empty value and eat the next line.
        $updated = preg_replace_callback(
            '/^path:[^\r\n]*/m',
            static fn (): string => "path: {$wpPath}",
            $content,
            1,
            $count
        );

        if ($count === 0) {
            $updated = "path: {$wpPath}\n" . $content;
        }

        if ($updated === $content) {
            return self::NONE;
        }

        if (! $this->filesystem->writeContent($updated, $targetPath)) {
            return self::ERROR;
        }

        return self::SUCCESS;
    }

    public function error(): string
    {
        return 'Error while creating wp-cli.yml';
    }

    public function success(): string
    {
        return '<comment>wp-cli.yml</comment> saved successfully.';
    }
}
