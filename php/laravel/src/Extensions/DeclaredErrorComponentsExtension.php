<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\ErrorComponent;
use Docuccino\Attributes\Response;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\SharedErrorResponses;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Schema\ComponentNames;
use Docuccino\Laravel\Routing\AttributeCollector;
use ReflectionException;
use ReflectionMethod;

/**
 * Everything an author wrote about an error component's NAME that reached nothing, reported in one
 * place. Runs at `Finalize` for two reasons: a status only becomes a `$ref` once a mapper resolves at
 * `Errors`, so that is the first point the question is answerable at all; and nothing here is gated on
 * `error_responses`, which `ErrorResponsesExtension` returns early on — a misplaced attribute is
 * misplaced whether or not the build documents errors, and `'none'` is the value an application with no
 * config key resolves to.
 *
 * Two things reach nothing. A `#[Response(errorComponent:)]` on a status the shared-error hoist never
 * groups (below 400) or has already turned into a reference; and an `#[ErrorComponent]` on the ACTION,
 * whose two anchors are an exception class and a render method ({@see ErrorResponsesExtension}).
 *
 * A name no component key could carry is {@see AttributeResponsesExtension}'s to report, and never
 * reaches here: it is never a claim.
 */
final class DeclaredErrorComponentsExtension implements OperationExtension
{
    public function __construct(
        private readonly AttributeCollector $collector = new AttributeCollector,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Finalize;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $this->reportUnreadDeclarations($context);

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

    /**
     * One warning per `#[ErrorComponent]` written on the ACTION, where nothing reads it: `TARGET_METHOD`
     * permits the placement because a render METHOD is one of the two anchors, and an author naming the
     * error on the action answering it gets their old names back.
     *
     * Deliberately the action's OWN attributes rather than the route's set, which includes the
     * controller's and every parent's. A base controller carrying one is ordinary Laravel, and the set is
     * walked per route, so reporting off it says one mistake once per route of every child — measured at
     * six routes for one attribute on one base, and linear in the API from there. There is nowhere in a
     * route-scoped, fragment-cached pass to say it once instead: a per-build "already said" set would
     * make what the document reports a function of which routes came from cache, and a warm build that
     * reports less than a cold one is a silent degradation. So the placement that is one report stays,
     * and the one that would be N does not — the names it fails to change are the names they would have
     * been either way.
     *
     * @return list<string>
     */
    private function unreadOnAction(RouteContext $context): array
    {
        $class = $context->actionRef->class;
        $method = $context->actionRef->method;

        if ($class === null || ! method_exists($class, $method)) {
            return [];
        }

        try {
            $reflection = new ReflectionMethod($class, $method);
        } catch (ReflectionException) {
            return [];
        }

        // Null `$onUnreadable`: one that cannot be instantiated was already reported as
        // `attribute.unreadable` when the route's set was built, and has no name to report here anyway.
        return array_map(
            static fn (ErrorComponent $declaration): string => $declaration->name,
            $this->collector->collectOne($reflection, $context->actionRef->symbol())->all(ErrorComponent::class),
        );
    }

    private function reportUnreadDeclarations(RouteContext $context): void
    {
        foreach ($this->unreadOnAction($context) as $name) {
            $context->components->addDiagnostic(new Diagnostic(
                severity: Severity::Warning,
                code: 'attribute.error-component-unread',
                message: sprintf(
                    '#[ErrorComponent(\'%s\')] on an action names nothing: the attribute names an error where the error is defined, not where an operation answers with it.',
                    $name,
                ),
                source: $context->actionSource(),
                routeSignature: $context->route->signature(),
                help: 'Put it on the exception class the error is raised from, or on the render method that builds the body. For one status of this operation, name it with the `errorComponent:` argument of the #[Response] that declares it.',
            ));
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
