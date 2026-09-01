<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\ArgumentSlots;
use Docuccino\Core\Inference\Frame;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Support\Fqcn;
use Docuccino\Inference\PhpStan\Analysis\FileAnalyzer;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Trace\Callee;
use Docuccino\Inference\PhpStan\Trace\CalleeResolver;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\MethodReturnStatementsNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Type;

/**
 * The 3-layer exception-flow engine (docs/design/inference-embedding.md §6):
 *
 *   1. PHPStan throw points. Drop `!isExplicit()` ones — they're always bare `Throwable`. Do NOT filter on
 *      `canContainAnyThrowable`: nearly every point flags it, signal included.
 *   2. {@see KnownThrowers}, keyed on callee name — enriches explicit stubbed points with a status, and
 *      rescues still-implicit forwarders (static `findOrFail`) at `likely` confidence. Gated on the
 *      RESOLVED callee, so a name-keyed guess never overrules a body we can read ({@see applyRegistry}).
 *   3. Bounded descent (depth 3) into project callees with no `@throws`, cycle-guarded. The vendor-file
 *      gate, not depth, does the real containment.
 *
 * A status comes from {@see KnownThrowers} where a name-keyed entry has one, and otherwise from what an
 * `HttpException` subclass sets on itself ({@see HttpExceptionStatus}). Only when neither speaks is the
 * answer the 500 that means "not an HTTP error at all" — which is the fallback {@see ThrowSignal} reads to
 * tell an API error from vendor plumbing.
 *
 * Result identity is `(fqcn, httpStatusHint)` — two aborts (403/404) are two responses, so never dedupe by
 * FQCN alone. Vendor-declared 500-class exceptions are demoted to `internal`; dropped bare-`Throwable`
 * noise is discarded silently — how much of it there was says nothing about the API, and nothing the
 * author writes would change it.
 *
 * @internal
 */
final class ThrowAnalyzer
{
    /** @var array<string, true> */
    private array $visitedFiles = [];

