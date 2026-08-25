<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\IdentityProvider;
use App\Services\SsoGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The two framework response classes an app hands back when nothing at the call site names a payload: a
 * 302 that carries no body at all, and a `JsonResponse` produced by a collaborator whose own return type
 * is bare. Only ever analysed, never dispatched.
 */
final class SsoRedirectController
{
    public function __construct(
        private readonly SsoGateway $gateway,
        private readonly IdentityProvider $provider,
    ) {}

    /** Kicks the browser out to the identity provider — a 302 with no JSON body. */
    public function connection(Request $request): RedirectResponse
    {
        return new RedirectResponse($this->gateway->authorizeUrl($request));
    }

    /** Hands back whatever the gateway answered, re-stamped with the status this route promises. */
    public function reset(Request $request): JsonResponse
    {
        $response = $this->gateway->exchange($request);

        return $response->setStatusCode(200);
    }

    /**
     * Relays the provider's own answer. The collaborator is a CONTRACT, so there is no body to follow and
     * nothing anywhere names the payload — the response stays the bare framework class.
     */
    public function introspect(Request $request): JsonResponse
    {
        return $this->provider->introspect($request);
    }
}
