<?php

declare(strict_types=1);

namespace n5s\WpStarter\Tests\Support;

use Composer\Config as ComposerConfig;
use Composer\Util\Filesystem as ComposerFilesystem;
use Symfony\Component\Process\Process;
use WeCodeMore\WpStarter\Config\Config;
use WeCodeMore\WpStarter\Config\Validator;
use WeCodeMore\WpStarter\Util\Paths;

/**
 * A throwaway WP Starter project in a temporary directory: env files, a WordPress dir with
 * just enough of core for wp-config.php to run (plugin.php with the hook functions,
 * wp-settings.php printing the outcome as JSON), and the plugin's own vendor dir.
 */
final class FakeProject
{
    /**
     * The layout the plugin injects by default.
     */
    public const LAYOUT = [
        'wordpress-install-dir' => 'public/wp',
        'wordpress-content-dir' => 'public/app',
    ];

    public readonly string $root;

    private ComposerFilesystem $filesystem;

    /**
     * @param array<string, string> $extra The `extra` of composer.json, i.e. the layout
     */
    private function __construct(
        string $root,
        private readonly array $extra,
    ) {
        $this->root = $root;
        $this->filesystem = new ComposerFilesystem();
    }

    public function __destruct()
    {
        $this->filesystem->removeDirectory($this->root);
    }

    /**
     * @param array<string, string> $files Relative path => content, `.env` files included
     * @param array<string, string> $extra
     */
    public static function create(array $files, array $extra = self::LAYOUT): self
    {
        $root = sys_get_temp_dir() . '/n5s-wpstarter-' . bin2hex(random_bytes(6));
        $project = new self($root, $extra);
        $wp = $extra['wordpress-install-dir'];

        foreach ($files as $path => $content) {
            $project->write($path, $content);
        }
        $project->write("{$wp}/wp-includes/plugin.php", self::pluginPhpStub());
        $project->write("{$wp}/wp-settings.php", self::wpSettingsStub());
        $project->write('config/.gitkeep', '');
        symlink(dirname(__DIR__, 2) . '/vendor', "{$root}/vendor");

        return $project;
    }

    public function path(string $relative): string
    {
        return "{$this->root}/{$relative}";
    }

    public function write(string $relative, string $content): void
    {
        $path = $this->path($relative);
        $this->filesystem->ensureDirectoryExists(dirname($path));
        file_put_contents($path, $content);
    }

    public function read(string $relative): string
    {
        return (string) file_get_contents($this->path($relative));
    }

    public function has(string $relative): bool
    {
        return file_exists($this->path($relative));
    }

    public function paths(): Paths
    {
        $composerConfig = new ComposerConfig(false, $this->root);
        $composerConfig->merge([
            'config' => [
                'vendor-dir' => "{$this->root}/vendor",
            ],
        ]);

        return Paths::withRoot($this->root, $composerConfig, $this->extra, $this->filesystem);
    }

    /**
     * @param array<string, mixed> $wpstarterConfig
     */
    public function config(array $wpstarterConfig = []): Config
    {
        return new Config(
            $wpstarterConfig + [
                'env-bootstrap-dir' => 'config',
            ],
            new Validator($this->paths(), $this->filesystem)
        );
    }

    public function wpConfigPath(): string
    {
        $wpParent = dirname($this->extra['wordpress-install-dir']);

        return $this->path($wpParent === '.' ? 'wp-config.php' : "{$wpParent}/wp-config.php");
    }

    public function wpConfig(): string
    {
        return (string) file_get_contents($this->wpConfigPath());
    }

    /**
     * Runs one request against the generated wp-config.php in a fresh PHP process, the way
     * PHP-FPM would see it: variables_order=GPCS (real environment in $_SERVER only), no OPcache.
     *
     * @param array<string, string|false> $env Environment variables of the request; `false` removes one
     * @return array{constants: array<string, mixed>, env: array<string, mixed>, server: array<string, mixed>, getenv: array<string, mixed>, lastError: ?string}
     */
    public function request(array $env = []): array
    {
        $process = $this->requestProcess($env);
        $process->mustRun();

        $output = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        \assert(\is_array($output));

        return $output;
    }

    /**
     * Same request, but returned as is: for the paths that end the request early.
     *
     * @param array<string, string|false> $env
     */
    public function requestProcess(array $env = []): Process
    {
        return new Process(
            [PHP_BINARY, '-d', 'variables_order=GPCS', '-d', 'opcache.enable_cli=0', __DIR__ . '/request.php', $this->wpConfigPath()],
            $this->root,
            // The test runner may itself be a child of Composer (`composer test`).
            $env + [
                'COMPOSER_BINARY' => false,
                'SYMFONY_DOTENV_VARS' => false,
            ],
        );
    }

    private static function pluginPhpStub(): string
    {
        return <<<'PHP'
<?php
// The part of wp-includes/plugin.php that wp-config.php uses before wp-settings.php.
$GLOBALS['__filters'] = [];
function add_filter($hook, $callback, $priority = 10, $acceptedArgs = 1) {
    $GLOBALS['__filters'][$hook][$priority][] = [$callback, $acceptedArgs];
    return true;
}
function add_action(...$args) {
    return add_filter(...$args);
}
function apply_filters($hook, $value, ...$args) {
    if (empty($GLOBALS['__filters'][$hook])) {
        return $value;
    }
    ksort($GLOBALS['__filters'][$hook]);
    foreach ($GLOBALS['__filters'][$hook] as $callbacks) {
        foreach ($callbacks as [$callback, $acceptedArgs]) {
            $value = $callback(...array_slice([$value, ...$args], 0, $acceptedArgs));
        }
    }
    return $value;
}
PHP;
    }

    private static function wpSettingsStub(): string
    {
        return <<<'PHP'
<?php
// Stands in for wp-settings.php: reports what the request ended up with.
echo json_encode([
    'constants' => get_defined_constants(true)['user'] ?? [],
    'env' => $_ENV,
    'server' => array_filter($_SERVER, static fn ($key) => preg_match('/^[A-Z][A-Z0-9_]*$/', (string) $key) === 1, ARRAY_FILTER_USE_KEY),
    'getenv' => getenv(),
    'lastError' => error_get_last()['message'] ?? null,
], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
PHP;
    }
}
