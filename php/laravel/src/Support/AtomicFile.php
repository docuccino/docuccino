<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

/**
 * Write a file so no reader ever sees half of one: a temp file in the same directory, then a rename.
 *
 * It matters most where a write can be interrupted — `docuccino:watch` re-exports on every save, and
 * Ctrl+C reaches the build it is running, so a plain `file_put_contents` would eventually leave a
 * truncated artifact behind. The temp name carries the pid, so two processes writing the same target
 * cannot land on each other's.
 *
 * @internal
 */
final class AtomicFile
{
    /** False when the write or the rename failed, leaving whatever was already there untouched. */
    public static function write(string $path, string $contents): bool
    {
        $temp = $path.'.'.getmypid().'.tmp';

        if (@file_put_contents($temp, $contents) === false) {
            return false;
        }

        if (@rename($temp, $path)) {
            return true;
        }

        @unlink($temp);

        return false;
    }
}
