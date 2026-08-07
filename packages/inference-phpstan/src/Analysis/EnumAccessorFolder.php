<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Closure;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use Throwable;

/**
 * Folds an accessor on a KNOWN enum case to a literal — the final hop of the inferred-error-examples
 * chain. When a call site binds a concrete case (`ProblemResponse::make(ProblemType::Forbidden, …)`) to a
 * callee parameter, the {@see ResponseShapeRefiner} asks this folder to resolve the accessors the callee
 * applied to that parameter:
 *
 *   - `->value` / `->name` fold from the case itself via reflection — WORKS FOR VENDOR ENUMS too, since no
 *     method body is analysed. `->value` needs a backed enum; `->name` is universal.
 *   - `->method()` (a no-arg accessor like `status()` / `title()`) folds only for a PROJECT enum, by
 *     analysing the method body with `$this` narrowed to the bound case: a `match ($this)` arm whose
 *     condition names the case folds to that arm's constant body, and a plain constant return folds
 *     outright. Anything else (a translation call, a computed expression, a vendor enum) is honestly
 *     permissive (null) — never guessed.
 *
 * BOUNDED + DETERMINISTIC: one method body, no interprocedural descent; memoised per
 * (enum-case, method) so ordering never affects results. CACHE-SOUND: the enum's file is reported through
 * the {@see $recordFile} sink so it lands in the analysis's `dependencyFiles` (editing the enum
 * invalidates the fragment), re-contributed on every memo hit.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final class EnumAccessorFolder
{
    /**
     * Per (enum-case, method) memo of a folded method accessor — call-independent, so a case+method
     * folded once is reused across every route that reaches it.
     *
     * @var array<string, LiteralT|null>
     */
    private array $methodMemo = [];

    /**
     * @param  Closure(string): void  $recordFile  sink that lands a descended file in the analysis's dependency set
     */
    public function __construct(
        private readonly FileAnalyzer $fileAnalyzer,
        private readonly ProjectFilter $projectFilter,
        private readonly Closure $recordFile,
    ) {}

    /**
     * The literal an accessor on `$enumFqcn::$caseName` folds to, or null when it does not fold (a
     * computed method body, a vendor enum method, a `->value` on a non-backed enum, or an identity read
     * of the case object itself — an enum object is not a documentable scalar).
     */
    public function fold(string $enumFqcn, string $caseName, ParamAccessor $accessor): ?LiteralT
    {
        return match ($accessor->kind) {
            AccessorKind::Value => $this->backingValue($enumFqcn, $caseName),
            AccessorKind::Name => new LiteralT($caseName),
            AccessorKind::Method => $accessor->method === null ? null : $this->foldMethod($enumFqcn, $caseName, $accessor->method),
            AccessorKind::Identity => null,
        };
    }

    /** The backed value of a case as a literal (reflection; vendor-safe), or null when not a backed enum. */
    private function backingValue(string $enumFqcn, string $caseName): ?LiteralT
    {
        if (! enum_exists($enumFqcn)) {
            return null;
        }

        try {
            $reflection = new ReflectionEnum($enumFqcn);
            if (! $reflection->isBacked() || ! $reflection->hasCase($caseName)) {
                return null;
            }
            $case = $reflection->getCase($caseName);
            if (! $case instanceof ReflectionEnumBackedCase) {
                return null;
            }

            return new LiteralT($case->getBackingValue());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Fold a no-arg accessor method on the bound case: analyse the (project-only) method body, select the
     * `match ($this)` arm for this case (or a plain constant return), and fold its constant body. Memoised
     * per (enum-case, method); the enum file lands in the dependency set on every path (miss and hit).
     */
    private function foldMethod(string $enumFqcn, string $caseName, string $method): ?LiteralT
    {
        $key = $enumFqcn.'::'.$caseName.'::'.$method;
        if (array_key_exists($key, $this->methodMemo)) {
            $this->recordDeclaringFile($enumFqcn, $method);

            return $this->methodMemo[$key];
        }

        return $this->methodMemo[$key] = $this->computeMethod($enumFqcn, $caseName, $method);
    }

    private function computeMethod(string $enumFqcn, string $caseName, string $method): ?LiteralT
    {
        $file = $this->declaringFile($enumFqcn, $method);
        if ($file === null || ! $this->projectFilter->isProjectFile($file)) {
            return null; // vendor / unresolved — never analyse a vendor enum's method body
        }

        ($this->recordFile)($file);

        $node = $this->fileAnalyzer->analyze($file)[$method] ?? null;
        if ($node === null) {
            return null;
        }

        foreach ($node->getReturnStatements() as $statement) {
            $expr = $statement->getReturnNode()->expr;
            if ($expr === null) {
                continue;
            }
            $scope = $statement->getScope();

            if ($expr instanceof Node\Expr\Match_) {
                $body = AccessorExtractor::matchArmBodyForCase(
                    $expr,
                    $enumFqcn,
                    $caseName,
                    static fn (Node\Name $name): string => $scope->resolveName($name),
                );

                return $body === null ? null : $this->constLiteral($body, $scope);
            }

            return $this->constLiteral($expr, $scope);
        }

        return null;
    }

    /** A single constant scalar expression as a literal, or null when it does not constant-fold. */
    private function constLiteral(Node\Expr $expr, Scope $scope): ?LiteralT
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

    private function recordDeclaringFile(string $enumFqcn, string $method): void
    {
        $file = $this->declaringFile($enumFqcn, $method);
        if ($file !== null && $this->projectFilter->isProjectFile($file)) {
            ($this->recordFile)($file);
        }
    }

    /** The file the enum method is declared in (native reflection), or null when unresolvable. */
    private function declaringFile(string $enumFqcn, string $method): ?string
    {
        if (! enum_exists($enumFqcn)) {
            return null;
        }

        try {
            $reflection = new ReflectionEnum($enumFqcn);
            if (! $reflection->hasMethod($method)) {
                return null;
            }
            $file = $reflection->getMethod($method)->getFileName();

            return $file === false ? null : $file;
        } catch (Throwable) {
            return null;
        }
    }
}
