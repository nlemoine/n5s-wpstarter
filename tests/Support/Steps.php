<?php

declare(strict_types=1);

namespace n5s\WpStarter\Tests\Support;

use Composer\IO\NullIO;
use Composer\Util\Filesystem as ComposerFilesystem;
use n5s\WpStarter\EnvStep;
use n5s\WpStarter\WpCliConfigStep;
use n5s\WpStarter\WpConfigStep;
use WeCodeMore\WpStarter\Config\Config;
use WeCodeMore\WpStarter\Io\Io;
use WeCodeMore\WpStarter\Step\WpConfigStep as NativeWpConfigStep;
use WeCodeMore\WpStarter\Util\FileContentBuilder;
use WeCodeMore\WpStarter\Util\Filesystem;
use WeCodeMore\WpStarter\Util\Paths;
use WeCodeMore\WpStarter\Util\Salter;
use WeCodeMore\WpStarter\Util\WpConfigSectionEditor;

/**
 * Builds the steps without a WP Starter Locator (which needs a full Composer runtime), by
 * setting the dependencies the steps read from it.
 */
final class Steps
{
    public static function wpConfig(Paths $paths): WpConfigStep
    {
        $composerFilesystem = new ComposerFilesystem();
        $native = self::build(NativeWpConfigStep::class, [
            'io' => new Io(new NullIO()),
            'builder' => new FileContentBuilder(),
            'filesystem' => new Filesystem($composerFilesystem),
            'composerFilesystem' => $composerFilesystem,
            'salter' => new Salter(),
        ]);

        return self::build(WpConfigStep::class, [
            'native' => $native,
            'wpConfigSectionEditor' => new WpConfigSectionEditor($paths),
            'composerFilesystem' => $composerFilesystem,
        ]);
    }

    public static function wpCliConfig(): WpCliConfigStep
    {
        return self::build(WpCliConfigStep::class, [
            'filesystem' => new Filesystem(new ComposerFilesystem()),
        ]);
    }

    public static function env(Config $config): EnvStep
    {
        return self::build(EnvStep::class, [
            'filesystem' => new Filesystem(new ComposerFilesystem()),
            'config' => $config,
        ]);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $properties
     * @return T
     */
    private static function build(string $class, array $properties): object
    {
        $step = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        (function () use ($properties): void {
            foreach ($properties as $name => $value) {
                $this->{$name} = $value;
            }
        })->call($step);

        return $step;
    }
}
