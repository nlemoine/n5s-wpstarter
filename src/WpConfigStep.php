<?php

declare(strict_types=1);

namespace n5s\WpStarter;

use Composer\Util\Filesystem as ComposerFilesystem;
use WeCodeMore\WpStarter\Config\Config;
use WeCodeMore\WpStarter\Step\BlockingStep;
use WeCodeMore\WpStarter\Step\FileCreationStepInterface;
use WeCodeMore\WpStarter\Step\WpConfigStep as NativeWpConfigStep;
use WeCodeMore\WpStarter\Util\Locator;
use WeCodeMore\WpStarter\Util\Paths;
use WeCodeMore\WpStarter\Util\WpConfigSectionEditor;

/**
 * Decorates WP Starter's build-wp-config step: the native step writes wp-config.php from its
 * template as usual, then this one patches the file. Registered under the native step's name so
 * it takes its place: one step, so the file is never left unpatched (a run of the native step
 * alone, an in-place deploy between two steps) and never patched twice (`prevent-overwrite`,
 * `skip-steps`, a run of this step alone).
 *
 * The patch replaces WP Starter's env file loading with Symfony Dotenv's loadEnv(), and its env
 * cache writer with one that dumps every variable Symfony loaded, without putenv().
 *
 * Loading: when the env cache dump (.env.cached.php) exists, WP Starter reads it as usual
 * (buildFromCacheDump()). Otherwise Symfony loads the full override chain into $_ENV and
 * $_SERVER only:
 *   .env                              — the project's values (n5s/env creates it from .env.example)
 *   .env.local                        — overrides of this checkout
 *   .env.{WP_ENVIRONMENT_TYPE}        — values of one environment type
 *   .env.{WP_ENVIRONMENT_TYPE}.local  — overrides of this checkout for that type
 * WPSTARTER_ENV_LOADED then keeps the bridge's own loading inert: load() returns at once, and
 * loadAppended() still parses .env.{WP_ENVIRONMENT_TYPE} but finds every variable set and
 * writes nothing. The bridge only defines the constants (setupConstants()).
 *
 * WP_ENVIRONMENT_TYPE is the one key for the environment type, with WordPress' values
 * (local, development, staging, production; WP Starter maps its aliases). WP_ENV and
 * WORDPRESS_ENV, which WP Starter also reads, are not part of the contract: set, they would
 * drive the bridge but not the file chain.
 *
 * Caching: see replaceEnvCache(). The dump is stale by design: delete it (or run
 * `composer wpstarter flush-env-cache`) after changing the .env files.
 */
final class WpConfigStep implements FileCreationStepInterface, BlockingStep
{
    public const NAME = NativeWpConfigStep::NAME;

    private readonly NativeWpConfigStep $native;

    private readonly WpConfigSectionEditor $wpConfigSectionEditor;

    private readonly ComposerFilesystem $composerFilesystem;

    public function __construct(Locator $locator)
    {
        $this->native = new NativeWpConfigStep($locator);
        $this->wpConfigSectionEditor = $locator->wpConfigSectionEditor();
        $this->composerFilesystem = $locator->composerFilesystem();
    }

    public function name(): string
    {
        return $this->native->name();
    }

    public function targetPath(Paths $paths): string
    {
        return $this->native->targetPath($paths);
    }

    public function allowed(Config $config, Paths $paths): bool
    {
        return $this->native->allowed($config, $paths);
    }

    public function run(Config $config, Paths $paths): int
    {
        $result = $this->native->run($config, $paths);
        if ($result !== self::SUCCESS) {
            return $result;
        }

        $cacheEnv = $this->cacheEnv($config);
        $this->appendDotenvLoading($config, $paths, $cacheEnv);
        $this->appendEnvAgnosticPhpFile($config, $paths);
        $this->appendSqliteDbDir($paths);
        $this->replaceEnvCache($cacheEnv);

        return self::SUCCESS;
    }

    public function error(): string
    {
        return $this->native->error();
    }

    public function success(): string
    {
        return $this->native->success();
    }