    /** @var array<string, true> HttpException subclasses whose status did not fold, by FQCN */
    private array $unreadStatuses = [];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly ProjectFilter $projectFilter,
        private readonly FileAnalyzer $fileAnalyzer,
        private readonly KnownThrowers $knownThrowers,
        private readonly CalleeResolver $calleeResolver,
        private readonly HttpExceptionStatus $httpExceptionStatus,
        private readonly int $maxDepth = 3,
    ) {}

    /**
     * @return list<ThrownException>
     */
    public function analyze(MethodReturnStatementsNode $node, string $selfLabel): array
    {
        $this->visitedFiles = [];
        $this->unreadStatuses = [];

        $raw = $this->analyzeMethod($node, $selfLabel, 0, [], []);

        return $this->dedupe($raw);
    }

    /**
     * @return list<string>
     */
    public function visitedFiles(): array
    {
        return array_keys($this->visitedFiles);
    }

    /**
     * One notice per exception CLASS whose HTTP status this build could not read — not one per throw site,
     * which would say the same thing about the same class several times over. They ride the analysis, so
     * a warm build reports what a cold one did.
     *
     * @return list<Diagnostic>
     */
    public function diagnostics(): array
    {
        $classes = array_keys($this->unreadStatuses);
        sort($classes);

        return array_map(
            static fn (string $fqcn): Diagnostic => new Diagnostic(
                severity: Severity::Info,
                code: 'inference.http-exception-status-unread',
                message: sprintf(
                    '%s extends HttpException, but the status it sets could not be read; the error is documented without a status of its own.',
                    $fqcn,
                ),
                help: 'Pass a constant to `parent::__construct()` — a literal or a class constant both fold. Where the status is a constructor parameter with a default, make the constructor `private` and build the exception through static factories, so the default is the only value it can take. Where it is passed in at the `throw`, write it there as a constant.',
            ),
            $classes,
        );
    }

    /**
     * @param  list<string>  $visited
     * @param  list<Frame>  $priorChain
     * @return list<ThrownException>
     */
    private function analyzeMethod(
        MethodReturnStatementsNode $methodNode,
        string $selfLabel,
        int $depth,
        array $visited,
        array $priorChain,
    ): array {
        $results = [];

        foreach ($methodNode->getStatementResult()->getThrowPoints() as $throwPoint) {
            $node = $throwPoint->getNode();
            $type = $throwPoint->getType();
            $scope = $this->fileAnalyzer->stableScope($throwPoint->getScope());
            $explicit = $throwPoint->isExplicit();
            $calleeName = $this->calleeResolver->name($node);
            $callee = $this->calleeResolver->resolve($node, $scope);
            $frame = $this->frame($selfLabel, $scope, $node);

            // Layer 2: KnownThrowers registry, keyed on the callee name — for callees we cannot read.
            $registryResult = $this->applyRegistry($calleeName, $callee, $node, $scope, $type, $explicit, $priorChain, $frame);
            if ($registryResult !== null) {
                $results[] = $registryResult;

                continue;
            }

            // Layer 1: explicit concrete type (literal throw, @throws, stub).
            if ($explicit && ! $this->isBareThrowable($type)) {
                foreach ($this->applyExplicit($callee, $node, $scope, $type, $priorChain, $frame) as $result) {
                    $results[] = $result;
                }

                continue;
            }

            // Layer 3: implicit bare Throwable — descend, or drop it as noise.
            if (! $explicit) {
                foreach ($this->applyDescent($callee, $depth, $visited, $priorChain, $frame) ?? [] as $result) {
                    $results[] = $result;
                }
            }
        }

        return $results;
    }

    /**
     * @param  list<Frame>  $priorChain
     */
    private function applyRegistry(
        ?string $calleeName,
        ?Callee $callee,
        Node $node,
        Scope $scope,
        Type $type,
        bool $explicit,
        array $priorChain,
        Frame $frame,
    ): ?ThrownException {
        if ($calleeName === null) {
            return null;
        }

        $thrower = $this->knownThrowers->forFunction($calleeName);
        $status = null;
        if ($thrower !== null) {
            // A function thrower either folds its status from an argument (`abort($status)`) or carries a
            // fixed one — never assume arg 0 (`abort_if` puts it at arg 1).
            $status = $thrower->foldsStatusFromArgument()
                ? $this->foldStatusArg($node, $scope, $thrower->statusArgIndex)
                : $thrower->fixedStatus;
        } else {
            $thrower = $this->knownThrowers->forMethod($calleeName);
            if ($thrower !== null) {
                $status = $thrower->fixedStatus;
            }
        }

        if ($thrower === null || $this->readsCalleeBody($callee)) {
            return null;
        }

        // Certain when PHPStan corroborated the same concrete type; likely when we rescued a bare-Throwable.
        $corroborated = $explicit && in_array($thrower->exceptionFqcn, $type->getObjectClassNames(), true);

        return new ThrownException(
            $thrower->exceptionFqcn,
            $status,
            [...$priorChain, $frame],
            $corroborated ? ThrowConfidence::Certain : ThrowConfidence::Likely,
            ThrowDisposition::Signal,
        );
    }

    /**
     * Whether this build can read the callee's own body — the invariant that keeps layer 2 honest.
     *
     * The registry is keyed on a BARE METHOD NAME, so it may only ever speak for code we cannot read:
     * a framework method behind vendor, a trait's method (which resolves to the USING class's file and
     * isn't declared there), a magic forward, a stub. Where the callee IS a project method whose body
     * this build analyses, layers 1 and 3 read what it actually throws, and a name-keyed guess must
     * never overrule that: an application's own `validate()` throwing its own exception is that
     * exception, not a 422 `ValidationException`.
     *
     * The predicate is deliberately the same one descent uses ({@see applyDescent}) — a project file
     * whose harvest really holds the method — so nothing that layer 3 would have dropped silently
     * loses the registry's rescue. It is asked only after an entry matched, so a build pays for the
     * file analysis only where a call shares a framework method's name.
     */
    private function readsCalleeBody(?Callee $callee): bool
    {
        return $callee !== null
            && $this->projectFilter->isProjectFile($callee->file)
            && $this->fileAnalyzer->method($callee->file, $callee->class, $callee->method) !== null;
    }

    /**
     * @param  list<Frame>  $priorChain
     * @return list<ThrownException>
     */
    private function applyExplicit(
        ?Callee $callee,
        Node $node,
        Scope $scope,
        Type $type,
        array $priorChain,
        Frame $frame,
    ): array {
        // php-parser v5 models `throw` only as an expression.
        $isLiteral = $node instanceof Node\Expr\Throw_;

        // A declared exception documents intent only from project code; a vendor `@throws` is plumbing.
        $calleeIsProject = ! $isLiteral && $callee !== null
            && $this->projectFilter->isProjectFile($callee->file);

        $results = [];
        foreach ($this->concreteClasses($type) as $class) {
            $resolution = $this->statusForType($class, $node, $scope);
            $results[] = new ThrownException(
                $class,
                $resolution['status'],
                [...$priorChain, $frame],
                $isLiteral ? ThrowConfidence::Certain : ThrowConfidence::Declared,
                ThrowSignal::disposition($isLiteral, $calleeIsProject, $resolution['fellBack']),
            );
        }

        return $results;
    }

    /**
     * @param  list<string>  $visited
     * @param  list<Frame>  $priorChain
     * @return list<ThrownException>|null null when there is nothing to descend into
     */
    private function applyDescent(
        ?Callee $callee,
        int $depth,
        array $visited,
        array $priorChain,
        Frame $frame,
    ): ?array {
        // The vendor-file gate, not depth, does the containment: vendor is a terminal, never descended.
        if ($callee === null
            || ! $this->projectFilter->isProjectFile($callee->file)
            || $depth >= $this->maxDepth
        ) {
            return null;
        }

        $key = $callee->key();
        if (in_array($key, $visited, true)) {
            return []; // cycle guard — treated as descended (no drop)
        }

        $this->visitedFiles[$callee->file] = true;
        $childNode = $this->fileAnalyzer->method($callee->file, $callee->class, $callee->method);
        if ($childNode === null) {
            return [];
        }

        $childLabel = Fqcn::short($callee->class).'::'.$callee->method;

        return $this->analyzeMethod(
            $childNode,
            $childLabel,
            $depth + 1,
            [...$visited, $key],
            [...$priorChain, $frame],
        );
    }

    /**
     * @param  list<ThrownException>  $raw
     * @return list<ThrownException>
     */
    private function dedupe(array $raw): array
    {
        $byIdentity = [];
        foreach ($raw as $throw) {
            $key = $throw->identityKey();
            if (! isset($byIdentity[$key])
                || $throw->confidence->rank() > $byIdentity[$key]->confidence->rank()
            ) {
                $byIdentity[$key] = $throw;
            }
        }

        $list = array_values($byIdentity);
        usort($list, static function (ThrownException $a, ThrownException $b): int {
            return [$a->httpStatusHint ?? PHP_INT_MAX, $a->exceptionFqcn]
                <=> [$b->httpStatusHint ?? PHP_INT_MAX, $b->exceptionFqcn];
        });

        return $list;
    }

    private function frame(string $selfLabel, Scope $scope, Node $node): Frame
    {
        return new Frame($selfLabel, new SourceLocation($scope->getFile(), $node->getStartLine()));
    }

    private function foldStatusArg(Node $node, Scope $scope, ?int $argIndex): ?int
    {
        // A first-class callable holds a placeholder where its arguments go, and `getArgs()` only ASSERTS
        // that — with `zend.assertions=-1` the placeholder would reach the scope below as an expression.
        if ($argIndex === null || ! $node instanceof Node\Expr\CallLike || $node->isFirstClassCallable()) {
            return null;
        }

        // Slots, not written arguments: a status inside a spread nobody can read is unknown, and reading
        // the position it looks absent from would publish the exception's default for a status the code
        // states itself.
        $arg = ArgumentSlots::of($node->getArgs())->at($argIndex);
        if ($arg === null) {
            return null;
        }
        $argType = $scope->getType($arg);

        return $argType instanceof ConstantIntegerType ? $argType->getValue() : null;
    }

    /**
     * Concrete (non-`Throwable`/`Exception`) object class names on a type.
     *
     * @return list<string>
     */
    private function concreteClasses(Type $type): array
    {
        return array_values(array_filter(
            $type->getObjectClassNames(),
            static fn (string $name): bool => $name !== 'Throwable' && $name !== 'Exception',
        ));
    }

    private function isBareThrowable(Type $type): bool
    {
        return $this->concreteClasses($type) === [];
    }

    /**
     * The status a thrown type carries, and whether that answer is the FALLBACK rather than something the
     * code states. The two are separate because 500 is a real status a class may pin, and because "an HTTP
     * error whose status did not fold" is a third answer again — null, which is not the same claim as "no
     * HTTP status at all".
     *
     * @return array{status: int|null, fellBack: bool}
     */
    private function statusForType(string $fqcn, Node $node, Scope $scope): array
    {
        // KnownThrowers is the single source: exact FQCN wins, else a subclass inherits its parent's status.
        $exact = $this->knownThrowers->statusForExceptionFqcn($fqcn);
        if ($exact !== null) {
            return ['status' => $exact, 'fellBack' => false];
        }

        if ($this->reflectionProvider->hasClass($fqcn)) {
            $reflection = $this->reflectionProvider->getClass($fqcn);
            foreach ($this->knownThrowers->knownStatuses() as $known => $status) {
                if ($reflection->is($known)) {
                    return ['status' => $status, 'fellBack' => false];
                }
            }
        }

        if ($this->httpExceptionStatus->isHttpException($fqcn)) {
            return ['status' => $this->httpStatus($fqcn, $node, $scope), 'fellBack' => false];
        }

        return ['status' => 500, 'fellBack' => true]; // internal / unhandled
    }

    /**
     * What an `HttpException` subclass's status is here: the one the class pins on every instance, else the
     * one written at THIS `throw new X(...)` for a class that takes its status as an argument. Null when
     * neither reads, which earns the class one diagnostic ({@see diagnostics()}).
     */
    private function httpStatus(string $fqcn, Node $node, Scope $scope): ?int
    {
        // The class's own file now decides what this route publishes, so it joins the dependency set.
        foreach ($this->httpExceptionStatus->filesFor($fqcn) as $file) {
            $this->visitedFiles[$file] = true;
        }

        $construction = $this->construction($node, $fqcn, $scope);
        $status = $this->httpExceptionStatus->pinned($fqcn) ?? ($construction === null
            ? null
            : $this->foldStatusArg($construction, $scope, $this->httpExceptionStatus->statusParameter($fqcn)));

        if ($status === null) {
            $this->unreadStatuses[$fqcn] = true;
        }

        return $status;
    }

    /**
     * The `new X(...)` a literal `throw` builds, where X is the exception the status is wanted for. Only
     * there is an argument at the throw site the status of THIS throw — a call that merely declares the
     * exception says nothing about what it was constructed with.
     */
    private function construction(Node $node, string $fqcn, Scope $scope): ?Node\Expr\New_
    {
        if (! $node instanceof Node\Expr\Throw_) {
            return null;
        }

        $new = $node->expr;
        if (! $new instanceof Node\Expr\New_ || ! $new->class instanceof Node\Name) {
            return null;
        }

        return $scope->resolveName($new->class) === $fqcn ? $new : null;
    }
}
