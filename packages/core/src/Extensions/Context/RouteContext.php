<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\TypeEngine;

/**
 * Everything an {@see OperationExtension} needs about the route it is documenting (design §5):
 * the discovered route, its engine action reference, the collected attributes (method > class),
 * docblock prose, and the document being built. The inference handle is lazy — {@see analysis()}
 * calls the engine at most once per context and memoises, so extensions in different phases
 * share one analysis pass. {@see converter()} exposes the resolved type→schema chain over a
 * per-route {@see ComponentRegistry} — hoisted components are collected into the operation's
 * fragment after the pipeline runs.
 */
final class RouteContext
{
    private ?ActionAnalysis $analysis = null;

    private ?SchemaConverter $converter = null;

    /**
     * @param  list<TypeToSchema>  $typeMappers  the resolved type→schema chain (document-wide)
     * @param  list<ExceptionToResponse>  $exceptionMappers  the resolved exception→response chain
     * @param  list<string>  $pathParameters  route template parameter names, in template order
     * @param  list<string>  $optionalPathParameters  the subset declared optional (`{param?}`)
     * @param  array<string, string>  $routeBindings  path parameter name → bound model FQCN
     */
    public function __construct(
        public readonly RouteDescriptor $route,
        public readonly ActionRef $actionRef,
        public readonly AttributeSet $attributes,
        public readonly TypeEngine $engine,
        public readonly DocumentConfig $document,
        public readonly array $typeMappers = [],
        public readonly array $exceptionMappers = [],
        public readonly array $pathParameters = [],
        public readonly array $optionalPathParameters = [],
        public readonly array $routeBindings = [],
        public readonly ?string $summary = null,
        public readonly ?string $description = null,
        public readonly ComponentRegistry $components = new ComponentRegistry,
    ) {}

    /** The action's inference result, computed once and memoised. */
    public function analysis(): ActionAnalysis
    {
        return $this->analysis ??= $this->engine->analyzeAction($this->actionRef);
    }

    /**
     * The type→schema converter for this route, hoisting into the document-wide component
     * registry so cross-route `$ref`s stay consistent and named schemas dedupe once.
     */
    public function converter(): SchemaConverter
    {
        return $this->converter ??= new SchemaConverter($this->typeMappers, $this->engine, $this->components);
    }
}
