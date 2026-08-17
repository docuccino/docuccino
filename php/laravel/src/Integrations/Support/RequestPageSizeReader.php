<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\TypeScope;
use PhpParser\Node;
use ReflectionException;
use ReflectionMethod;

/**
 * Recovers the query key a paginated endpoint reads its PAGE SIZE from, by following the paginating
 * terminal's size argument back to a `$request->integer('per_page', …)` — through a local variable, and
 * into a helper on another class, which is where an application sharing one clamp across its list
 * endpoints puts it. A visitor drives it: {@see observe()} on every node, {@see terminal()} on every
 * paginating call, {@see recovered()} for the answer.
 *
 * **The size argument is the evidence, never the name.** Nothing here matches `per_page`, so an app whose
 * key is `limit` documents `limit`, and an app whose size is fixed at the call site
 * (`paginate(20)`, or the model's own `$perPage`) still documents nothing at all —
 * {@see PaginatorPageParameter} states that half of the rule in full.
 *
 * Two bounds, both stated because exceeding either recovers nothing rather than guessing:
 *
 * - **One variable hop.** `$perPage = clamp(…); … paginate($perPage);` is the shape apps write; a longer
 *   chain of variables is dataflow guesswork, not a reading.
 * - **Correlation is by SOURCE RANGE, one callee deep.** A visitor is never told which call site the body
 *   it is walking belongs to, so the size argument names a callee, reflection says which lines that
 *   callee spans, and a read recorded inside them is that callee's. Two DIFFERENT keys in range, or a
 *   variable assigned twice, recovers nothing: a wrong page-size key would send every generated client to
 *   a parameter the endpoint ignores, which is worse than sending them to none.
 *
 * @phpstan-type PageSizeSource array{read: RequestPageSizeKey|null, callee: string|null, file: string|null, var: string|null}
 */
final class RequestPageSizeReader
{
    /** A page size is only ever read off the request. */
    private const REQUEST = 'Illuminate\\Http\\Request';

    /**
     * Request accessors naming one query key in argument 0 with its fallback in argument 1. `integer()`
     * casts and the others do not, but a value reaching `paginate()` is a page size either way, so all
     * four document the same integer parameter.
     *
     * @var list<string>
     */
    private const ACCESSORS = ['integer', 'input', 'query', 'get'];

    /**
     * Terminals whose signature says WHERE the page size sits — Laravel's own three, all
     * `paginate($perPage, …)`. A custom terminal's own signature is unknown, so its arguments are never
     * read positionally; the vendor terminal it forwards to is reached by the trace anyway, and that one
     * is in here.
     *
     * @var list<string>
     */
    private const SIZE_TERMINALS = ['paginate', 'simplePaginate', 'cursorPaginate'];

    private const SIZE_POSITION = 0;

    private const SIZE_NAME = 'perPage';

    /** Clamp helpers written inline around a read. A clamp is not a constraint, so only the key travels. */
    private const CLAMPS = ['min', 'max', 'intval'];

    /** @var list<array{key: string, default: int|null, file: string, line: int}> */
    private array $reads = [];

    /**
     * Local assignments by `file|variable`, null once a second write retires one.
     *
     * @var array<string, list<PageSizeSource>|null>
     */
    private array $assignments = [];

    /**
     * Every page-size argument seen, from any terminal at any depth: an outer custom terminal hides the
     * vendor one's arguments from the FACTS, but both are walked, and only one of them will name a read.
     *
     * @var list<PageSizeSource>
     */
    private array $sizes = [];

    /** @var list<string> */
    private array $dependencyFiles = [];

    private bool $dirty = false;

    private ?RequestPageSizeKey $resolved = null;

    /** Records a request read or a local assignment. Safe to call on every node of every walked body. */
    public function observe(Node $node, TypeScope $scope): void
    {
        if ($node instanceof Node\Expr\MethodCall) {
            $read = $this->readAt($node, $scope);
            if ($read !== null) {
                $location = $scope->location($node);
                $this->reads[] = [
                    'key' => $read->key,
                    'default' => $read->default,
                    'file' => $location->file,
                    'line' => $location->line ?? 0,
                ];
                $this->dirty = true;
            }
        }

        if ($node instanceof Node\Expr\Assign
            && $node->var instanceof Node\Expr\Variable
            && is_string($node->var->name)
        ) {
            $key = $scope->location($node)->file.'|'.$node->var->name;
            // A variable written twice names no single origin, so the second write retires it.
            $this->assignments[$key] = array_key_exists($key, $this->assignments)
                ? null
                : $this->sourcesOf($node->expr, $scope);
            $this->dirty = true;
        }
    }

