<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Patch\Contribution;
use ReflectionMethod;
use Throwable;

/**
 * The FLAGSHIP tier of the error-response chain (design §6): documents the app's REAL error shapes
 * by analysing the code that actually renders each exception. For a thrown exception it resolves,
 * in order:
 *   1. a render callback (`$exceptions->render(fn (T $e) => …)`) whose first-parameter type the
 *      exception `is_a` — analysed by file+line, with the parameter narrowed to the thrown type so a
 *      catch-all `fn (Throwable $e)` resolves the one reachable branch;
 *   2. the exception's own `render()` method;
 *   3. a `Responsable` exception's `toResponse()`.
 * The recovered `JsonResponse<payload, status>` becomes the documented response. A body too dynamic
 * to fold to a `JsonResponse` → defer (null) + an info diagnostic, so the next tier (preset /
 * framework defaults) fills in. Ordered FIRST so ground truth wins over any preset or default.
 * Producer integration:inferred-handler. Handler files join the route's fragment-cache deps.
 */
#[ExtensionOrder(priority: Priorities::FIRST)]
final class InferredHandlerExceptionToResponse implements ExceptionToResponse
{
    private const RESPONSABLE = 'Illuminate\\Contracts\\Support\\Responsable';

    /** @var array<string, CallableRef|null> memoised candidate per exception FQCN */
    private array $candidates = [];

    public function __construct(private readonly HandlerReflector $reflector) {}

    public function supports(ThrownException $exception, RouteContext $context): bool
    {
        return $this->candidate($exception->exceptionFqcn) !== null;
    }

    public function producer(): string
    {
        return 'integration:inferred-handler';
    }

    public function toResponse(
        ThrownException $exception,
        RouteContext $context,
        ComponentRegistry $components,
    ): ?ResponseDraft {
        $callable = $this->candidate($exception->exceptionFqcn);
        if ($callable === null) {
            return null;
        }

        $analysis = $context->engine->analyzeCallable($callable);
        // Cache soundness (design §10): editing the handler must invalidate this route's fragment.
        $context->recordDependencyFiles($analysis->dependencyFiles);

        $response = HandlerResponseBuilder::build($analysis, $context, Contribution::integration('inferred-handler'));
        if ($response === null) {
            $components->addDiagnostic(new Diagnostic(
                severity: Severity::Info,
                code: 'inferred-handler.too-dynamic',
                message: sprintf(
                    'The exception handler rendering %s could not be folded to a JSON response; deferring to the next error tier.',
                    $exception->exceptionFqcn,
                ),
                help: 'Return a response()->json([...], status) with a constant status from the handler, or document it with an attribute.',
            ));

            return null;
        }

        return $response;
    }

    private function candidate(string $fqcn): ?CallableRef
    {
        if (array_key_exists($fqcn, $this->candidates)) {
            return $this->candidates[$fqcn];
        }

        return $this->candidates[$fqcn] = $this->resolve($fqcn);
    }

    private function resolve(string $fqcn): ?CallableRef
    {
        foreach ($this->reflector->renderCallbacks() as $callback) {
            if ($fqcn === $callback->exceptionType || is_a($fqcn, $callback->exceptionType, true)) {
                // A closure located by line; the parameter is narrowed to the thrown type (a no-op for
                // a callback typed to the exact exception, branch selection for a catch-all).
                return new CallableRef($callback->file, null, null, $callback->line, $callback->parameterName, $fqcn);
            }
        }

        return $this->renderableMethod($fqcn, 'render')
            ?? ($this->isResponsable($fqcn) ? $this->renderableMethod($fqcn, 'toResponse') : null);
    }

    /**
     * A {@see CallableRef} for a `render()`/`toResponse()` the exception declares itself, or null when
     * it declares none (or the method is unreflectable).
     */
    private function renderableMethod(string $fqcn, string $method): ?CallableRef
    {
        try {
            if (! class_exists($fqcn) || ! method_exists($fqcn, $method)) {
                return null;
            }

            $reflection = new ReflectionMethod($fqcn, $method);
            $file = $reflection->getFileName();
            if ($file === false) {
                return null;
            }

            // Analyse the method on the class that declares it (its real source location).
            return new CallableRef($file, $reflection->getDeclaringClass()->getName(), $method);
        } catch (Throwable) {
            return null;
        }
    }

    private function isResponsable(string $fqcn): bool
    {
        return interface_exists(self::RESPONSABLE) && is_a($fqcn, self::RESPONSABLE, true);
    }
}
