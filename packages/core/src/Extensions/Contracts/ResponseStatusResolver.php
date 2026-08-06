<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Extensions\Context\RouteContext;

/**
 * A gated seam contributing a success-status override for a returned class — e.g. a spatie Data class
 * overriding `calculateResponseStatus()` to 201/202. Resolved per-document like the exception-mapper
 * chain (first non-null wins), so a DISABLED integration contributes no override and the built-in
 * inferred-responses extension reads only this chain (never the integration class).
 */
interface ResponseStatusResolver
{
    /** The HTTP success status this class documents, or null to leave the inferred default in place. */
    public function resolveStatus(RouteContext $context, string $fqcn): ?int;
}
