<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\NeverT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;
use Docuccino\Laravel\Integrations\LaravelActions\LaravelAction;
use Docuccino\Laravel\Integrations\Support\FrameworkClasses;
use Docuccino\Laravel\Integrations\TimacdonaldJsonApi\TimacdonaldResourceReflector;

/**
 * Infers the success response(s) from the action's return paths (design §5). Every return type is
 * unwrapped to a `(status, payload)` pair and grouped by HTTP status, so distinct return paths that
 * carry distinct statuses become distinct responses:
 *
 * - A `JsonResponse<TPayload, TStatus>` (recovered by the bundled PHPStan extension for
 *   `response()->json($x, 201)`) contributes its PAYLOAD shape under the folded status — the whole
 *   `JsonResponse` object is never rendered as a generic `{type: object}`.
 * - `noContent()` surfaces as `JsonResponse<void, 204>`: an empty response body under status 204.
 * - Any other return type keeps its default `200 application/json` mapping.
 *
 * The folded status is read from the second type arg when it is a constant `int` literal; a dynamic
 * status (non-literal) falls back to the default `200`. Bare `void`/`never` returns (no
 * `JsonResponse` wrapper) contribute nothing.
 *
 * One integration-aware redirect: a `lorisleiva/laravel-actions` action that defines `jsonResponse()`
 * has its success body analysed from THAT method's return type, not the dispatched
 * `handle()`/`asController()` — the package's controller decorator transforms the dispatched value
 * through `jsonResponse()` for JSON clients, so it is the real wire shape ({@see LaravelAction::responseAnalysisRef()}).
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class InferredResponsesExtension implements OperationExtension
{
    private const DEFAULT_STATUS = '200';

    /** @var array<int, string> canonical reason phrases for the statuses this extension emits */
    private const REASONS = [
        '200' => 'OK',
        '201' => 'Created',
        '202' => 'Accepted',
        '204' => 'No Content',
    ];

    public function phase(): OperationPhase
    {
        return OperationPhase::Responses;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        /** @var array<string, array{payloads: list<DType>, location: ?SourceLocation, empty: bool}> $byStatus */
        $byStatus = [];

        foreach ($this->responseAnalysis($context)->returns as $return) {
            [$status, $payload, $empty] = $this->unwrap($return->type);

            // A bare void/never return (no JsonResponse wrapper) documents nothing.
            if ($payload === null && ! $empty) {
                continue;
            }

            $bucket = $byStatus[$status] ??= ['payloads' => [], 'location' => null, 'empty' => false];
            $bucket['location'] ??= $return->location;
            if ($payload !== null) {
                $bucket['payloads'][] = $payload;
            }
            $bucket['empty'] = $bucket['empty'] || $empty;
            $byStatus[$status] = $bucket;
        }

        if ($byStatus === []) {
            return;
        }

        // Deterministic response order regardless of return-path scheduling.
        ksort($byStatus);

        foreach ($byStatus as $status => $bucket) {
            // A numeric-string status key ('200') is coerced to int by PHP array semantics; restore
            // the string the draft API and reason table expect.
            $this->emit($operation, $context, (string) $status, $bucket['payloads'], $bucket['location']);
        }
    }

    /**
     * The analysis whose return sites define the success body. Normally the dispatched action's own
     * ({@see RouteContext::analysis()}); for a laravel-actions action defining `jsonResponse()` it is
     * that method's analysis instead (the transformed JSON wire shape). Analysing `jsonResponse()`
     * directly — rather than layering an override on top of the dispatched-method body — keeps a single
     * source for the 200 schema, so no stale keywords from the untransformed body can leak. Its
     * dependency files are recorded so editing `jsonResponse()` still invalidates the cached fragment.
     */
    private function responseAnalysis(RouteContext $context): ActionAnalysis
    {
        $responseRef = LaravelAction::responseAnalysisRef($context->actionRef);
        if ($responseRef === null) {
            return $context->analysis();
        }

        $analysis = $context->engine->analyzeAction($responseRef);
        $context->recordDependencyFiles($analysis->dependencyFiles);

        return $analysis;
    }

    /**
     * Unwrap a return type into its `(status, payloadType, isEmptyBody)` triple. A
     * `JsonResponse<payload, status>` yields the payload under its folded status (void payload =
     * empty body); anything else yields itself under `200`.
     *
     * @return array{0: string, 1: ?DType, 2: bool}
     */
    private function unwrap(DType $type): array
    {
        if ($type instanceof VoidT || $type instanceof NeverT) {
            return [self::DEFAULT_STATUS, null, false];
        }

        if ($type instanceof ClassT && $type->fqcn === FrameworkClasses::JSON_RESPONSE) {
            $status = $this->foldStatus($type->typeArgs[1] ?? null);
            $payload = $type->typeArgs[0] ?? null;

            if ($payload === null || $payload instanceof VoidT || $payload instanceof NeverT) {
                return [$status, null, true];
            }

            return [$status, $payload, false];
        }

        return [self::DEFAULT_STATUS, $type, false];
    }

    /** The folded status from a `JsonResponse` status type arg — a constant `int` literal, else 200. */
    private function foldStatus(?DType $statusArg): string
    {
        if ($statusArg instanceof LiteralT && is_int($statusArg->value)) {
            return (string) $statusArg->value;
        }

        return self::DEFAULT_STATUS;
    }

    /**
     * Emit one response: the unioned payload schema under `application/json`, or an empty-bodied
     * response when there is no payload (e.g. `noContent()`).
     *
     * @param  list<DType>  $payloads
     */
    private function emit(
        OperationDraft $operation,
        RouteContext $context,
        string $status,
        array $payloads,
        ?SourceLocation $location,
    ): void {
        $response = $operation->response($status);
        $response->setDescription(self::REASONS[$status] ?? 'OK', Contribution::fallback());

        if ($payloads === []) {
            return;
        }

        $type = count($payloads) === 1 ? $payloads[0] : UnionT::of($this->dedupe($payloads));
        $result = $context->converter()->toSchema($type);

        // Anchor the inferred body to the first contributing return path (design §4); an engine that
        // reports no usable location yields a sourceless contribution rather than a churny one.
        $source = $location !== null ? $context->sourceAt($location) : null;
        $contribution = Contribution::inference($source, $result->confidence);

        $mediaType = self::mediaType($payloads);
        foreach ($result->schema as $keyword => $value) {
            $response->content($mediaType)->set($keyword, $value, $contribution);
        }
    }

    /**
     * The response media type: JSON:API resources (either family, or a collection of them) serialise
     * as `application/vnd.api+json`; everything else stays `application/json`. A mixed union falls
     * back to `application/json`.
     *
     * @param  list<DType>  $payloads
     */
    private static function mediaType(array $payloads): string
    {
        foreach ($payloads as $payload) {
            if (! self::isJsonApi($payload)) {
                return 'application/json';
            }
        }

        return $payloads === [] ? 'application/json' : 'application/vnd.api+json';
    }

    private static function isJsonApi(DType $type): bool
    {
        if (! $type instanceof ClassT) {
            return false;
        }

        if (ResourceReflector::isJsonApiResource($type->fqcn) || TimacdonaldResourceReflector::isResource($type->fqcn)) {
            return true;
        }

        if (! ResourceReflector::isAnonymousCollection($type->fqcn)) {
            return false;
        }

        $item = $type->typeArgs[0] ?? null;

        return $item instanceof ClassT
            && (ResourceReflector::isJsonApiResource($item->fqcn) || TimacdonaldResourceReflector::isResource($item->fqcn));
    }

    /**
     * @param  list<DType>  $types
     * @return list<DType>
     */
    private function dedupe(array $types): array
    {
        $byKey = [];
        foreach ($types as $type) {
            $byKey[$type->canonicalKey()] = $type;
        }

        return array_values($byKey);
    }
}
