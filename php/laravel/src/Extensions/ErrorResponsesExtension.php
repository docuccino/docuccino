<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Extensions\Validation\ResponseDraftApplier;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Provenance\Source;
use Docuccino\Laravel\Exceptions\DeclaredErrorComponent;

/**
 * Turns the action's signalled exceptions into error responses (design §Errors): each runs through the
 * resolved {@see ExceptionToResponse} chain (first supports() wins) and merges in via
 * {@see ResponseDraftApplier}. Skipped when `error_responses => 'none'`.
 *
 * Also the one place `#[ErrorComponent]` is read, so the name an application declares reaches the
 * response through the same `claimComponentName()` every producer uses ({@see applyDeclarations()}).
 */
final class ErrorResponsesExtension implements OperationExtension
{
    public function __construct(
        private readonly ResponseDraftApplier $applier = new ResponseDraftApplier,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Errors;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        if ($context->document->errorResponses === 'none') {
            return;
        }

        /** @var array<string, array<string, DeclaredErrorComponent>> $declared */
        $declared = [];

        foreach ($context->analysis()->throws as $throw) {
            if ($throw->disposition !== ThrowDisposition::Signal) {
                continue;
            }

            // Whether an exception names its own component is answered by its whole hierarchy, so the
            // hierarchy's files key this route's fragment: an attribute added to a BASE class has to
            // rebuild every route that throws a subclass, and a warm build that missed it would publish
            // a different name from a cold one.
            $context->recordDependencyFiles(DeclarationFiles::of($throw->exceptionFqcn));

            $mapped = $context->mapThrow($throw);
            if ($mapped === null) {
                continue;
            }

            $this->applier->apply($operation, $mapped->draft, $mapped->mapper->producer(), $this->throwSource($context, $throw));

            $declaration = DeclaredErrorComponent::on($throw->exceptionFqcn);
            if ($declaration !== null) {
                $declared[$mapped->draft->status][$declaration->name] = $declaration;
            }
        }

        $this->applyDeclarations($operation, $context, $declared);
    }

    /**
     * Publish each declared name on the response its exception produced.
     *
     * The declaration replaces the DEFAULT name — the one derived from the status — and nothing else. A
     * producer that named this body said something the class cannot: one exception can render several
     * bodies and only the mapper that built one tells them apart, so a mapper's name stands and the
     * attribute is the way to rename what the built-in tiers called after the status. Two exceptions
     * declaring DIFFERENT names for one status describe one response that can only carry one name, so
     * neither takes it ({@see reportContest()}).
     *
     * @param  array<string, array<string, DeclaredErrorComponent>>  $declared  status → declared name → its declaration
     */
    private function applyDeclarations(OperationDraft $operation, RouteContext $context, array $declared): void
    {
        foreach ($declared as $key => $declarations) {
            $status = (string) $key;

            if (count($declarations) > 1) {
                $this->reportContest($context, $status, $declarations);

                continue;
            }

            // Exactly one, since a status only appears here once something declared for it.
            foreach ($declarations as $declaration) {
                $response = $operation->response($status);

                // A response that is a reference states no body of its own, so it is not this operation's
                // to name — the component it points at was named where it was defined.
                if ($response->resolvedField('$ref') === null && $declaration->replaces($response->componentClaim(), $status)) {
                    $response->claimComponentName($declaration->name, Contribution::attribute($this->declarationSource($context, $declaration)));
                }
            }
        }
    }

    /** Where the winning `#[ErrorComponent]` was written — the class that declared it, not the throw site. */
    private function declarationSource(RouteContext $context, DeclaredErrorComponent $declaration): ?Source
    {
        return $context->sourceAt(
            new SourceLocation($declaration->file ?? '', $declaration->line),
            $declaration->declaredBy,
        );
    }

    /**
     * One warning per status two declarations disagree over. Handing the response to whichever exception
     * the engine reported first would make the published name — a generated client's type name — a
     * function of encounter order, so the status keeps its default name and the author is told which two
     * classes to reconcile.
     *
     * @param  array<string, DeclaredErrorComponent>  $declarations
     */
    private function reportContest(RouteContext $context, string $status, array $declarations): void
    {
        ksort($declarations);

        $claims = [];
        foreach ($declarations as $name => $declaration) {
            $claims[] = sprintf('%s names it "%s"', $declaration->declaredBy, $name);
        }

        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Warning,
            code: 'attribute.error-component-contested',
            message: sprintf(
                'Exceptions this action signals declare different component names for its %s response (%s), which can carry only one, so the default name stands.',
                $status,
                implode('; ', $claims),
            ),
            source: $context->actionSource(),
            routeSignature: $context->route->signature(),
            help: sprintf(
                'One response carries one name. Keep #[ErrorComponent] on the exception the %s response really is and drop it from the others, or document the errors under statuses of their own. Where the bodies genuinely differ, register an ExceptionToResponse that builds and names each one.',
                $status,
            ),
        ));
    }

    /**
     * The throw site (first call-chain frame), falling back to the action when the engine had no usable
     * location — so an explicit throw carries a source just like a synthesized one, never none.
     */
    private function throwSource(RouteContext $context, ThrownException $throw): ?Source
    {
        $frame = $throw->callChain[0] ?? null;
        if ($frame !== null && $frame->location->file !== '') {
            return $context->sourceAt($frame->location, $frame->symbol === '' ? null : $frame->symbol);
        }

        return $context->actionSource();
    }
}
