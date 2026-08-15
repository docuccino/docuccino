<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

/**
 * FQCNs of framework classes referenced by string across the adapter (never `use`d — they may be
 * absent from the analysed app). One home for a string that would otherwise be re-declared per
 * consumer, reachable from both the built-in extensions and the integrations.
 */
final class FrameworkClasses
{
    /** Illuminate's JSON response wrapper — the `JsonResponse<payload>` type integrations unwrap. */
    public const JSON_RESPONSE = 'Illuminate\\Http\\JsonResponse';

    /** Illuminate's redirect. Always sets `Location`, and never carries a body of its own. */
    public const REDIRECT_RESPONSE = 'Illuminate\\Http\\RedirectResponse';

    /** Symfony's redirect, which Illuminate's extends. */
    public const REDIRECT_BASE = 'Symfony\\Component\\HttpFoundation\\RedirectResponse';

    /** Symfony's HttpFoundation response — the root every framework response class extends. */
    public const RESPONSE_BASE = 'Symfony\\Component\\HttpFoundation\\Response';

    /**
     * The framework response classes an action is declared as returning. A response OBJECT is
     * transport, not an API contract: its public members are PHP internals (`original`, `exception`,
     * `headers`), so reflecting one documents the framework instead of the app.
     *
     * The first five are the ones the engine really hands back as a bare `ClassT` for an idiomatic
     * action — proven in `FrameworkResponseReachabilityTest`, over the fixture app's SsoRedirect and
     * FileDelivery controllers. The last two are the hierarchy roots {@see isResponse()} and
     * {@see isRedirect()} match subclasses against, and are legal return types themselves.
     *
     * Deliberately not exhaustive: a loadable subclass of {@see RESPONSE_BASE} counts too, which is
     * what catches an app's own `extends JsonResponse` and the Symfony responses an app names direct.
     *
     * @var list<string>
     */
    public const RESPONSE_CLASSES = [
        self::JSON_RESPONSE,
        self::REDIRECT_RESPONSE,
        'Illuminate\\Http\\Response',
        'Symfony\\Component\\HttpFoundation\\BinaryFileResponse',
        'Symfony\\Component\\HttpFoundation\\StreamedResponse',
        self::REDIRECT_BASE,
        self::RESPONSE_BASE,
    ];

    /** Whether an FQCN names a framework response object rather than a body the API hands back. */
    public static function isResponse(string $fqcn): bool
    {
        return in_array($fqcn, self::RESPONSE_CLASSES, true)
            || is_subclass_of($fqcn, self::RESPONSE_BASE, true);
    }

    /** Whether an FQCN names a redirect: a 3xx carrying a `Location` header and no body. */
    public static function isRedirect(string $fqcn): bool
    {
        return $fqcn === self::REDIRECT_BASE
            || $fqcn === self::REDIRECT_RESPONSE
            || is_subclass_of($fqcn, self::REDIRECT_BASE, true);
    }
}