    /**
     * Append Symfony Dotenv loading to the AUTOLOAD section, before ENV_VARIABLES.
     *
     * With the cache on, the files are only read when the dump is absent. A missing,
     * unreadable or malformed file ends the request with a generic message: nothing of
     * Symfony's own message reaches the browser (it quotes the offending line, sometimes a
     * secret); the server log gets the exception class and the place.
     */
    private function appendDotenvLoading(Config $config, Paths $paths, bool $cacheEnv): void
    {
        $from = $this->composerFilesystem->normalizePath($paths->wpParent());
        /** @var string $envDir */
        $envDir = $config[Config::ENV_DIR]->unwrapOrFallback($paths->root());
        $envRelDir = $this->relPath($from, $envDir);
        $envFile = $config[Config::ENV_FILE]->unwrapOrFallback('.env');
        // var_export(): the path lands inside a PHP string literal of the generated file.
        $pathLiteral = var_export("{$envRelDir}/{$envFile}", true);

        $boot = <<<PHP
try {
    // loadEnv(), not bootEnv(): the file chain keyed on WP_ENVIRONMENT_TYPE, with no test-env
    // special case (WP Starter maps `test` to staging) and no WP_DEBUG derived from the raw
    // value, which cannot tell an alias such as `prod`: the DEFAULT_ENV section below sets it
    // from the mapped type. Without WP_ENVIRONMENT_TYPE, assume production: nothing
    // development-only gets enabled by mistake. No realpath() on the path: a symlinked .env
    // (a Deployer shared file) would move the whole chain next to its target, and a missing
    // one would make Symfony look in the current working directory.
    (new \\Symfony\\Component\\Dotenv\\Dotenv())
        ->loadEnv(__DIR__ . {$pathLiteral}, 'WP_ENVIRONMENT_TYPE', 'production', []);
} catch (\\Throwable \$e) {
    // Any failure, not only Dotenv's own exceptions (a `\$(...)` value without symfony/process
    // throws a plain LogicException). Symfony's message can quote the offending line, and a
    // secret with it: the server log gets the class and the place, nobody else gets more.
    \$where = \$e instanceof \\Symfony\\Component\\Dotenv\\Exception\\FormatException ? ' at ' . \$e->getContext()->getPath() . ':' . \$e->getContext()->getLineno() : '';
    error_log('wp-config.php: environment files could not be loaded (' . get_class(\$e) . \$where . ')');
    \$message = 'Environment files could not be loaded: see the server error log. If .env is missing, copy .env.example to .env and fill in the values.';
    if (PHP_SAPI === 'cli') {
        // exit() with a string exits with 0: WP-CLI and deploy scripts would read it as success.
        fwrite(STDERR, \$message . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    exit(\$message);
}
PHP;

        if (! $cacheEnv) {
            $dotenv = <<<PHP

// Prevent WP Starter from loading .env files (they are loaded below)
\$_ENV['WPSTARTER_ENV_LOADED'] = true;

// Load .env files via Symfony Dotenv on every request, into \$_ENV and \$_SERVER only, never
// putenv(): the values stay out of the process environment (a child started with the real
// environment does not inherit them; Symfony Process forwards \$_ENV on its own). No env
// cache (cache-env is off).
{$boot}
PHP;
            $this->wpConfigSectionEditor->append('AUTOLOAD', $dotenv);

            return;
        }

        $guardedBoot = (string) preg_replace('/^(?=.)/m', '    ', $boot);
        $dotenv = <<<PHP

// Prevent WP Starter from loading .env files (they are loaded below, or come from its cache dump)
\$_ENV['WPSTARTER_ENV_LOADED'] = true;

// Load .env files via Symfony Dotenv unless WP Starter's env cache dump exists: then the
// ENV_VARIABLES section reads the dump instead (see ENV_CACHE for how it is written). Either
// way into \$_ENV and \$_SERVER only, never putenv(): the values stay out of the process
// environment.
// The dump is probed through SplFileInfo, not is_file()/file_exists(): with
// opcache.enable_file_override=On and opcache.validate_timestamps=0 (some shared hosts),
// those functions are answered from OPcache and keep reporting a deleted dump as existing.
// A dump WP Starter could not use (empty, unreadable) would leave the request with no env
// at all, as WPSTARTER_ENV_LOADED also stops its own loader: it is dropped, the files are
// loaded again and the dump is rewritten.
\$envCacheFile = WPSTARTER_PATH . \\WeCodeMore\\WpStarter\\Env\\WordPressEnvBridge::CACHE_DUMP_FILE;
\$envCacheInfo = new \\SplFileInfo(\$envCacheFile);
if (!\$envCacheInfo->isFile() || !\$envCacheInfo->isReadable() || \$envCacheInfo->getSize() === 0) {
    \$envCacheInfo->isFile() and @unlink(\$envCacheFile);
    // Drop any stale OPcache entry so buildFromCacheDump() (which trusts file_exists()) also
    // sees the file as gone; otherwise its failed include marks the bridge as fromCache and
    // the dump is never rewritten. Then clear the warning a silenced call may have left in
    // error_get_last(), which wp-admin/admin-header.php turns into a `php-error` body class.
    function_exists('opcache_invalidate') and @opcache_invalidate(\$envCacheFile, true);
    error_clear_last();
{$guardedBoot}
}
unset(\$envCacheFile, \$envCacheInfo);
PHP;

        $this->wpConfigSectionEditor->append('AUTOLOAD', $dotenv);
    }

    /**
     * Replace the ENV_CACHE section: the dump is written from the variables Symfony loaded,
     * in the shape of WordPressEnvBridge::dumpCached() so buildFromCacheDump() reads it
     * unchanged, but without putenv(). The bridge's own writer cannot be used: with
     * WPSTARTER_ENV_LOADED set, its list of loaded variables is empty, so it dumps the
     * constants only, and it replays every variable with putenv(). Same gate as WP Starter,
     * plus the filter appended to BEFORE_BOOTSTRAP: production only. Nothing when
     * `cache-env` is off.
     */
    private function replaceEnvCache(bool $cacheEnv): void
    {
        if (! $cacheEnv) {
            $this->wpConfigSectionEditor->delete('ENV_CACHE');

            return;
        }

        // No `\$` nor `\\` below: WpConfigSectionEditor injects the code as a preg_replace()
        // replacement string, which drops the backslash of those two sequences.
        $envCache = <<<'PHP'
/** On shutdown, we dump environment so that on subsequent requests we can load it faster */
// Never from a process Composer spawned (WP Starter's wp-cli-commands): there the .env values
// WP Starter exported with putenv() pass for real environment and would be left out of the
// dump. Deliberately not part of the wpstarter.skip-cache-env filter, so that no filter can
// override it. A warm-up from a deploy shell (`wp eval`) is fine.
// Also: only a request that loaded the files (SYMFONY_DOTENV_VARS; WP Starter's isWpSetup()
// would demand DB_NAME and DB_USER, which a SQLite project does not have), and only where
// the dump can be written (a read-only filesystem would build it on every request).
if (!$envLoader->hasCachedValues() && isset($_SERVER['SYMFONY_DOTENV_VARS']) && !isset($_SERVER['COMPOSER_BINARY']) && is_writable(WPSTARTER_PATH)) {
    register_shutdown_function(
        static function () use ($envLoader, $envType) {
            $isLocal = $envType === 'local';
            $isDevMode = defined('WP_DEVELOPMENT_MODE') && WP_DEVELOPMENT_MODE;
            if (apply_filters('wpstarter.skip-cache-env', $isLocal || $isDevMode, $envType)) {
                return;
            }
            // The dump WordPressEnvBridge::dumpCached() would write if it knew the variables
            // Symfony Dotenv loaded, minus putenv(): define() for the constants the bridge set
            // up (setupConstants() does not run on a cached request), $_ENV and $_SERVER for
            // the variables, then the bridge's cache, `name => [raw, filtered]`.
            $loaded = ['WP_ENVIRONMENT_TYPE', ...explode(',', $_SERVER['SYMFONY_DOTENV_VARS'] ?? '')];
            $constants = ['WP_ENV', 'WP_ENVIRONMENT_TYPE', ...array_keys(\WeCodeMore\WpStarter\Env\WordPressEnvBridge::WP_CONSTANTS)];
            foreach (explode(',', (string) $envLoader->read(\WeCodeMore\WpStarter\Env\WordPressEnvBridge::CUSTOM_ENV_TO_CONST_VAR_NAME)) as $custom) {
                $constants[] = trim(explode(':', $custom, 2)[0]);
            }
            $content = "<?php\n";
            $cache = [];
            foreach (array_unique([...$constants, ...$loaded]) as $name) {
                $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
                if ($name === '' || !is_scalar($value)) {
                    continue;
                }
                $value = (string) $value;
                $cache[$name] = [$value, $envLoader->read($name)];
                $key = var_export($name, true);
                $export = var_export($value, true);
                if (in_array($name, $constants, true) && defined($name)) {
                    $constant = constant($name);
                    $content .= "define({$key}, " . ($constant === $value ? $export : var_export($constant, true)) . ");\n";
                }
                if (in_array($name, $loaded, true)) {
                    $content .= '$_ENV[' . $key . '] = ' . $export . ";\n";
                    (strpos($name, 'HTTP_') !== 0) and $content .= '$_SERVER[' . $key . '] = ' . $export . ";\n";
                }
                $content .= "\n";
            }
            $content .= sprintf("return %s;\n", var_export($cache, true));
            $file = WPSTARTER_PATH . \WeCodeMore\WpStarter\Env\WordPressEnvBridge::CACHE_DUMP_FILE;
            // Written aside, then renamed: a concurrent request never includes a half-written
            // dump. A random name, created exclusively ('x'): two writers never share a file,
            // not even threads of one process or hosts sharing the directory.
            $tmp = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
            $handle = @fopen($tmp, 'xb');
            $written = $handle !== false && @fwrite($handle, $content) === strlen($content) && @fclose($handle);
            if (!$written || !@rename($tmp, $file)) {
                @unlink($tmp);
            }
        }
    );
}
PHP;
        $this->wpConfigSectionEditor->replace('ENV_CACHE', $envCache);

        // A closure, not __return_true: WordPress functions are not loaded yet at this point.
        $filter = <<<'PHP'

// The env cache dump is written in production only (development reads the files on every
// request). The constant, not the raw name WP Starter passes: aliases such as `prod` count.
add_filter('wpstarter.skip-cache-env', static fn ($skip) => $skip || WP_ENVIRONMENT_TYPE !== 'production', 10, 1);
PHP;
        $this->wpConfigSectionEditor->append('BEFORE_BOOTSTRAP', $filter);
    }

    /**
     * Append an env-agnostic PHP config file loader right after WP Starter's
     * env-specific PHP file require (the one that loads `<bootstrap>/{$envType}.php`).
     *
     * Loads `<bootstrap>/all.php` on every request, regardless of WP_ENVIRONMENT_TYPE.
     * Useful for constants that must be defined for every environment but can't be
     * expressed as plain env-to-const values (e.g. paths that need to be resolved
     * against the project root rather than PHP's CWD).
     */
    private function appendEnvAgnosticPhpFile(Config $config, Paths $paths): void
    {
        // var_export(): the path lands inside a PHP string literal of the generated file.
        $phpAllLiteral = var_export($this->bootstrapRelDir($config, $paths) . '/all.php', true);

        $phpAllFile = <<<PHP

// Env-agnostic PHP config file: loads `<env-bootstrap-dir>/all.php` for every WP_ENVIRONMENT_TYPE
\$phpAllFilePath = realpath(__DIR__ . {$phpAllLiteral});
\$hasPhpAllFile = \$phpAllFilePath && file_exists(\$phpAllFilePath) && is_readable(\$phpAllFilePath);
if (\$hasPhpAllFile) {
    require_once \$phpAllFilePath;
}
\$debugInfo['env-php-all-file'] = [
    'label' => 'Env-agnostic PHP file',
    'value' => \$hasPhpAllFile ? \$phpAllFilePath : 'None',
    'debug' => \$hasPhpAllFile ? \$phpAllFilePath : '',
];
unset(\$phpAllFilePath, \$hasPhpAllFile);
PHP;

        $this->wpConfigSectionEditor->append('ENV_VARIABLES', $phpAllFile);
    }

    /**
     * Bridge DB_DIR/DB_FILE from env to constants with defaults, then resolve FQDBDIR.
     *
     * DB_DIR/DB_FILE don't need to be in WP_STARTER_ENV_TO_CONST: this block handles the
     * env to constant bridge and provides defaults:
     *   - DB_DIR  defaults to `var/db` in the project root, expressed relative to the
     *     wp-parent dir, as any relative DB_DIR is resolved (`../var/db` with the default layout)
     *   - DB_FILE defaults to "db.sqlite"
     *
     * FQDBDIR is then resolved to an absolute path (the SQLite integration plugin needs it
     * absolute regardless of PHP's working directory).
     */
    private function appendSqliteDbDir(Paths $paths): void
    {
        $from = $this->composerFilesystem->normalizePath($paths->wpParent());
        $dbDirDefault = var_export(ltrim($this->relPath($from, $paths->root('var/db')), '/'), true);

        $sqliteDbDir = <<<PHP

// SQLite database integration: bridge DB_DIR/DB_FILE from env, set defaults, resolve FQDBDIR
if (!defined('DB_DIR')) {
    \$dbDir = \$envLoader->read('DB_DIR') ?: {$dbDirDefault};
    define('DB_DIR', \$dbDir);
}
if (!defined('DB_FILE')) {
    \$dbFile = \$envLoader->read('DB_FILE') ?: 'db.sqlite';
    define('DB_FILE', \$dbFile);
}
if (!defined('FQDBDIR')) {
    // Resolve DB_DIR to an absolute path even if the directory doesn't exist yet
    // (first deploy). realpath() returning false would leave FQDBDIR unset, and
    // the SQLite plugin would fall back to the relative DB_DIR resolved against
    // PHP's CWD, which points outside the release tree under WP-CLI.
    \$dbDirRaw = DB_DIR;
    \$dbDirAbs = (str_starts_with(\$dbDirRaw, '/') || preg_match('~^[a-zA-Z]:~', \$dbDirRaw) === 1)
        ? \$dbDirRaw
        : __DIR__ . '/' . \$dbDirRaw;
    \$dbDirReal = realpath(\$dbDirAbs);
    define('FQDBDIR', (\$dbDirReal !== false ? \$dbDirReal : \$dbDirAbs) . '/');
}
unset(\$dbDir, \$dbFile, \$dbDirRaw, \$dbDirAbs, \$dbDirReal);
PHP;

        $this->wpConfigSectionEditor->append('ENV_VARIABLES', $sqliteDbDir);
    }

    /**
     * The env bootstrap dir as WP Starter resolves it: `env-bootstrap-dir` if set,
     * otherwise `env-dir`, otherwise the project root; relative to the wp-parent dir so
     * `__DIR__` in the generated wp-config.php resolves it.
     */
    private function bootstrapRelDir(Config $config, Paths $paths): string
    {
        $from = $this->composerFilesystem->normalizePath($paths->wpParent());

        /** @var string $envBootstrapDir */
        $envBootstrapDir = $config[Config::ENV_BOOTSTRAP_DIR]->unwrapOrFallback('');
        if ($envBootstrapDir !== '') {
            return $this->relPath($from, $paths->root($envBootstrapDir));
        }
        /** @var string $envDir */
        $envDir = $config[Config::ENV_DIR]->unwrapOrFallback($paths->root());

        return $this->relPath($from, $envDir);
    }

    private function cacheEnv(Config $config): bool
    {
        return (bool) $config[Config::CACHE_ENV]->unwrapOrFallback(true);
    }

    private function relPath(string $from, string $to): string
    {
        $path = $this->composerFilesystem->normalizePath(
            $this->composerFilesystem->findShortestPath($from, $to, true)
        );

        return "/{$path}";
    }
}
