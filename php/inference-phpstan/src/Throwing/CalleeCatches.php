<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Trace\CalleeResolver;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use Throwable;

/**
 * The catches a callee writes around each place it runs a callable it was handed, read off the callee's
 * source with {@see EnclosingCatches}' grammar. A closure's throws reach the caller only through one of those
 * places, so a class every one of them takes never does. Null wherever the places cannot all be named: the
 * parameter is read any other way than called — passed on, stored, captured — or never called at all.
 *
 * Only the application's own callees are read, and the vendor functions in {@see CATCHES_BY_CONTRACT}: a
 * package's catch may hand what it took to a method that rethrows it, which no source read sees.
 *
 * @internal
 */
final class CalleeCatches
{
    /**
     * Framework functions whose contract is to catch what the callable they run throws. Their body is still
     * read — the installed one — so what a catch takes is the version the application resolved.
     */
    public const CATCHES_BY_CONTRACT = ['rescue'];

    /** Reads of the callable that name no place it runs: by position, or every local at once. */
    private const OPAQUE_READS = ['func_get_args', 'func_get_arg', 'get_defined_vars', 'compact', 'extract'];

    /** @var array<string, list<Node\Stmt>> */
    private array $files = [];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly CalleeResolver $calleeResolver,
        private readonly ProjectFilter $appFilter,
    ) {}

    /**
     * The catch classes around each place the callee runs the argument at `$position`, one list per place,
     * and the file that was read for them.
     *
     * @return array{sites: list<list<string>>, file: string}|null
     */
    public function around(Node\Expr\CallLike $call, int $position, Scope $scope): ?array
    {
        $argument = $call->getArgs()[$position] ?? null;
        $declaration = $this->declaration($call, $scope);
        if ($argument === null || $argument->unpack || $declaration === null) {
            return null;
        }
        [$function, $file] = $declaration;

        $parameter = self::parameter($function, $argument, $position);
        if ($parameter === null) {
            return null;
        }

        $sites = self::sites($function->getStmts() ?? [], $parameter);

        return $sites === null ? null : ['sites' => $sites, 'file' => $file];
    }

    /**
     * The callee's declaration and the file it is written in, where this may read it.
     *
     * @return array{Node\FunctionLike, string}|null
     */
    private function declaration(Node\Expr\CallLike $call, Scope $scope): ?array
    {
        if ($call instanceof Node\Expr\FuncCall) {
            if (! $call->name instanceof Node\Name || ! $this->reflectionProvider->hasFunction($call->name, $scope)) {
                return null;
            }

            $function = $this->reflectionProvider->getFunction($call->name, $scope);
            $file = $function->getFileName();
            if ($file === null
                || (! $this->appFilter->isProjectFile($file) && ! in_array(strtolower($function->getName()), self::CATCHES_BY_CONTRACT, true))
            ) {
                return null;
            }

            $found = (new NodeFinder)->find($this->statements($file), static fn (Node $node): bool => $node instanceof Node\Stmt\Function_
                && strcasecmp($node->namespacedName?->toString() ?? '', $function->getName()) === 0);

            return count($found) === 1 && $found[0] instanceof Node\Stmt\Function_ ? [$found[0], $file] : null;
        }

        $callee = $this->calleeResolver->resolve($call, $scope);
        if ($callee === null || ! $this->appFilter->isProjectFile($callee->writtenIn())) {
            return null;
        }

        // A trait's body is written in the trait's file, under the trait's name rather than the using class's.
        $file = $callee->writtenIn();
        $found = [];
        foreach ((new NodeFinder)->findInstanceOf($this->statements($file), Node\Stmt\ClassLike::class) as $class) {
            if ($file === $callee->file && strcasecmp($class->namespacedName?->toString() ?? '', $callee->class) !== 0) {
                continue;
            }
            $method = $class->getMethod($callee->method);
            if ($method !== null) {
                $found[] = $method;
            }
        }

        return count($found) === 1 ? [$found[0], $file] : null;
    }

    /** The parameter an argument binds, where it is one plain variable a call could name. */
    public static function parameter(Node\FunctionLike $function, Node\Arg $argument, int $position): ?string
    {
        $parameters = $function->getParams();
        $bound = null;
        if ($argument->name !== null) {
            foreach ($parameters as $parameter) {
                if ($parameter->var instanceof Node\Expr\Variable && $parameter->var->name === $argument->name->toString()) {
                    $bound = $parameter;
                }
            }
        } else {
            $bound = $parameters[$position] ?? null;
        }

        if ($bound === null || $bound->variadic || $bound->byRef
            || ! $bound->var instanceof Node\Expr\Variable || ! is_string($bound->var->name)
        ) {
            return null;
        }

        return $bound->var->name;
    }

    /**
     * The catches around every `$parameter(…)`, or null where the parameter is read any other way, or
     * called nowhere.
     *
     * @param  array<Node\Stmt>  $statements
     * @return list<list<string>>|null
     */
    public static function sites(array $statements, string $parameter): ?array
    {
        $calls = [];
        $callees = [];
        $opaque = false;
        foreach ((new NodeFinder)->find($statements, static fn (Node $node): bool => $node instanceof Node\Expr\FuncCall || $node instanceof Node\Expr\Variable) as $node) {
            if ($node instanceof Node\Expr\FuncCall) {
                if ($node->name instanceof Node\Expr\Variable && $node->name->name === $parameter) {
                    $calls[] = $node;
                    $callees[spl_object_id($node->name)] = true;
                } elseif ($node->name instanceof Node\Name && in_array($node->name->toLowerString(), self::OPAQUE_READS, true)) {
                    $opaque = true;
                }

                continue;
            }

            if (! $node instanceof Node\Expr\Variable) {
                continue;
            }

            if (! is_string($node->name) || ($node->name === $parameter && ! isset($callees[spl_object_id($node)]))) {
                $opaque = true;
            }
        }

        if ($opaque || $calls === []) {
            return null;
        }

        $sites = [];
        foreach ($calls as $call) {
            $sites[] = array_map(
                static fn (Node\Name $name): string => $name->toString(),
                EnclosingCatches::around($statements, $call->getStartFilePos()),
            );
        }

        return $sites;
    }

    /** @return list<Node\Stmt> */
    private function statements(string $file): array
    {
        if (isset($this->files[$file])) {
            return $this->files[$file];
        }

        try {
            $code = is_file($file) ? file_get_contents($file) : false;
            $statements = $code === false ? null : (new ParserFactory)->createForHostVersion()->parse($code);
        } catch (Throwable) {
            $statements = null;
        }

        if ($statements === null) {
            return $this->files[$file] = [];
        }

        return $this->files[$file] = array_values(array_filter(
            (new NodeTraverser(new NameResolver))->traverse($statements),
            static fn (Node $node): bool => $node instanceof Node\Stmt,
        ));
    }
}
