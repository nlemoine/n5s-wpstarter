<?php

declare(strict_types=1);

// One simulated request: wp-config.php is required at file scope, as wp-load.php does. The
// stub wp-settings.php of the fake project prints what the request ended up with.
require $argv[1];