    /**
     * Records the page-size argument of a terminal whose signature names one. An argument that was never
     * written is the model's own `$perPage`, which reads no request key.
     */
    public function terminal(Node\Expr\MethodCall|Node\Expr\StaticCall $call, string $terminal, TypeScope $scope): void
    {
        if (! in_array($terminal, self::SIZE_TERMINALS, true) || $call->isFirstClassCallable()) {
            return;
        }

        $argument = null;
        foreach ($call->getArgs() as $index => $arg) {
            $named = $arg->name?->toString();
            if ($named === self::SIZE_NAME || ($named === null && $index === self::SIZE_POSITION)) {
                $argument = $arg->value;
            }
        }

        if ($argument === null) {
            return;
        }

        $this->sizes = [...$this->sizes, ...$this->sourcesOf($argument, $scope)];
        $this->dirty = true;
    }

    /**
     * The one page-size read every recorded size argument agrees on, or null when they name none or
     * several. Resolved on demand — a read written inside a callee is only seen once the engine has
     * descended into it, which happens after the call site was walked — and memoised until the next
     * observation, so a visitor may ask after every node.
     */
    public function recovered(): ?RequestPageSizeKey
    {
        if (! $this->dirty) {
            return $this->resolved;
        }
        $this->dirty = false;

        $found = [];
        foreach ($this->sizes as $source) {
            foreach ($this->resolve($source, 0) as $read) {
                // Keyed by name, so agreeing reads collapse however the walk ordered them. Two reads of
                // one key that DISAGREE on the fallback settle on no default rather than on whichever
                // arrived last — a default that depended on encounter order would not be a fact.
                $found[$read->key] = array_key_exists($read->key, $found) && $found[$read->key]->default !== $read->default
                    ? new RequestPageSizeKey($read->key)
                    : $read;
            }
        }

        return $this->resolved = count($found) === 1 ? reset($found) : null;
    }

    /**
     * The files a recovered fact was WRITTEN in — the helper's own, its parents' and its traits' — for
     * {@see RouteContext::recordDependencyFiles()}. The trace reports
     * every file it descended into, but a fact reached through reflection owes its own accounting.
     *
     * @return list<string>
     */
    public function dependencyFiles(): array
    {
        return array_values(array_unique($this->dependencyFiles));
    }

    /**
     * @param  PageSizeSource  $source
     * @return list<RequestPageSizeKey>
     */
    private function resolve(array $source, int $hops): array
    {
        if ($source['read'] !== null) {
            return [$source['read']];
        }

        if ($source['var'] !== null && $source['file'] !== null) {
            if ($hops > 0) {
                return []; // one variable hop; see the class docblock
            }

            $next = $this->assignments[$source['file'].'|'.$source['var']] ?? null;
            if ($next === null) {
                return [];
            }

            $found = [];
            foreach ($next as $inner) {
                $found = [...$found, ...$this->resolve($inner, $hops + 1)];
            }

            return $found;
        }

        return $source['callee'] === null ? [] : $this->readsIn($source['callee']);
    }

    /**
     * Where a value came from, as far as one expression can say: a read here and now, a local variable to
     * look up once the walk has seen its assignment, or the callee whose body to look inside. A list
     * because an inline clamp wraps the read in arguments that are values of their own.
     *
     * @return list<PageSizeSource>
     */
    private function sourcesOf(Node\Expr $expr, TypeScope $scope): array
    {
        if ($expr instanceof Node\Expr\MethodCall) {
            $read = $this->readAt($expr, $scope);
            if ($read !== null) {
                return [['read' => $read, 'callee' => null, 'file' => null, 'var' => null]];
            }
        }

        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return [['read' => null, 'callee' => null, 'file' => $scope->location($expr)->file, 'var' => $expr->name]];
        }

