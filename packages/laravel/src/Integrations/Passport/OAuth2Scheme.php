<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Passport;

/**
 * The OAS `oauth2` security scheme for a Passport-protected API (design §Phase 4 — Passport auto-
 * config): Passport's authorization-code, client-credentials and password grants mapped to OAS flows
 * over its conventional `/oauth/authorize` + `/oauth/token` endpoints. The flow `scopes` map carries
 * Passport's conventional `*` (full-access) scope; per-operation scopes live in the security
 * requirement, not here. Pure so the shape is dataset-testable.
 */
final class OAuth2Scheme
{
    /**
     * @return array<string, mixed>
     */
    public static function passport(string $baseUrl): array
    {
        $base = rtrim($baseUrl, '/');
        $authorize = $base.'/oauth/authorize';
        $token = $base.'/oauth/token';
        $scopes = ['*' => 'Full access to the API'];

        return [
            'type' => 'oauth2',
            'flows' => [
                'authorizationCode' => [
                    'authorizationUrl' => $authorize,
                    'tokenUrl' => $token,
                    'refreshUrl' => $token,
                    'scopes' => $scopes,
                ],
                'clientCredentials' => [
                    'tokenUrl' => $token,
                    'refreshUrl' => $token,
                    'scopes' => $scopes,
                ],
                'password' => [
                    'tokenUrl' => $token,
                    'refreshUrl' => $token,
                    'scopes' => $scopes,
                ],
            ],
        ];
    }
}
