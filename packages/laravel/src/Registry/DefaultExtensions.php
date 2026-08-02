<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Registry;

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Laravel\Exceptions\DefaultExceptionToResponse;
use Docuccino\Laravel\Extensions\AttributeOverridesExtension;
use Docuccino\Laravel\Extensions\AttributeParametersExtension;
use Docuccino\Laravel\Extensions\AttributeRequestBodyExtension;
use Docuccino\Laravel\Extensions\AttributeResponsesExtension;
use Docuccino\Laravel\Extensions\ErrorResponsesExtension;
use Docuccino\Laravel\Extensions\InferredResponsesExtension;
use Docuccino\Laravel\Extensions\PathParametersExtension;
use Docuccino\Laravel\Extensions\SecurityExtension;
use Docuccino\Laravel\Integrations\ApiResources\ApiResourcesIntegration;
use Docuccino\Laravel\Integrations\Eloquent\EloquentIntegration;
use Docuccino\Laravel\Integrations\Enum\EnumSchema;
use Docuccino\Laravel\Integrations\FormRequest\ValidationRequestExtension;
use Docuccino\Laravel\Integrations\Passport\PassportIntegration;
use Docuccino\Laravel\Integrations\Permission\PermissionIntegration;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderIntegration;
use Docuccino\Laravel\Integrations\RateLimit\RateLimitIntegration;
use Docuccino\Laravel\Integrations\Sanctum\SanctumIntegration;
use Docuccino\Laravel\Integrations\SpatieData\SpatieDataIntegration;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Docuccino\Laravel\Routing\LaravelRouteResolver;

/**
 * The built-in extension set (dogfooding the public API — arch-enforceable: everything here
 * implements only the core contracts). Class-strings are container-resolved; the core type
 * mappers are stateless instances. Resolved through the same {@see ExtensionRegistry} path as
 * config and programmatic registrations.
 *
 * @internal
 */
final class DefaultExtensions
{
    /**
     * @return list<class-string|object>
     */
    public static function all(): array
    {
        return [
            LaravelRouteResolver::class,
            PathParametersExtension::class,
            AttributeParametersExtension::class,
            AttributeRequestBodyExtension::class,
            InferredResponsesExtension::class,
            AttributeResponsesExtension::class,
            ErrorResponsesExtension::class,
            SecurityExtension::class,
            AttributeOverridesExtension::class,
            DefaultExceptionToResponse::class,
            // FormRequest / inline validate() request documentation (design §Phase 4). Consumes only
            // public contracts (dogfooding); the rule vocabulary registers through the same chain.
            ValidationRequestExtension::class,
            ...ValidationIntegration::transformers(),
            // Reflection-rich enum schemas (backing values, #[CaseDescription] → x-enumDescriptions);
            // ordered ahead of the core case-names-only mapper.
            EnumSchema::class,
            // API Resources (always-on; illuminate/http ships everywhere) — JsonResource toArray
            // shapes + Laravel 13 first-party JSON:API (added only when its class exists).
            ...ApiResourcesIntegration::extensions(),
            // Eloquent model schemas (always-on): columns from the engine refined by the model's
            // visible/hidden/appends/casts + class-level #[Hidden].
            ...EloquentIntegration::extensions(),
            // Rate limiting (always-on): `throttle` middleware → 429 + Retry-After/X-RateLimit-* headers.
            ...RateLimitIntegration::extensions(),
            // Conditional integrations (Telescope-style class_exists guard): registered only when the
            // target package is installed, so docuccino/laravel never hard-requires it.
            ...(SpatieDataIntegration::installed() ? SpatieDataIntegration::extensions() : []),
            // Spatie Query Builder (design §Phase 4 — the Scramble-Pro-beater): trace-recovered
            // allowedFilters/Sorts/Includes/Fields + pagination, added only when the package exists.
            ...(QueryBuilderIntegration::installed() ? QueryBuilderIntegration::extensions() : []),
            // Security auto-config (Telescope-style guards): Sanctum → bearer/cookie scheme;
            // Passport → oauth2 scheme + per-operation scopes from scope:/scopes: middleware.
            ...(SanctumIntegration::installed() ? SanctumIntegration::extensions() : []),
            ...(PassportIntegration::installed() ? PassportIntegration::extensions() : []),
            // spatie/laravel-permission: role:/permission:/role_or_permission: middleware →
            // x-permissions extension member + a generated description line.
            ...(PermissionIntegration::installed() ? PermissionIntegration::extensions() : []),
            ...DefaultTypeMappers::all(),
        ];
    }
}
