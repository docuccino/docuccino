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

        $fragments = [];
        foreach ($this->descriptors($resolved, $document) as $descriptor) {
            $fragment = $this->processRoute($descriptor, $document, $documentId, $engine, $resolved, $components, $bag);
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

    private function processRoute(
        RouteDescriptor $descriptor,
        DocumentConfig $document,
        string $documentId,
        TypeEngine $engine,
        ResolvedExtensions $resolved,
        ComponentRegistry $components,
        DiagnosticBag $bag,
    ): ?OperationFragment {
        $method = $descriptor->primaryMethod();
        $path = $this->oasPath($descriptor->uri);
        $signature = $descriptor->signature();

        try {
            $context = $this->contextBuilder->build(
                $descriptor,
                $document,
                $engine,
                $resolved->typeToSchema,
                $resolved->exceptionToResponse,
                $components,
            );

            if ($context === null) {
                return $this->onFailure($descriptor, $document, $documentId, $path, $method, 'action could not be reflected', $bag);
            }

            $operation = new OperationDraft;
            $this->pipeline->run($operation, $context, $resolved);
            $this->collectAnalysisDiagnostics($context, $signature, $bag);
            $this->assignIds($operation, $documentId, $method, $path);

            return new OperationFragment($path, $method, $operation->freeze(), $signature);
        } catch (Throwable $exception) {
            return $this->onFailure($descriptor, $document, $documentId, $path, $method, $exception->getMessage(), $bag);
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

    private function collectAnalysisDiagnostics(RouteContext $context, string $signature, DiagnosticBag $bag): void
    {
        foreach ($context->analysis()->diagnostics as $diagnostic) {
            $bag->add(new Diagnostic(
                severity: $diagnostic->severity,
                code: $diagnostic->code,
                message: $diagnostic->message,
                source: $diagnostic->source,
                routeSignature: $diagnostic->routeSignature ?? $signature,
                help: $diagnostic->help,
            ));
        }
    }

    private function oasPath(string $uri): string
    {
        $path = preg_replace('/\{([^}]+)\?}/', '{$1}', $uri);

        return $path ?? $uri;
    }
}
