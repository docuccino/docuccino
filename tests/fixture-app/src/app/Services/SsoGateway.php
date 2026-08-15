<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Talks to the identity provider over HTTP. Both members are declared as bare framework types — the
 * provider's body shape is not ours to state — which is exactly the situation a route inherits when it
 * returns what a collaborator answered. Only ever analysed, never called.
 */
final class SsoGateway
{
    public function authorizeUrl(Request $request): string
    {
        return 'https://idp.example.test/authorize?state='.$request->string('state')->toString();
    }

    public function exchange(Request $request): JsonResponse
    {
        return new JsonResponse(json_decode((string) $request->getContent(), true));
    }
}
