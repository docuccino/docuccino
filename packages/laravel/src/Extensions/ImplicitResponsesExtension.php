<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\IgnoreResponse;
use Docuccino\Attributes\Unauthenticated;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Validation\ResponseDraftApplier;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Provenance\Source;
use Docuccino\Laravel\Integrations\FormRequest\FormRequestRules;
use Docuccino\Laravel\Support\AuthMiddlewareDetector;
use ReflectionClass;

/**
 * Synthesizes the error responses that framework MIDDLEWARE and BINDING-TIME machinery produce but
 * the action body never throws, so the engine's throw analysis cannot see them (design §Errors —
 * Scramble's implicit 401/422/404/403). Each detected signal becomes a synthetic {@see ThrownException}
 * run through the SAME resolved exception→response chain the explicit-throw path uses, so the body
 * matches the document's error style (framework defaults or the Problem Details preset):
 *
 *  | Status | Signal |
 *  |--------|--------|
 *  | 401    | auth middleware detected AND the route is not `#[Unauthenticated]` |
 *  | 422    | a validated request body was recovered (Data / FormRequest / action rules()) |
 *  | 404    | the route has ≥1 model-bound path parameter (one 404 per operation, not per param) |
 *  | 403    | `can:` / `signed` / `verified` middleware, or a FormRequest `authorize()` not `return true` |
 *
 * Runs at integration precedence and LATE within the Errors phase, so an exception the action ALSO
 * throws explicitly (already applied by {@see ErrorResponsesExtension}) owns its status and this
 * synthesis is shadowed — no double response. Overridable by docblock/attribute/overlay, and each
 * status honours `#[IgnoreResponse]`. Skipped when `error_responses => 'none'`. 429 is left to the
 * rate-limit integration; CSRF 419, maintenance 503 and arbitrary custom-middleware throws are
 * deliberate non-goals (recorded in plan.md).
 */
#[ExtensionOrder(priority: Priorities::LATE)]
final class ImplicitResponsesExtension implements OperationExtension
{
    private const PRODUCER = 'integration:implicit-response';

    private const AUTHENTICATION = 'Illuminate\\Auth\\AuthenticationException';

    private const VALIDATION = 'Illuminate\\Validation\\ValidationException';

    private const MODEL_NOT_FOUND = 'Illuminate\\Database\\Eloquent\\ModelNotFoundException';

    private const AUTHORIZATION = 'Illuminate\\Auth\\Access\\AuthorizationException';

    /** requestBody producers that mean "a validated request was recovered". */
    private const VALIDATION_PRODUCERS = [
        'integration:form-request',
        'integration:spatie-data',
        'integration:laravel-actions',
    ];

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

        $ignored = $this->ignoredStatuses($context);

        // 401 — behind auth middleware and not explicitly public.
        if (AuthMiddlewareDetector::matches($context) && ! $context->attributes->has(Unauthenticated::class)) {
            $this->synthesize($operation, $context, 401, self::AUTHENTICATION, 'auth-middleware', $ignored);
        }

        // 422 — a validated request body was recovered for a write verb.
        if ($this->hasValidatedRequest($operation)) {
            $this->synthesize($operation, $context, 422, self::VALIDATION, 'validated-request', $ignored);
        }

        // 404 — one per operation, regardless of how many params are model-bound.
        if ($context->routeBindings !== []) {
            $this->synthesize($operation, $context, 404, self::MODEL_NOT_FOUND, 'route-model-binding', $ignored);
        }

        // 403 — authorization middleware or a FormRequest authorize() gate.
        $authorization = $this->authorizationSignal($context);
        if ($authorization !== null) {
            $this->synthesize($operation, $context, 403, self::AUTHORIZATION, $authorization, $ignored);
        }
    }

    /**
     * @param  array<int, true>  $ignored
     */
    private function synthesize(
        OperationDraft $operation,
        RouteContext $context,
        int $status,
        string $exceptionFqcn,
        string $signal,
        array $ignored,
    ): void {
        if (isset($ignored[$status])) {
            return;
        }

        $throw = new ThrownException($exceptionFqcn, $status, [], ThrowConfidence::Certain, ThrowDisposition::Signal);
        $source = $this->signalSource($context, $signal);

        foreach ($context->exceptionMappers as $mapper) {
            if (! $mapper->supports($throw, $context)) {
                continue;
            }

            $draft = $mapper->toResponse($throw, $context, $context->components);
            if ($draft === null) {
                continue;
            }

            $this->applier->apply($operation, $draft, self::PRODUCER, $source);

            return;
        }
    }

    /**
     * @return array<int, true>
     */
    private function ignoredStatuses(RouteContext $context): array
    {
        $ignored = [];
        foreach ($context->attributes->all(IgnoreResponse::class) as $ignore) {
            $ignored[$ignore->status] = true;
        }

        return $ignored;
    }

    /** True when a request extension recovered a validated body (its producer owns `requestBody`). */
    private function hasValidatedRequest(OperationDraft $operation): bool
    {
        $producer = $operation->guard()->producerFor('requestBody');

        return $producer !== null && in_array($producer, self::VALIDATION_PRODUCERS, true);
    }

    /**
     * The 403 signal name, or null. Authorization middleware first (`can:`/`signed`/`verified`), then
     * a FormRequest whose `authorize()` gate is not a literal `return true`.
     */
    private function authorizationSignal(RouteContext $context): ?string
    {
        foreach ($context->route->middleware as $middleware) {
            if (str_starts_with($middleware, 'can:')) {
                return 'can-middleware';
            }
            if ($middleware === 'signed' || str_starts_with($middleware, 'signed:')) {
                return 'signed-middleware';
            }
            if ($middleware === 'verified' || str_starts_with($middleware, 'verified:')) {
                return 'verified-middleware';
            }
        }

        return $this->formRequestAuthorizes($context) ? 'formrequest-authorize' : null;
    }

    /** Whether the route's FormRequest declares an authorize() gate that is not a literal `return true`. */
    private function formRequestAuthorizes(RouteContext $context): bool
    {
        $formRequest = FormRequestRules::classFor($context);
        if ($formRequest === null || ! class_exists($formRequest)) {
            return false;
        }

        $reflection = new ReflectionClass($formRequest);
        if (! $reflection->hasMethod('authorize')) {
            return false;
        }

        $method = $reflection->getMethod('authorize');
        $methodFile = $method->getFileName();
        // Only an authorize() declared in the FormRequest's own file is a real gate; the framework
        // default (no method / a base default) is not.
        if ($methodFile === false || $methodFile !== $reflection->getFileName()) {
            return false;
        }

        $line = $method->getStartLine();
        $analysis = $context->engine->analyzeAction(new ActionRef($methodFile, $formRequest, 'authorize', $line === false ? 0 : $line));
        $context->recordDependencyFiles($analysis->dependencyFiles);

        // A `return true;` gate never fails, so it produces no 403; anything provably otherwise (a
        // computed bool, `return false`, `$this->user()->can(...)`) can deny. Conservative when the
        // return type is unknown (empty returns): document no 403 rather than invent one — the deny
        // only surfaces when the engine can PROVE the gate is not an unconditional `true`.
        foreach ($analysis->returns as $return) {
            if (! ($return->type instanceof LiteralT && $return->type->value === true)) {
                return true;
            }
        }

        return false;
    }

    private function signalSource(RouteContext $context, string $signal): Source
    {
        $base = $context->actionSource();
        if ($base === null) {
            return new Source('', null, 'implicit:'.$signal);
        }

        return new Source($base->file, $base->line, 'implicit:'.$signal);
    }
}
