<?php

declare(strict_types=1);

// One simulated request under OPcache as some shared hosts run it, right after the env cache
// dump was deleted: the dump is compiled into OPcache (not executed), then deleted, so that
// is_file()/file_exists() still report it and include still serves it. Exits with 3 when
// OPcache does not answer for the deleted file, since then the test would prove nothing.
[, $wpConfig, $dump] = $argv;

opcache_compile_file($dump);
unlink($dump);
if (! is_file($dump)) {
    fwrite(STDERR, 'OPcache does not answer is_file() for the deleted dump.');
    exit(3);
}

require $wpConfig;
