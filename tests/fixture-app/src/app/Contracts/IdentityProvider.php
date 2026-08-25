<?php

declare(strict_types=1);

namespace App\Contracts;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the application asks of an identity provider, bound to a concrete gateway in a service provider.
 * A contract has no body to read, which is exactly why an action typed on one hands back a response the
 * analysis cannot describe.
 */
interface IdentityProvider
{
    public function introspect(Request $request): JsonResponse;
}
