<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Pipeline;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Overlay\OverlayDocument;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Validation\Validator;
use Docuccino\Laravel\Registry\DefaultExtensions;
use Docuccino\Laravel\Registry\ExtensionRegistry;
use Docuccino\Laravel\Registry\ResolvedExtensions;
use Docuccino\Laravel\Routing\RouteContextBuilder;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * The document pipeline (design §5): resolve extensions late, discover routes, build each
 * operation in phased isolation, assign identities, assemble, apply overlays/transformers,
 * validate against the bundled UIR schema, and return a {@see UirDocument} with deterministic
 * diagnostics. A broken route yields a skeleton (or is omitted) and an error diagnostic — never a
 * dead build.
 */
final class DocumentGenerator
{
    public function __construct(
        private readonly ExtensionRegistry $registry,
        private readonly Container $container,
        private readonly RouteContextBuilder $contextBuilder,
        private readonly OperationPipeline $pipeline,
        private readonly Assembler $assembler,
        private readonly Validator $validator,
        private readonly string $generatorVersion,
        private readonly FragmentCache $cache = new FragmentCache(false, '', '', '', ''),
        private readonly IdentityGenerator $identity = new IdentityGenerator,
    ) {}

    /**
     * @param  list<class-string|object>  $configExtensions
     * @param  list<OverlayDocument>  $overlays
     */
    public function generate(
        DocumentConfig $document,
        TypeEngine $engine,
        array $configExtensions = [],
        array $overlays = [],
    ): GenerationResult {
        $resolved = $this->registry->resolve($this->container, DefaultExtensions::all(), $configExtensions);
        $documentId = $this->identity->documentId($document->key);
        $components = new ComponentRegistry;
        $bag = new DiagnosticBag;

        $configHash = $document->hash();
        $extensionClasses = $resolved->cacheSignature();

        $fragments = [];
        foreach ($this->descriptors($resolved, $document) as $descriptor) {
            $fragment = $this->processRoute($descriptor, $document, $documentId, $engine, $resolved, $components, $bag, $configHash, $extensionClasses);
            if ($fragment !== null) {
                $fragments[] = $fragment;
                $bag->addAll($fragment->diagnostics);
            }
        }

        $assembly = $this->assembler->assemble(
            $fragments,
            $document,
            $documentId,
            $components,
            $overlays,
            $resolved->documentTransformers,
            $this->generatorVersion,
        );
        $bag->addAll($assembly->diagnostics);

        $validation = $this->validator->validate($assembly->document);
        foreach ($validation->errors as $error) {
            $bag->add(new Diagnostic(
                severity: Severity::Error,
                code: 'document.schema-invalid',
                message: trim($error->pointer.' '.$error->message),
            ));
        }

        return new GenerationResult(UirDocument::fromArray($assembly->document), $bag->sorted());
    }

    /**
     * @return list<RouteDescriptor>
     */
    private function descriptors(ResolvedExtensions $resolved, DocumentConfig $document): array
    {
        $descriptors = [];
        foreach ($resolved->routeResolvers as $resolver) {
            foreach ($resolver->resolve($document) as $descriptor) {
                $descriptors[$descriptor->primaryMethod().' '.$descriptor->uri] ??= $descriptor;
            }
        }

        ksort($descriptors);

        return array_values($descriptors);
    }

    /**
     * @param  list<string>  $extensionClasses
     */
    private function processRoute(
        RouteDescriptor $descriptor,
        DocumentConfig $document,
        string $documentId,
        TypeEngine $engine,
        ResolvedExtensions $resolved,
        ComponentRegistry $components,
        DiagnosticBag $bag,
        string $configHash,
        array $extensionClasses,
    ): ?OperationFragment {
        $method = $descriptor->primaryMethod();
        $path = $this->oasPath($descriptor->uri);
        $signature = $descriptor->signature();

        $cacheKey = $this->cache->key($descriptor->cacheSignature(), $configHash, $extensionClasses);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            // Warm hit: restore the route's components without touching the type engine (design §10).
            $this->restoreComponents($cached, $components);

            return $cached;
        }

        // Snapshot the shared registry so a route that throws after registering components rolls
        // back cleanly, leaving no orphaned schemas from a route that never entered the document.
        $snapshot = $components->snapshot();

