<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\IgnoreResponse;
use Docuccino\Attributes\Response;
use Docuccino\Attributes\ResponseHeader;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\TypeGrammar\ImportContext;
use Docuccino\Core\TypeGrammar\TypeStringParser;

/**
 * Applies the response attributes as the attribute layer: `#[Response]` (per status, with a
 * parsed body type), `#[IgnoreResponse]` removals, and `#[ResponseHeader]` (grouped and merged per
 * status). Examples are the core attribute-examples extension's, which runs once every response exists.
 */
final class AttributeResponsesExtension implements OperationExtension
{
    public function __construct(
        private readonly TypeStringParser $types = new TypeStringParser,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Responses;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        // Unqualified class names in `type:` strings resolve against the controller file's imports and
        // namespace, so authors don't have to write FQCNs to get a real class instead of a bare object.
        $imports = ImportContext::forFile($context->actionRef->file === '' ? null : $context->actionRef->file);

        foreach ($context->attributes->all(IgnoreResponse::class) as $ignore) {
            $operation->removeResponse((string) $ignore->status);
        }

        foreach ($context->attributes->all(Response::class) as $attribute) {
            $status = (string) $attribute->status;
            $response = $operation->response($status);

            // Naming a code is also a statement about the range inference put it in
            // ({@see OperationDraft::supersedeStatusRange()}).
            $operation->supersedeStatusRange($status, Contribution::attribute($context->actionSource()));

            $response->setDescription('OK', Contribution::fallback());
            $response->setDescription($attribute->description, Contribution::attribute($context->actionSource()));

            if ($attribute->type === null) {
                continue;
            }

            // Unlike the idiomatic `response()->json(null, 204)` inference drops in silence, naming a body
            // AND a bodyless status in one attribute is a deliberate statement that can't be honoured.
            if ($response->isBodyless()) {
                $this->reportBodylessBody($context, $status, $attribute->type);

                continue;
            }

            // Naming the media type is the same statement one level down
            // ({@see ResponseDraft::supersedeMediaRange()}) — but only a media type the author actually
            // WROTE is a naming. The default publishes the body under JSON without claiming the stream a
            // producer could only document as any-media-type is really JSON, which nobody said.
            if ($attribute->mediaType !== null) {
                $response->supersedeMediaRange($attribute->mediaType, Contribution::attribute($context->actionSource()));
            }

            $mediaType = $attribute->mediaType ?? Response::DEFAULT_MEDIA_TYPE;

            // One declared shape, not a keyword-by-keyword patch: whatever a producer worked out about
            // the body it replaces comes off with it (SchemaDraft::declareShape()).
            $schema = $context->converter()->toSchema($this->types->parse($attribute->type, $imports))->schema;
            $response->content($mediaType)->declareShape($schema, Contribution::attribute($context->actionSource()));
        }

        $this->applyDeclaredComponents($operation, $context);
        $this->applyResponseHeaders($operation, $context, $imports);
    }

    /**
     * The component name a `#[Response]` declared for the status it declares, through the same
     * `claimComponentName()` every producer uses — one naming path, and the ordinary ladder settles it.
     * `#[ErrorComponent]` cannot reach a body an operation states itself (it is read off the exception
     * classes a route throws and the render methods on their path), so this is the anchor for one.
     *
     * A response component covers ALL of a status's content, so the name belongs to the status rather
     * than to the one representation the attribute declared — which is why two `#[Response]`s naming one
     * status differently is an authoring error rather than something to settle. Neither is claimed: a
     * published name is what a generated client calls the type, and picking one would make that a
     * function of attribute order.
     */
    private function applyDeclaredComponents(OperationDraft $operation, RouteContext $context): void
    {
        /** @var array<string, list<string>> $byStatus */
        $byStatus = [];
        foreach ($context->attributes->all(Response::class) as $attribute) {
            if ($attribute->component === null) {
                continue;
            }

            $status = (string) $attribute->status;
            $byStatus[$status] ??= [];
            if (! in_array($attribute->component, $byStatus[$status], true)) {
                $byStatus[$status][] = $attribute->component;
            }
        }

        foreach ($byStatus as $key => $names) {
            $status = (string) $key;

            if (count($names) > 1) {
                sort($names);
                $this->reportComponentContest($context, $status, $names);

                continue;
            }

            $operation->response($status)->claimComponentName($names[0], Contribution::attribute($context->actionSource()));
        }
    }

    /**
     * @param  list<string>  $names
     */
    private function reportComponentContest(RouteContext $context, string $status, array $names): void
    {
        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Warning,
            code: 'attribute.response-component-contested',
            message: sprintf(
                'Two #[Response(status: %s)] declarations name different components for one status (%s), so neither was used and the body keeps the name it would have had.',
                $status,
                implode(' and ', array_map(static fn (string $name): string => sprintf('"%s"', $name), $names)),
            ),
            source: $context->actionSource(),
            routeSignature: $context->route->signature(),
            help: 'A response component covers every representation of one status, so a status has one name. Put `component:` on one of the declarations, or spell the same name on both.',
        ));
    }

    private function reportBodylessBody(RouteContext $context, string $status, string $type): void
    {
        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Warning,
            code: 'attribute.body-on-bodyless-status',
            message: sprintf('#[Response(status: %s, type: %s)] names a body under a status HTTP forbids one on; the body is not documented.', $status, $type),
            source: $context->actionSource(),
            routeSignature: $context->route->signature(),
            help: 'Document the body under a status that may carry one, or drop `type:` — 1xx, 204, 205 and 304 responses never carry content (RFC 9110).',
        ));
    }

    private function applyResponseHeaders(OperationDraft $operation, RouteContext $context, ImportContext $imports): void
    {
        /** @var array<string, array<string, array<string, mixed>>> $byStatus */
        $byStatus = [];
        foreach ($context->attributes->all(ResponseHeader::class) as $header) {
            $status = (string) $header->status;
            $schema = $header->type !== null
                ? $context->converter()->toSchema($this->types->parse($header->type, $imports))->schema
                : ['type' => 'string'];

            $entry = ['schema' => $schema];
            if ($header->description !== null) {
                $entry['description'] = $header->description;
            }

            $headers = $byStatus[$status] ?? [];
            $headers[$header->name] = $entry;
            $byStatus[$status] = $headers;
        }

        foreach ($byStatus as $status => $headers) {
            // Naming a header AT a status is a statement that the status exists, so it retires the range
            // the same way #[Response] does ({@see OperationDraft::supersedeStatusRange()}).
            $operation->supersedeStatusRange((string) $status, Contribution::attribute($context->actionSource()));

            // `headers` is one guarded field every producer writes whole, so a declaration has to carry
            // what is already there or it replaces it — the declared header BESIDE a redirect's inherited
            // `Location`, not instead of it. A name written twice is the author's.
            $response = $operation->response((string) $status);
            $inherited = $response->resolvedField('headers');

            $response->set(
                'headers',
                is_array($inherited) ? [...$inherited, ...$headers] : $headers,
                Contribution::attribute($context->actionSource()),
            );
        }
    }
}
