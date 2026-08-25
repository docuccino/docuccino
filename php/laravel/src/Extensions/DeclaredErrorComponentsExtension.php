<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\Response;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\SharedErrorResponses;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Schema\ComponentNames;

/**
 * Whether each `#[Response(errorComponent:)]` an operation carries could reach anything at all, asked at
 * `Finalize` because that is the first point the answer is knowable: the status only becomes a `$ref`
 * when a mapper resolves at `Errors`, after {@see AttributeResponsesExtension} has written the claim.
 *
 * The claim itself is honoured by one pass, the shared-error hoist, which groups error statuses only and
 * walks past anything already a reference. Where neither is true of the status the author wrote on, the
 * argument is inert — and an argument that does nothing has to say so, which is the whole reason
 * `errorComponent:` exists rather than `#[ErrorComponent]` on the action.
 *
 * A name no component key could carry is {@see AttributeResponsesExtension}'s to report, and never
 * reaches here: it is never a claim.
 */
final class DeclaredErrorComponentsExtension implements OperationExtension
{
    public function phase(): OperationPhase
    {
        return OperationPhase::Finalize;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        /** @var array<string, string> $declared */
        $declared = [];
        foreach ($context->attributes->all(Response::class) as $attribute) {
            $name = $attribute->errorComponent;
            if ($name !== null && ComponentNames::isLegal($name)) {
                $declared[(string) $attribute->status] ??= $name;
            }
        }

        ksort($declared);

        foreach ($declared as $key => $name) {
            $status = (string) $key;

            if (! SharedErrorResponses::shares($status)) {
                $this->report(
                    $context,
                    $status,
                    $name,
                    sprintf('%s is not an error status, and only an error body is ever published as a shared component, so the name was not used.', $status),
                    'An error component names one of the bodies a 4xx or 5xx shares. Move the name to the error status it describes, or drop it — a success body\'s schema is named after the class `type:` points at.',
                );

                continue;
            }

            if ($operation->response($status)->resolvedField('$ref') !== null) {
                $this->report(
                    $context,
                    $status,
                    $name,
                    sprintf('the %s response is a reference to a shared component, which was named where that component is defined, so the name was not used.', $status),
                    'A response that is a reference states no body of its own to name. Name the component at its own definition — the Problem Details preset names its own, and an ExceptionToResponse of yours names whatever it builds — or stop referencing it from this status.',
                );
            }
        }
    }

    private function report(RouteContext $context, string $status, string $name, string $because, string $help): void
    {
        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Warning,
            code: 'attribute.error-component-unreachable',
            message: sprintf('#[Response(status: %s, errorComponent: "%s")] names nothing: %s', $status, $name, $because),
            source: $context->actionSource(),
            routeSignature: $context->route->signature(),
            help: $help,
        ));
    }
}
