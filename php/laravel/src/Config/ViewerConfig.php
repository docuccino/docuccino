<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Support\Hydrate;

/**
 * One document's `viewer` bag, out of the framework's own `config/docuccino.php`.
 *
 * The whole bag stays there rather than in `docuccino.yaml` because every key in it is read on a path
 * that cannot afford to parse a project file: the route and its middleware at BOOT, and the gate, the
 * driver, the CDN switch, the served source and the driver's configuration on a viewer REQUEST. A
 * viewer page load that parsed a file somebody was halfway through editing would 500 on a document it
 * had already built correctly.
 *
 * One reader, because three places ask and they must agree: boot registers the routes from it, the
 * request renders the page from it, and {@see ConfigSplit} judges it against the documents the build
 * defines. It shapes no emitted byte — {@see DocumentConfig::hash()}
 * lifts `viewer` out — so carrying it on the document config costs nothing and keeps the runtime from
 * having to know which file it came from.
 *
 * @internal
 */
final class ViewerConfig
{
    /**
     * The bag for `$key`, empty when the document configures no viewer — which is an ordinary shape,
     * because an export-only document has no page to serve.
     *
     * @return array<string, mixed>
     */
    public static function for(string $key): array
    {
        return Hydrate::map(self::all()[$key] ?? null);
    }

    /**
     * Every document's viewer bag, keyed by document, exactly as the framework config holds it.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        /** @var array<string, mixed> $documents */
        $documents = (array) config('docuccino.documents', []);
        $viewers = [];

        foreach ($documents as $key => $bag) {
            $viewers[(string) $key] = Hydrate::map(Hydrate::map($bag)['viewer'] ?? null);
        }

        return $viewers;
    }
}
