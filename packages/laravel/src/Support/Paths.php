<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Support\ConfinedPath;

/**
 * Resolves a configured or user-supplied filesystem path against the app base directory. Unlike
 * {@see ConfinedPath}, this does not confine the result — the export target, an overlay glob and a
 * diff's old-artifact path are trusted inputs that may legitimately point anywhere (including an
 * absolute path outside the project). The single owner of the "absolute unless it already is" join
 * every command and the viewer shared as a private copy.
 *
 * @internal
 */
final class Paths
{
    /** Resolve $path against $base unless it is already absolute. */
    public static function absolute(string $path, string $base): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($base, '/').'/'.ltrim($path, '/');
    }
}
