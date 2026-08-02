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
use Docuccino\Laravel\Integrations\Enum\EnumSchema;
use Docuccino\Laravel\Integrations\FormRequest\ValidationRequestExtension;
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
            // Conditional integrations (Telescope-style class_exists guard): registered only when the
            // target package is installed, so docuccino/laravel never hard-requires it.
            ...(SpatieDataIntegration::installed() ? SpatieDataIntegration::extensions() : []),
            ...DefaultTypeMappers::all(),
        ];
    }
}
