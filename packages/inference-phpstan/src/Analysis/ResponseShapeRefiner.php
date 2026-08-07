<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\NeverT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\StatusMarkerT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Trace\Callee;
use Docuccino\Inference\PhpStan\Trace\CalleeResolver;
use Docuccino\Inference\PhpStan\Translation\TypeTranslator;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Type;

/**
 * Response-shape refinement through project-code helper indirection (the inferred-handler flagship).
 *
 * A renderer builds its response through a helper — `__invoke` → `renderNotFound()` →
 * `ProblemResponse::make(...): JsonResponse` — and the DECLARED bare `JsonResponse` return hint erases
 * the payload/status generic at every call site, so PHPStan hands the harvest a shapeless
 * `JsonResponse`. This refiner recovers the real shape by following the indirection: when a return
 * expression's type is a bare response class AND it is a call into PROJECT code (never vendor), it
 * analyses the callee's OWN return sites and substitutes the richer recovered type — a
 * `JsonResponse<payload, status, contentType>`.
 *
 * Design invariants (see docs/design/inference-embedding.md §4 and the plan decision log):
 *   - BOUNDED: reuses the engine's descent depth + per-analysis file budget; declines past either.
 *   - MEMOISED per callee `class::method` — call-INDEPENDENT (payload shape, content type, and a status
 *     that is either a literal or a pass-through {@see RefinedResponse::$statusParam}), so ordering
 *     never affects results and one helper analysed once is reused across every route reaching it.
 *   - TRANSITIVE within bounds: a helper that calls another helper folds through both hops; a
 *     pass-through status re-homes onto the outer callee's parameter at each hop.
 *   - ARGUMENTS MATTER, honestly: a constant-foldable status argument (`make($title, 422, $errors)`)
 *     binds at the call site; a status that folds to neither a literal nor a parameter stays
 *     permissive (recovered as {@see UnknownT}, never guessed), and payload/content-type are still
 *     recovered when the status does not fold.
 *   - CACHE-SOUND: every descended helper file is reported via {@see takeFiles()} so it lands in the
 *     analysis's `dependencyFiles` — editing a helper invalidates the route fragment.
 *   - VENDOR IS NEVER DESCENDED: the {@see ProjectFilter} gate is the containment boundary.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final class ResponseShapeRefiner
{
    /** Bare (generic-erased) response classes a helper's declared return type collapses to. */
    private const RESPONSE_FQCNS = [
        'Illuminate\\Http\\JsonResponse',
        'Illuminate\\Http\\Response',
        'Symfony\\Component\\HttpFoundation\\JsonResponse',
        'Symfony\\Component\\HttpFoundation\\Response',
    ];

    /** The canonical FQCN the recovered shape is emitted under (the shape the pipeline unwraps). */
    public const CANONICAL_RESPONSE = 'Illuminate\\Http\\JsonResponse';

    /**
     * Per-callee memo: call-independent shape + the files that callee's descent touched. The file set
     * is re-contributed on a memo hit so a second route reaching the same helper still records it as a
     * dependency (soundness survives memoisation).
     *
     * @var array<string, array{result: RefinedResponse|null, files: list<string>}>
     */
    private array $memo = [];

    /** @var array<string, true> cycle guard over the descent (callee `class::method`). */
    private array $inProgress = [];

    /** @var array<string, true> files touched by the CURRENT analysis, drained by {@see takeFiles()}. */
    private array $currentFiles = [];

    public function __construct(
        private readonly RuntimeAdapter $adapter,
        private readonly TypeTranslator $translator,
        private readonly FileAnalyzer $fileAnalyzer,
        private readonly CalleeResolver $calleeResolver,
        private readonly ProjectFilter $projectFilter,
        private readonly ReflectionProvider $reflectionProvider,
        private readonly int $maxDepth = 4,
        private readonly int $fileBudget = 40,
    ) {}

    /**
     * Whether a class FQCN is a bare response type the refiner should try to enrich. The harvest calls
     * this to gate the (cheap) fast path against the (potentially descending) refinement.
     */
    public static function isResponseFqcn(string $fqcn): bool
    {
        return in_array($fqcn, self::RESPONSE_FQCNS, true);
    }

    /**
     * Refine a single return EXPRESSION (with the flow-refined scope at that return) into a recovered
     * response shape, or null when nothing better than the bare type is recoverable. Called at each
     * harvested return site whose translated type is a bare response class.
     */
    public function refine(Node\Expr $expr, Scope $scope): ?RefinedResponse
    {
        return $this->refineExpr($expr, $scope, [], 0);
    }

    /**
     * The files this refiner touched since the last drain — merged into the analysis's
     * `dependencyFiles` so editing any descended helper invalidates the route fragment. Draining resets
     * the per-analysis set (the per-callee memo, and its recorded file set, persist across the build).
     *
     * @return list<string>
     */
    public function takeFiles(): array
    {
        $files = array_keys($this->currentFiles);
        sort($files);
        $this->currentFiles = [];

        return $files;
    }

    /**
     * @param  list<string>  $paramNames  the current function's parameter names — a status expression
     *                                    that is one of these is a PASS-THROUGH the caller can bind.
     */
    private function refineExpr(Node\Expr $expr, Scope $scope, array $paramNames, int $depth): ?RefinedResponse
    {
        // 1. `new JsonResponse($body, $status, [headers])` — fold the constructor arguments directly.
        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            $class = $scope->resolveName($expr->class);
            if (self::isResponseFqcn($class)) {
                return $this->foldConstructor($expr, $scope, $paramNames);
            }
        }

        // 2. A response the type system already carries richly (`response()->json([...], 422)` via the
        //    bundled extension) — read the shape straight off the resolved generic.
        $type = $this->translator->translate($scope->getType($expr));
        if ($type instanceof ClassT && self::isResponseFqcn($type->fqcn) && $type->typeArgs !== []) {
            return $this->fromTypeArgs($type);
        }

        // 3. A call into project code whose declared return erased the shape — descend and substitute.
        if ($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\StaticCall) {
            if ($type instanceof ClassT && self::isResponseFqcn($type->fqcn) && $depth < $this->maxDepth) {
                $callee = $this->calleeResolver->resolve($expr, $scope);
                if ($callee !== null && $this->projectFilter->isProjectFile($callee->file) && $this->withinBudget($callee->file)) {
                    $child = $this->refineCallee($callee, $depth + 1);
                    if ($child === null || $child->delegates) {
                        return $child;
                    }

                    return $this->bindCall($child, $callee, $expr, $scope, $paramNames);
                }
            }

            return null; // vendor / unresolvable / over-budget — declined (never descend into vendor)
        }

        // 4. A `return null` / void arm — the renderer delegates this type to the framework.
        if ($type instanceof NullT || $type instanceof VoidT) {
            return RefinedResponse::delegation();
        }

        return null;
    }

    /**
     * Fold `new JsonResponse($body, $status, [headers])`: payload from arg 0, HTTP status from arg 1
     * (a literal, a pass-through parameter, or permissive), explicit content type from a `Content-Type`
     * header in arg 2. Symfony's constructor defaults the status to 200 when it is omitted.
     *
     * @param  list<string>  $paramNames
     */
    private function foldConstructor(Node\Expr\New_ $new, Scope $scope, array $paramNames): RefinedResponse
    {
        $args = $new->getArgs();

        $payload = null;
        $provenance = [];
        if (isset($args[0])) {
            $payload = $this->payloadOf($scope->getType($args[0]->value));
            $provenance = $this->payloadProvenance($args[0]->value, $scope, $paramNames);
        }

        [$status, $statusParam] = isset($args[1])
            ? $this->resolveStatus($args[1]->value, $scope, $paramNames)
            : [new LiteralT(200), null];

        $contentType = isset($args[2]) ? $this->contentTypeOf($args[2]->value, $scope) : null;

        // A body member whose value passes through the SAME parameter that drives the HTTP status
        // echoes the response status. Mark it now (call-independent) so a call site that folds the
        // status folds the member too, and an unfolded status still fills at documentation time.
        if ($statusParam !== null && $payload instanceof ArrayShapeT) {
            foreach ($provenance as $key => $param) {
                if ($param === $statusParam) {
                    $payload = $this->replaceFieldType($payload, $key, new StatusMarkerT);
                }
            }
        }

        return new RefinedResponse($payload, $status, $statusParam, $contentType, false, $provenance);
    }

    /**
     * The member→parameter provenance of a body array literal: each string-keyed member whose value is
     * one of the current function's parameters, so a call site can fold it to the argument it passed.
     *
     * @param  list<string>  $paramNames
     * @return array<string, string> member key → parameter name
     */
    private function payloadProvenance(Node\Expr $expr, Scope $scope, array $paramNames): array
    {
        if (! $expr instanceof Node\Expr\Array_) {
            return [];
        }

        $provenance = [];
        foreach ($expr->items as $item) {
            if ($item->key === null || ! $item->value instanceof Node\Expr\Variable || ! is_string($item->value->name)) {
                continue;
            }
            if (! in_array($item->value->name, $paramNames, true)) {
                continue;
            }
            $keys = $scope->getType($item->key)->getConstantStrings();
            if (count($keys) === 1) {
                $provenance[$keys[0]->getValue()] = $item->value->name;
            }
        }

        return $provenance;
    }

    /**
     * Build a {@see RefinedResponse} from an already-resolved `JsonResponse<payload, status, contentType>`
     * generic (the `response()->json()` extension emits the first two args; the refiner's own descent may
     * carry the third).
     */
    private function fromTypeArgs(ClassT $type): RefinedResponse
    {
        // Preserve the already-resolved payload faithfully — a void payload (`noContent()`) is a
        // meaningful "no body", not an unfolded one; only an UnknownT is genuinely absent.
        $payloadArg = $type->typeArgs[0] ?? null;
        $payload = $payloadArg instanceof UnknownT ? null : $payloadArg;
        $statusArg = $type->typeArgs[1] ?? null;
        $status = $statusArg instanceof LiteralT && is_int($statusArg->value) ? $statusArg : null;
        $ctArg = $type->typeArgs[2] ?? null;
        $contentType = $ctArg instanceof LiteralT && is_string($ctArg->value) ? $ctArg->value : null;

        return new RefinedResponse($payload, $status, null, $contentType);
    }

    /**
     * The call-independent shape of a project callee: analyse its return sites (bounded, memoised,
     * cycle-guarded) and fold the first documentable — or delegating — one. Vendor callees never reach
     * here (the caller gates on {@see ProjectFilter}).
     */
    private function refineCallee(Callee $callee, int $depth): ?RefinedResponse
    {
        $key = $callee->class.'::'.$callee->method;

        if (array_key_exists($key, $this->memo)) {
            foreach ($this->memo[$key]['files'] as $file) {
                $this->currentFiles[$file] = true;
            }

            return $this->memo[$key]['result'];
        }

        if (isset($this->inProgress[$key]) || $depth > $this->maxDepth || ! $this->withinBudget($callee->file)) {
            return null; // cycle / over-budget — declined, and deliberately NOT memoised
        }

        $this->inProgress[$key] = true;
        $filesBefore = $this->currentFiles;
        $result = $this->computeCalleeShape($callee, $depth);
        unset($this->inProgress[$key]);

        $delta = array_keys(array_diff_key($this->currentFiles, $filesBefore));
        sort($delta);
        $this->memo[$key] = ['result' => $result, 'files' => $delta];

        return $result;
    }

    private function computeCalleeShape(Callee $callee, int $depth): ?RefinedResponse
    {
        $this->currentFiles[$this->adapter->normalize($callee->file)] = true;

        $node = $this->fileAnalyzer->analyze($callee->file)[$callee->method] ?? null;
        if ($node === null) {
            return null;
        }

        $paramNames = $this->parameterNames($callee);

        $delegation = null;
        foreach ($node->getReturnStatements() as $statement) {
            $expr = $statement->getReturnNode()->expr;
            if ($expr === null) {
                $delegation ??= RefinedResponse::delegation();

                continue;
            }

            $refined = $this->refineExpr($expr, $statement->getScope(), $paramNames, $depth);
            if ($refined === null) {
                continue;
            }
            if ($refined->delegates) {
                $delegation ??= $refined;

                continue;
            }

            return $refined; // first documentable return wins (a helper's single response)
        }

        return $delegation;
    }

    /**
     * Re-express a callee's pass-through recovery in the CALLER's terms: fold the arguments the caller
     * passed into the callee's status parameter AND into any body members that pass a parameter through.
     * Payload binding runs first so a status member folds consistently with the HTTP status (both key on
     * the same argument), then the status value itself is bound.
     *
     * @param  list<string>  $paramNames  the caller's parameter names
     */
    private function bindCall(RefinedResponse $child, Callee $callee, Node\Expr $call, Scope $scope, array $paramNames): RefinedResponse
    {
        return $this->bindStatus(
            $this->bindPayload($child, $callee, $call, $scope, $paramNames),
            $callee,
            $call,
            $scope,
            $paramNames,
        );
    }

    /**
     * Bind the callee's pass-through status: a folded literal resolves it outright; a parameter of the
     * caller re-homes the pass-through one hop out; anything else leaves it permissive.
     *
     * @param  list<string>  $paramNames  the caller's parameter names
     */
    private function bindStatus(RefinedResponse $child, Callee $callee, Node\Expr $call, Scope $scope, array $paramNames): RefinedResponse
    {
        if ($child->statusParam === null) {
            return $child;
        }

        $argExpr = $this->argumentFor($callee, $child->statusParam, $call);
        if ($argExpr === null) {
            return $child->withStatusParam(null);
        }

        $literal = $this->intLiteralOf($argExpr, $scope);
        if ($literal !== null) {
            return $child->withBoundStatus(new LiteralT($literal));
        }

        if ($argExpr instanceof Node\Expr\Variable && is_string($argExpr->name) && in_array($argExpr->name, $paramNames, true)) {
            return $child->withStatusParam($argExpr->name);
        }

        return $child->withStatusParam(null);
    }

    /**
     * Fold the arguments the caller passed into the callee's body-member parameters: a constant-foldable
     * argument pins the member to that literal (a per-arm `type` URI becomes a `const`); a caller
     * parameter re-homes the provenance one hop out; anything else drops the provenance and leaves the
     * member at its widened type (a {@see StatusMarkerT} status member is likewise left for the response
     * seam to fill). Honest: a member is only ever pinned to a value that actually flows to it.
     *
     * @param  list<string>  $paramNames  the caller's parameter names
     */
    private function bindPayload(RefinedResponse $child, Callee $callee, Node\Expr $call, Scope $scope, array $paramNames): RefinedResponse
    {
        if ($child->payloadParamProvenance === [] || ! $child->payload instanceof ArrayShapeT) {
            return $child;
        }

        $payload = $child->payload;
        $rehomed = [];
        foreach ($child->payloadParamProvenance as $key => $param) {
            $argExpr = $this->argumentFor($callee, $param, $call);
            if ($argExpr === null) {
                continue;
            }

            $literal = $this->constLiteralOf($argExpr, $scope);
            if ($literal !== null) {
                $payload = $this->replaceFieldType($payload, $key, $literal);

                continue;
            }

            if ($argExpr instanceof Node\Expr\Variable && is_string($argExpr->name) && in_array($argExpr->name, $paramNames, true)) {
                $rehomed[$key] = $argExpr->name;
            }
        }

        return $child->withPayload($payload, $rehomed);
    }

    /**
     * Resolve a status expression to either a literal int, a pass-through parameter name, or neither.
     *
     * @param  list<string>  $paramNames
     * @return array{?LiteralT, ?string}
     */
    private function resolveStatus(Node\Expr $expr, Scope $scope, array $paramNames): array
    {
        $literal = $this->intLiteralOf($expr, $scope);
        if ($literal !== null) {
            return [new LiteralT($literal), null];
        }

        if ($expr instanceof Node\Expr\Variable && is_string($expr->name) && in_array($expr->name, $paramNames, true)) {
            return [null, $expr->name];
        }

        return [null, null];
    }

    private function intLiteralOf(Node\Expr $expr, Scope $scope): ?int
    {
        $type = $scope->getType($expr);
        if (! $type->isInteger()->yes()) {
            return null;
        }

        $values = $type->getConstantScalarValues();

        return count($values) === 1 && is_int($values[0]) ? $values[0] : null;
    }

    /**
     * A constant scalar argument (`'https://…'`, `409`, `true`) as a {@see LiteralT}, or null when the
     * argument does not constant-fold to a single scalar. Mirrors the translator's literal recovery so a
     * bound body member reads identically to a directly-written literal.
     */
    private function constLiteralOf(Node\Expr $expr, Scope $scope): ?LiteralT
    {
        $type = $scope->getType($expr);

        $strings = $type->getConstantStrings();
        if (count($strings) === 1) {
            return new LiteralT($strings[0]->getValue());
        }

        if ($type->isConstantScalarValue()->yes()) {
            $values = $type->getConstantScalarValues();
            if (count($values) === 1 && is_scalar($values[0])) {
                return new LiteralT($values[0]);
            }
        }

        return null;
    }

    /**
     * A copy of a keyed array shape with one member's value type replaced (its key and optionality
     * preserved), or the shape unchanged when the key is absent.
     */
    private function replaceFieldType(ArrayShapeT $shape, string $key, DType $type): ArrayShapeT
    {
        $fields = array_map(
            static fn (ArrayShapeField $field): ArrayShapeField => (string) $field->key === $key
                ? new ArrayShapeField($field->key, $type, $field->optional)
                : $field,
            $shape->fields,
        );

        return new ArrayShapeT($fields, $shape->isList);
    }

    /**
     * The `Content-Type` value from a headers array literal (`['Content-Type' => 'application/problem+json']`),
     * matched case-insensitively; null when absent or non-constant.
     */
    private function contentTypeOf(Node\Expr $expr, Scope $scope): ?string
    {
        if (! $expr instanceof Node\Expr\Array_) {
            return null;
        }

        foreach ($expr->items as $item) {
            if ($item->key === null) {
                continue;
            }
            $keys = $scope->getType($item->key)->getConstantStrings();
            if (count($keys) !== 1 || strcasecmp($keys[0]->getValue(), 'content-type') !== 0) {
                continue;
            }
            $values = $scope->getType($item->value)->getConstantStrings();
            if (count($values) === 1) {
                return $values[0]->getValue();
            }
        }

        return null;
    }

    /** The payload DType, or null when it is not a documentable body (void/never/unknown). */
    private function payloadOf(Type $type): ?DType
    {
        return $this->documentablePayload($this->translator->translate($type));
    }

    private function documentablePayload(?DType $payload): ?DType
    {
        if ($payload === null || $payload instanceof VoidT || $payload instanceof NeverT || $payload instanceof UnknownT) {
            return null;
        }

        return $payload;
    }

    /**
     * The argument expression the call passed to `$paramName` — a named argument if present, otherwise
     * the positional argument at that parameter's index.
     */
    private function argumentFor(Callee $callee, string $paramName, Node\Expr $call): ?Node\Expr
    {
        if (! $call instanceof Node\Expr\MethodCall && ! $call instanceof Node\Expr\StaticCall) {
            return null;
        }

        $params = $this->parameterNames($callee);
        $index = array_search($paramName, $params, true);

        $positional = [];
        foreach ($call->getArgs() as $arg) {
            if ($arg->name instanceof Node\Identifier) {
                if ($arg->name->toString() === $paramName) {
                    return $arg->value;
                }

                continue;
            }
            $positional[] = $arg->value;
        }

        return $index !== false && isset($positional[$index]) ? $positional[$index] : null;
    }

    /**
     * The callee's parameter names in declaration order (for positional-argument binding).
     *
     * @return list<string>
     */
    private function parameterNames(Callee $callee): array
    {
        if (! $this->reflectionProvider->hasClass($callee->class)) {
            return [];
        }
        $class = $this->reflectionProvider->getClass($callee->class);
        if (! $class->hasNativeMethod($callee->method)) {
            return [];
        }

        $names = [];
        foreach ($class->getNativeMethod($callee->method)->getVariants()[0]->getParameters() as $parameter) {
            $names[] = $parameter->getName();
        }

        return $names;
    }

    private function withinBudget(string $file): bool
    {
        return count($this->currentFiles) < $this->fileBudget
            || isset($this->currentFiles[$this->adapter->normalize($file)]);
    }
}