        if ($expr instanceof Node\Expr\FuncCall
            && $expr->name instanceof Node\Name
            && in_array(strtolower($expr->name->toString()), self::CLAMPS, true)
        ) {
            $sources = [];
            foreach ($expr->getArgs() as $arg) {
                $sources = [...$sources, ...$this->sourcesOf($arg->value, $scope)];
            }

            return $sources;
        }

        $callee = $this->calleeOf($expr, $scope);

        return $callee === null ? [] : [['read' => null, 'callee' => $callee, 'file' => null, 'var' => null]];
    }

    /**
     * The request reads written inside one callee's own source lines. Reflection answers where those lines
     * are, which is the only correlation available: the trace hands a visitor another file's nodes without
     * ever saying which call led there.
     *
     * @return list<RequestPageSizeKey>
     */
    private function readsIn(string $callee): array
    {
        $split = explode('::', $callee, 2);
        if (count($split) !== 2 || ! method_exists($split[0], $split[1])) {
            return [];
        }

        try {
            $reflection = new ReflectionMethod($split[0], $split[1]);
        } catch (ReflectionException) {
            return [];
        }

        $file = $reflection->getFileName();
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();
        if ($file === false || $start === false || $end === false) {
            return []; // an internal or evaluated method has no lines to correlate against
        }

        $this->dependencyFiles = [...$this->dependencyFiles, ...DeclarationFiles::of($split[0])];

        $found = [];
        foreach ($this->reads as $read) {
            if (self::samePath($read['file'], $file) && $read['line'] >= $start && $read['line'] <= $end) {
                $found[] = new RequestPageSizeKey($read['key'], $read['default']);
            }
        }

        return $found;
    }

    /**
     * A `$request-><accessor>('key', <default>)` read, when the receiver really is a request — the type
     * decides, never the variable's name.
     */
    private function readAt(Node\Expr\MethodCall $call, TypeScope $scope): ?RequestPageSizeKey
    {
        if (! $call->name instanceof Node\Identifier
            || ! in_array($call->name->toString(), self::ACCESSORS, true)
            || $call->isFirstClassCallable()
        ) {
            return null;
        }

        $args = $call->getArgs();
        // `query()` with no key returns the whole bag and names nothing.
        $key = isset($args[0]) ? $scope->constantValueOf($args[0]->value) : null;
        if ($key === null || ! $key->isScalar() || ! is_string($key->scalar) || $key->scalar === '') {
            return null;
        }

        if (! $this->receiverIsRequest($call->var, $scope)) {
            return null;
        }

        $fallback = isset($args[1]) ? $scope->constantValueOf($args[1]->value) : null;
        $default = $fallback !== null && $fallback->isScalar() && is_int($fallback->scalar) ? $fallback->scalar : null;

        return new RequestPageSizeKey($key->scalar, $default);
    }

    private function receiverIsRequest(Node\Expr $receiver, TypeScope $scope): bool
    {
        $type = $scope->typeOf($receiver);

        return $type instanceof ClassT && is_a($type->fqcn, self::REQUEST, true);
    }

    /** The `Class::method` a call dispatches to, as far as the scope can name it. */
    private function calleeOf(Node\Expr $expr, TypeScope $scope): ?string
    {
        if ($expr instanceof Node\Expr\StaticCall) {
            // A static call folds to a descriptor carrying its resolved `Class::method`.
            $value = $scope->constantValueOf($expr);
            $factory = $value !== null && $value->isDescriptor() ? (string) $value->factory : '';

            return str_contains($factory, '::') ? $factory : null;
        }

        if ($expr instanceof Node\Expr\MethodCall && $expr->name instanceof Node\Identifier) {
            $type = $scope->typeOf($expr->var);

            return $type instanceof ClassT ? $type->fqcn.'::'.$expr->name->toString() : null;
        }

        return null;
    }

    /** Two spellings of one file — the engine reports absolute paths, reflection resolves symlinks. */
    private static function samePath(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }

        return (realpath($left) ?: $left) === (realpath($right) ?: $right);
    }
}
