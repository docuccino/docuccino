<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Support\Hydrate;
use JsonException;

/**
 * The namespace a class written into a directory has to carry, read off the application's own
 * `composer.json` PSR-4 map.
 *
 * Not a convenience. A version change is discovered by scanning source and then `class_exists()`, so a
 * class whose namespace the autoloader does not map is never loaded and never applied — silently. So
 * the namespace is derived from the one file that decides it, and a directory no mapping covers is a
 * refusal rather than a guess: writing an unloadable class would look exactly like a change nobody
 * declared.
 *
 * `autoload-dev` counts too for a namespace and for keeping bodies intact: a modular application maps
 * its modules wherever it maps them, and a helper a test root declares still has to reflect. It does
 * NOT count for what the analyser walks into, which is why {@see shipped()} exists — one reader, two
 * questions, and the decision about which section answers which lives here rather than at the callers.
 *
 * @internal
 */
final class Psr4Namespaces
{
    /**
     * The namespace for `$directory` (absolute, inside `$basePath`), or null when no PSR-4 prefix
     * covers it.
     *
     * The LONGEST matching directory prefix wins, so `modules/Billing/src` mapped separately beats a
     * blanket `modules/` — the same rule Composer's own resolution uses.
     */
    public static function for(string $basePath, string $directory): ?string
    {
        $relative = Paths::relative($directory, $basePath);

        if ($relative === null) {
            return null;
        }

        $relative = trim($relative, '/');
        $best = null;

        foreach (self::roots($basePath) as $prefix => $roots) {
            foreach ($roots as $root) {
                // `./app/` and `app/` are one directory to composer, so they are one here.
                $root = trim($root, './');

                if ($root !== '' && $relative !== $root && ! str_starts_with($relative, $root.'/')) {
                    continue;
                }

                if ($best !== null && strlen($root) <= strlen($best[1])) {
                    continue;
                }

                $best = [$prefix, $root];
            }
        }

        if ($best === null) {
            return null;
        }

        [$prefix, $root] = $best;
        $tail = trim(substr($relative, strlen($root)), '/');

        $namespace = trim($prefix, '\\');
        if ($tail !== '') {
            $namespace .= '\\'.str_replace('/', '\\', $tail);
        }

        return $namespace;
    }

    /**
     * The `psr-4` map of both autoload sections, each prefix against its list of roots, exactly as
     * `composer.json` writes them — the caller normalises, since what a leading `./` means differs
     * between "a namespace for this directory" and "a directory to keep bodies for".
     *
     * Public because the engine reads the same map to decide which source roots keep their bodies, and
     * two readers of one file is two answers about which namespaces an application maps.
     *
     * A missing or unreadable `composer.json` maps nothing, which a caller reports as "no prefix covers
     * this directory" — the same answer, and the same remedy.
     *
     * @return array<string, list<string>>
     */
    public static function roots(string $basePath): array
    {
        return self::psr4($basePath, ['autoload', 'autoload-dev']);
    }

    /**
     * The `psr-4` map of the `autoload` section alone: the roots the application SHIPS, which is what
     * the analyser is entitled to walk into. A test root is code the API does not serve, so descending
     * into it costs analysis time and can document nothing — while it still has to be readable, which
     * is {@see roots()}'s answer and not this one.
     *
     * @return array<string, list<string>>
     */
    public static function shipped(string $basePath): array
    {
        return self::psr4($basePath, ['autoload']);
    }

    /**
     * @param  list<string>  $sections
     * @return array<string, list<string>>
     */
    private static function psr4(string $basePath, array $sections): array
    {
        $contents = @file_get_contents(rtrim($basePath, '/').'/composer.json');

        if ($contents === false) {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $manifest = Hydrate::map(is_array($decoded) ? $decoded : null);
        $map = [];

        foreach ($sections as $section) {
            $psr4 = Hydrate::map(Hydrate::map($manifest[$section] ?? null)['psr-4'] ?? null);

            foreach ($psr4 as $prefix => $roots) {
                $map[(string) $prefix] = [
                    ...($map[(string) $prefix] ?? []),
                    ...(is_string($roots) ? [$roots] : Hydrate::stringList($roots)),
                ];
            }
        }

        return $map;
    }
}
