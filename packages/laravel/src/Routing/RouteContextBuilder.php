<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Provenance\SourcePathResolver;
use Docuccino\Laravel\Docblock\DocblockReader;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use ReflectionNamedType;

/**
 * Builds the framework-agnostic {@see RouteContext} for one discovered route: it locates the
 * Laravel {@see Route}, reflects the handler, collects attributes (method > class), reads the
 * docblock summary/description, and derives path parameters and route-model bindings from the
 * template and the action signature. Returns null when the action cannot be reflected so the
 * generator emits a skeleton.
 *
 * @internal
 */
final class RouteContextBuilder
{
    public function __construct(
        private readonly Router $router,
        private readonly ?SourcePathResolver $pathResolver = null,
        private readonly RouteReflector $reflector = new RouteReflector,
        private readonly AttributeCollector $attributes = new AttributeCollector,
        private readonly DocblockReader $docblocks = new DocblockReader,
    ) {}

    /**
     * @param  list<TypeToSchema>  $typeMappers
     * @param  list<ExceptionToResponse>  $exceptionMappers
     * @param  list<RuleTransformer>  $ruleTransformers
     */
    public function build(
        RouteDescriptor $descriptor,
        DocumentConfig $document,
        TypeEngine $engine,
        array $typeMappers,
        array $exceptionMappers,
        array $ruleTransformers,
        ComponentRegistry $components,
        ?string $method = null,
    ): ?RouteContext {
        $route = $this->locate($descriptor);
        if ($route === null) {
            return null;
        }

        $reflected = $this->reflector->forRoute($route);
        if ($reflected === null) {
            return null;
        }

        $prose = $this->docblocks->read($reflected->reflection->getDocComment() ?: null);

        [$pathParameters, $optional] = $this->pathParameters($descriptor->uri);

        return new RouteContext(
            route: $descriptor,
            actionRef: $reflected->actionRef,
            attributes: $this->attributes->collect($reflected),
            engine: $engine,
            document: $document,
            typeMappers: $typeMappers,
            exceptionMappers: $exceptionMappers,
            ruleTransformers: $ruleTransformers,
            pathParameters: $pathParameters,
            optionalPathParameters: $optional,
            routeBindings: $this->routeBindings($reflected, $pathParameters),
            summary: $prose['summary'],
            description: $prose['description'],
            components: $components,
            pathResolver: $this->pathResolver,
            documentedMethod: $method ?? $descriptor->primaryMethod(),
        );
    }

    private function locate(RouteDescriptor $descriptor): ?Route
    {
        /** @var iterable<Route> $routes */
        $routes = $this->router->getRoutes();

        foreach ($routes as $route) {
            if ('/'.ltrim($route->uri(), '/') !== $descriptor->uri) {
                continue;
            }

            if (array_values(array_filter($route->methods(), 'is_string')) === $descriptor->methods) {
                return $route;
            }
        }

        return null;
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function pathParameters(string $uri): array
    {
        preg_match_all('/\{([^}]+)}/', $uri, $matches);

        $names = [];
        $optional = [];
        foreach ($matches[1] as $raw) {
            $optionalParam = str_ends_with($raw, '?');
            $name = rtrim($raw, '?');
            $names[] = $name;
            if ($optionalParam) {
                $optional[] = $name;
            }
        }

        return [$names, $optional];
    }

    /**
     * @param  list<string>  $pathParameters
     * @return array<string, string>
     */
    private function routeBindings(ReflectedAction $action, array $pathParameters): array
    {
        $bindings = [];
        foreach ($action->reflection->getParameters() as $parameter) {
            if (! in_array($parameter->getName(), $pathParameters, true)) {
                continue;
            }

            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                $bindings[$parameter->getName()] = $type->getName();
            }
        }

        return $bindings;
    }
}
