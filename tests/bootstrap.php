<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// WP Starter only classmaps its plugin entry points; it autoloads the rest itself at runtime.
spl_autoload_register(static function (string $class): void {
    $prefix = 'WeCodeMore\\WpStarter\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $file = dirname(__DIR__) . '/vendor/wecodemore/wpstarter/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