        try {
            $context = $this->contextBuilder->build(
                $descriptor,
                $document,
                $engine,
                $resolved->typeToSchema,
                $resolved->exceptionToResponse,
                $resolved->ruleTransformers,
                $components,
            );

            if ($context === null) {
                $components->restore($snapshot);

                return $this->onFailure($descriptor, $document, $documentId, $path, $method, 'action could not be reflected', $bag);
            }

            $operation = new OperationDraft;
            $this->pipeline->run($operation, $context, $resolved);
            $diagnostics = $this->analysisDiagnostics($context, $signature);
            $this->assignIds($operation, $documentId, $method, $path);

            $frozen = $operation->freeze();
            [$referencedSchemas, $referencedSchemaIds] = $this->componentClosure($frozen->toArray(), $components);

            $fragment = new OperationFragment($path, $method, $frozen, $signature, $diagnostics, $referencedSchemas, $referencedSchemaIds);
            // Merge trace-derived dependency files (design §10 seam): integrations that recover facts
            // by tracing widen the cache key, so a deep chain invalidates when any traced file changes.
            $this->cache->put($cacheKey, $fragment, $context->dependencyFiles());

            return $fragment;
        } catch (Throwable $exception) {
            $components->restore($snapshot);

            return $this->onFailure($descriptor, $document, $documentId, $path, $method, $exception->getMessage(), $bag);
        }
    }

    /**
     * The transitive closure of the schema components this operation references (design §5 hoist /
     * arch F7): every component reachable from a `$ref` in the operation, plus every component those
     * components in turn reference. Carrying the full closure — not just the components this route
     * happened to register first — makes each cached fragment self-sufficient: a warm hit restores
     * everything it points at, so removing the route that first *owned* a shared component never
     * leaves a surviving referencer with a dangling `$ref`.
     *
     * @param  array<string, mixed>  $operation
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, string>}
     */
    private function componentClosure(array $operation, ComponentRegistry $components): array
    {
        $registry = $components->schemas();
        $ids = $components->schemaIds();

        $schemas = [];
        $schemaIds = [];
        $seen = [];
        $queue = $this->schemaRefs($operation);

        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($seen[$name]) || ! isset($registry[$name])) {
                continue;
            }
            $seen[$name] = true;

            $schemas[$name] = $registry[$name];
            if (isset($ids[$name])) {
                $schemaIds[$name] = $ids[$name];
            }

            foreach ($this->schemaRefs($registry[$name]) as $nested) {
                if (! isset($seen[$nested])) {
                    $queue[] = $nested;
                }
            }
        }

        return [$schemas, $schemaIds];
    }

    /**
     * The component-schema names a node references via `$ref` (`#/components/schemas/NAME`), scanned
     * recursively.
     *
     * @param  array<array-key, mixed>  $node
     * @return list<string>
     */
    private function schemaRefs(array $node): array
    {
        $refs = [];
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && str_starts_with($value, '#/components/schemas/')) {
                $refs[] = substr($value, strlen('#/components/schemas/'));

                continue;
            }
            if (is_array($value)) {
                foreach ($this->schemaRefs($value) as $ref) {
                    $refs[] = $ref;
                }
            }
        }

        return $refs;
    }

    private function restoreComponents(OperationFragment $fragment, ComponentRegistry $components): void
    {
        foreach ($fragment->componentSchemas as $name => $schema) {
            $components->registerSchema($name, $schema, $fragment->componentSchemaIds[$name] ?? null);
        }
    }

    private function onFailure(
        RouteDescriptor $descriptor,
        DocumentConfig $document,
        string $documentId,
        string $path,
        string $method,
        string $reason,
        DiagnosticBag $bag,
    ): ?OperationFragment {
        $bag->add(new Diagnostic(
            severity: Severity::Error,
            code: 'route.build-failed',
            message: sprintf('Failed to document %s: %s', $descriptor->signature(), $reason),
            routeSignature: $descriptor->signature(),
            help: $document->onRouteError === 'omit' ? 'Route omitted from the document.' : 'A skeleton operation was emitted in its place.',
        ));

        if ($document->onRouteError === 'omit') {
            return null;
        }

        $operation = new OperationDraft;
        $operation->setDescription('Documentation could not be generated for this route.', Contribution::fallback());
        $this->assignIds($operation, $documentId, $method, $path);

        return new OperationFragment($path, $method, $operation->freeze(), $descriptor->signature());
    }

    private function assignIds(OperationDraft $operation, string $documentId, string $method, string $path): void
    {
        $operationId = $this->identity->operationId($documentId, $method, $path);
        $operation->assignId($operationId);
        $operation->assignChildIds(
            fn (string $in, string $name): string => $this->identity->parameterId($operationId, $in, $name),
            fn (string $status, string $mediaType): ?string => $mediaType === '' ? null : $this->identity->responseId($operationId, $status, $mediaType),
        );
    }

    /**
     * The route's analysis diagnostics, tagged with its signature — returned so they live on the
     * fragment (and are therefore cached and replayed on a warm hit) rather than added straight to
     * the document bag.
     *
     * @return list<Diagnostic>
     */
    private function analysisDiagnostics(RouteContext $context, string $signature): array
    {
        $diagnostics = [];
        foreach ($context->analysis()->diagnostics as $diagnostic) {
            $diagnostics[] = new Diagnostic(
                severity: $diagnostic->severity,
                code: $diagnostic->code,
                message: $diagnostic->message,
                source: $diagnostic->source,
                routeSignature: $diagnostic->routeSignature ?? $signature,
                help: $diagnostic->help,
            );
        }

        return $diagnostics;
    }

    private function oasPath(string $uri): string
    {
        $path = preg_replace('/\{([^}]+)\?}/', '{$1}', $uri);

        return $path ?? $uri;
    }
}
