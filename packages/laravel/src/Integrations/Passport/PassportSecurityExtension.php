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
        private readonly PassportRuntime $runtime = new PassportRuntime,
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
        $requirements = $this->scopes->parse($middleware);

        if (! $this->protects($middleware, $requirements)) {
            return;
        }

        $scheme = OAuth2Scheme::passport(
            $this->baseUrl($context),
            $this->path(),
            $this->scopeCatalogue($requirements),
            $this->runtime->passwordGrant,
            $this->runtime->implicitGrant,
        );

        $name = $context->components->registerSecurityScheme('passport', $scheme);

        $operation->setSecurity($requirements->toSecurity($name), Contribution::integration('passport', $context->actionSource()));
    }

    /**
     * @param  list<string>  $middleware
     */
    private function protects(array $middleware, ScopeRequirements $requirements): bool
    {
        if (! $requirements->isEmpty()) {
            return true;
        }

        foreach ($middleware as $entry) {
            if ($entry === 'auth:api' || $entry === 'auth:passport') {
                return true;
            }
        }

        return false;
    }

    /**
     * The oauth2 flow scope map: the app's real Passport scope catalogue (`Passport::tokensCan()`),
     * augmented with any scope this route references that the catalogue is missing (so the security
     * requirement stays OAS-valid even in apps that never called `tokensCan()`). Missing scopes get
     * their id as description — the honest floor when no catalogue entry exists.
     *
     * @return array<string, string>
     */
    private function scopeCatalogue(ScopeRequirements $requirements): array
    {
        $catalogue = $this->runtime->scopes;

        foreach ($requirements->all() as $scope) {
            if (! array_key_exists($scope, $catalogue)) {
                $catalogue[$scope] = $scope;
            }
        }

        return $catalogue;
    }

    private function path(): string
    {
        $path = $this->config->get('passport.path');

        return is_string($path) && $path !== '' ? $path : 'oauth';
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
