<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Passport;

use Docuccino\Attributes\Unauthenticated;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;
use Illuminate\Contracts\Config\Repository;

/**
 * Auto-configures Passport OAuth2 security (design §Phase 4 — Passport auto-config): on a route
 * Passport protects (`auth:api`/`auth:passport`, or any `scope:`/`scopes:` middleware) it registers
 * the `oauth2` scheme and sets the operation's `security` requirement, with the per-operation scopes
 * recovered from the scope middleware. Deferred when config already declares security schemes, and
 * skipped for `#[Unauthenticated]`. Class_exists-guarded on `Laravel\Passport\Passport`.
 */
final class PassportSecurityExtension implements OperationExtension
{
    public function __construct(
        private readonly Repository $config,
        private readonly ScopeMiddlewareParser $scopes = new ScopeMiddlewareParser,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Security;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        if ($context->document->securitySchemes() !== []) {
            return;
        }

        if ($context->attributes->has(Unauthenticated::class)) {
            return;
        }

        $middleware = $context->route->middleware;
        $scopes = $this->scopes->scopes($middleware);

        if (! $this->protects($middleware, $scopes)) {
            return;
        }

        $name = $context->components->registerSecurityScheme('passport', OAuth2Scheme::passport($this->baseUrl($context)));

        $operation->setSecurity([[$name => $scopes]], Contribution::integration('passport', $context->actionSource()));
    }

    /**
     * @param  list<string>  $middleware
     * @param  list<string>  $scopes
     */
    private function protects(array $middleware, array $scopes): bool
    {
        if ($scopes !== []) {
            return true;
        }

        foreach ($middleware as $entry) {
            if ($entry === 'auth:api' || $entry === 'auth:passport') {
                return true;
            }
        }

        return false;
    }

    private function baseUrl(RouteContext $context): string
    {
        $configured = $context->document->integration('passport')['url'] ?? null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $appUrl = $this->config->get('app.url');

        return is_string($appUrl) ? $appUrl : 'http://localhost';
    }
}
