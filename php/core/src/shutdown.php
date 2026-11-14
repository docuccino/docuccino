<?php

declare(strict_types=1);

use Docuccino\Core\Pipeline\BuildWorkers;

// Loaded by Composer's autoloader before anything else runs, so this is the first shutdown function a process
// has, and a forked worker ends here however it dies, before any shutdown work it inherited (BuildWorkers).
// Only where a worker can be forked at all, and it never loads the class to find out.
if (function_exists('pcntl_fork')) {
    register_shutdown_function(static function (): void {
        if (class_exists(BuildWorkers::class, false)) {
            BuildWorkers::shutdown();
        }
    });
}
