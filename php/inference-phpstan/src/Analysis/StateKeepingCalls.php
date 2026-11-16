<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Inference\PhpStan\Support\ParsedFiles;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;
use Throwable;

/**
 * Whether a call leaves the object a variable holds as it was: nothing the call runs writes one of the
 * object's own properties. Read off the bodies the call reaches, so a reader taking arguments
 * (`$response->getData(true)`), or a helper handed the object that only reads it, keeps what was read off
 * the object before. A body this cannot read, a callee it cannot name, or the object kept or handed where it
 * cannot follow, may have changed it.
 *
 * @internal
 */
final class StateKeepingCalls
{
    /** How many calls below the first a body is still followed. */
    private const DEPTH = 4;

    /** @var array<string, array{bool, list<string>}> a whole answer by `class::method#variable@held`, with the files it was read out of */
    private array $memo = [];

    /** @var array<string, true> the bodies being read, which a call back into adds nothing to */
    private array $reading = [];

    /** @var list<string> the files the answer in flight has read, whatever it turns out to be */
    private array $read = [];

    public function __construct(private readonly ParsedFiles $files = new ParsedFiles) {}

    /**
     * For a variable a call is made on (`$argument` null) or hands on as `$argument`: whether the call keeps
     * the object it holds, and every file that answer was read out of — a "no" too, since an edit to what was
     * read can turn it into a "yes".
     *
     * @return array{bool, list<string>}
     */
    public function atCall(Node\Expr\CallLike $call, ?Node\Arg $argument, Scope $scope): array
    {
        $this->read = [];
        $kept = $this->keptAt($call, $argument, $scope);

        return [$kept, array_values(array_unique($this->read))];
    }

    private function keptAt(Node\Expr\CallLike $call, ?Node\Arg $argument, Scope $scope): bool
    {
        $variable = $argument === null ? ($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall ? $call->var : null) : $argument->value;
        $held = $variable === null || $call->isFirstClassCallable() ? [] : $scope->getType($variable)->getObjectClassNames();
        if ($held === []) {
            return false;
        }

        $callees = $argument === null ? [] : $this->scopedCallees($call, $scope);
        foreach ($held as $class) {
            $targets = $argument === null ? $this->receivedBy($call, $class) : $callees;
            if ($targets === null || $targets === []) {
                return false;
            }

            foreach ($targets as $method) {
                $parameter = $argument === null ? 'this' : self::handedTo($method, $call, $argument);
                if ($parameter === null || ! $this->keeps($method, $parameter, $class, 0)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Whether every mention of `$variable` in the method's body leaves the `$held` object it holds as it was. */
    private function keeps(ReflectionMethod $method, string $variable, string $held, int $depth): bool
    {
        $key = $method->class.'::'.$method->name.'#'.$variable.'@'.$held;
        // Only a whole answer is remembered: one read below the first call had less depth left to spend.
        if ($depth === 0 && isset($this->memo[$key])) {
            $this->read = [...$this->read, ...$this->memo[$key][1]];

            return $this->memo[$key][0];
        }
        if (isset($this->reading[$key])) {
            return true;
        }
        if ($depth > self::DEPTH) {
            return false;
        }

        $this->reading[$key] = true;
        $from = count($this->read);

        try {
            $kept = $this->body($method, $variable, $held, $depth);
        } finally {
            unset($this->reading[$key]);
        }

        if ($depth === 0) {
            $this->memo[$key] = [$kept, array_slice($this->read, $from)];
        }

        return $kept;
    }

    private function body(ReflectionMethod $method, string $variable, string $held, int $depth): bool
    {
        $file = $method->getFileName();
        $body = $this->files->body($method);
        if ($file === false || $body === null) {
            return false;
        }

        $this->read[] = $file;
        $finder = new NodeFinder;
        $declaring = $method->getDeclaringClass();
        $mentions = [];
        foreach ($finder->find($body, static fn (Node $node): bool => self::names($node, $variable)) as $mention) {
            $mentions[spl_object_id($mention)] = true;
        }

        $calls = [];
        foreach ($finder->find($body, static fn (Node $node): bool => true) as $node) {
            foreach (self::writeTargets($node) as $target) {
                if (self::names(self::root($target), $variable)) {
                    return false; // a property of the object, or the variable, written here
                }
            }

            $read = $node instanceof Node\Expr\Instanceof_ ? $node->expr : ($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch ? $node->var : null);
            if ($read !== null && self::names($read, $variable)) {
                unset($mentions[spl_object_id($read)]);
            }

            if ($node instanceof Node\Expr\CallLike && ! $node->isFirstClassCallable()) {
                $calls[] = $node; // a first-class callable may run later, anywhere: a mention in it stays unconsumed
            }
        }

        foreach ($calls as $call) {
            $followed = [];
            if (($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall) && self::names($call->var, $variable)) {
                foreach ($this->receivedBy($call, $held) ?? [] as $callee) {
                    $followed[] = [$callee, 'this'];
                    unset($mentions[spl_object_id($call->var)]);
                }
            } elseif ($variable === 'this' && $call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name && in_array($call->class->toLowerString(), ['self', 'static', 'parent'], true)) {
                // `self::m()` naming an instance method hands it `$this` without writing it.
                foreach ($this->rawCallees($call, $declaring, $held, $variable) ?? [] as $callee) {
                    if (! $callee->isStatic()) {
                        $followed[] = [$callee, 'this'];
                    }
                }
            }

            foreach ($call->getArgs() as $arg) {
                $root = self::root($arg->value);
                if ($arg->value === $root && self::names($root, $variable) && ! $arg->unpack) {
                    foreach ($this->rawCallees($call, $declaring, $held, $variable) ?? [] as $callee) {
                        $parameter = self::handedTo($callee, $call, $arg);
                        if ($parameter === null) {
                            return false;
                        }
                        $followed[] = [$callee, $parameter];
                        unset($mentions[spl_object_id($arg->value)]);
                    }
                } elseif ($arg->value !== $root && self::names($root, $variable) && $this->mayWriteThrough($call, $arg, $declaring, $held, $variable)) {
                    return false; // a property handed to a by-reference parameter, or to a callee this cannot name
                }
            }

            foreach ($followed as [$callee, $parameter]) {
                if (! $this->keeps($callee, $parameter, $held, $depth + 1)) {
                    return false;
                }
            }
        }

        // Returned, assigned, captured, compared, or handed to a callee this cannot name: kept somewhere this
        // does not follow.
        return $mentions === [];
    }

    /**
     * The method a call on the object runs, resolved on the class it holds.
     *
     * @return list<ReflectionMethod>|null
     */
    private function receivedBy(Node\Expr\CallLike $call, string $held): ?array
    {
        return $call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall ? $this->methods($call, [$held]) : null;
    }

    /**
     * The methods a call written in analysed code runs, by the types its scope gives.
     *
     * @return list<ReflectionMethod>|null
     */
    private function scopedCallees(Node\Expr\CallLike $call, Scope $scope): ?array
    {
        if ($call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name) {
            $classes = [$scope->resolveName($call->class)];
        } elseif ($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall) {
            $classes = $scope->getType($call->var)->getObjectClassNames();
        } else {
            return null;
        }

        return $this->methods($call, $classes);
    }

    /**
     * The methods a call written in a body read from source runs, where its receiver names its class:
     * `$this` (the object followed, or the body's own), or `self::`, `static::`, `parent::` and a class name.
     *
     * @param  ReflectionClass<object>  $declaring
     * @return list<ReflectionMethod>|null
     */
    private function rawCallees(Node\Expr\CallLike $call, ReflectionClass $declaring, string $held, string $variable): ?array
    {
        $own = $variable === 'this' ? $held : $declaring->getName();
        if (($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall) && self::names($call->var, 'this')) {
            return $this->methods($call, [$own]);
        }

        if (! $call instanceof Node\Expr\StaticCall || ! $call->class instanceof Node\Name) {
            return null;
        }

        $class = match ($call->class->toLowerString()) {
            'self' => $declaring->getName(),
            'static' => $own,
            'parent' => $declaring->getParentClass() === false ? null : $declaring->getParentClass()->getName(),
            default => $call->class->toString(),
        };

        return $class === null ? null : $this->methods($call, [$class]);
    }

    /**
     * @param  list<string>  $classes
     * @return list<ReflectionMethod>|null
     */
    private function methods(Node\Expr\CallLike $call, array $classes): ?array
    {
        $name = $call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall || $call instanceof Node\Expr\StaticCall ? $call->name : null;
        if (! $name instanceof Node\Identifier) {
            return null;
        }

        $methods = [];
        foreach ($classes as $class) {
            $method = $this->method($class, $name->toString());
            if ($method === null) {
                return null;
            }
            $methods[] = $method;
        }

        return $methods;
    }

    private function method(string $class, string $name): ?ReflectionMethod
    {
        if (! class_exists($class) && ! interface_exists($class)) {
            return null;
        }

        // Which declaration answers the name is the hierarchy's to say.
        $this->read = [...$this->read, ...DeclarationFiles::of($class)];

        try {
            return (new ReflectionClass($class))->getMethod($name);
        } catch (Throwable) {
            return null; // a method only `__call` answers
        }
    }

    /**
     * The name the callee reads an argument by. A variadic one holds it in an array, and a by-reference one can
     * rebind it, both of which the callee's body then shows as a mention that is no read.
     */
    private static function handedTo(ReflectionFunctionAbstract $callee, Node\Expr\CallLike $call, Node\Arg $arg): ?string
    {
        return self::parameter($callee, $call, $arg)?->getName();
    }

    /**
     * Whether a callee may write through the argument: a by-reference parameter, or a callee this cannot name.
     *
     * @param  ReflectionClass<object>  $declaring
     */
    private function mayWriteThrough(Node\Expr\CallLike $call, Node\Arg $arg, ReflectionClass $declaring, string $held, string $variable): bool
    {
        $callees = $call instanceof Node\Expr\FuncCall ? self::function($call) : $this->rawCallees($call, $declaring, $held, $variable);
        if ($callees === null || $callees === []) {
            return true;
        }

        foreach ($callees as $callee) {
            if (self::parameter($callee, $call, $arg)?->isPassedByReference() === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * A function a call names, read where the file's namespace declares it, else globally.
     *
     * @return list<ReflectionFunction>|null
     */
    private static function function(Node\Expr\FuncCall $call): ?array
    {
        if (! $call->name instanceof Node\Name) {
            return null;
        }

        $namespaced = $call->name->getAttribute('namespacedName');
        foreach ([$namespaced instanceof Node\Name ? $namespaced->toString() : null, $call->name->toString()] as $name) {
            if ($name !== null && function_exists($name)) {
                return [new ReflectionFunction($name)];
            }
        }

        return null;
    }

    /** The parameter an argument binds to; none past a spread, or for an argument no parameter takes. */
    private static function parameter(ReflectionFunctionAbstract $callee, Node\Expr\CallLike $call, Node\Arg $arg): ?ReflectionParameter
    {
        $parameters = $callee->getParameters();
        if ($arg->name instanceof Node\Identifier) {
            foreach ($parameters as $parameter) {
                if ($parameter->getName() === $arg->name->toString()) {
                    return $parameter;
                }
            }

            return null;
        }

        foreach ($call->getArgs() as $position => $written) {
            if ($written->unpack) {
                return null;
            }
            if ($written === $arg) {
                $last = $parameters === [] ? null : $parameters[count($parameters) - 1];

                return $parameters[$position] ?? ($last !== null && $last->isVariadic() ? $last : null);
            }
        }

        return null;
    }

    /**
     * What a node writes: an assignment's target (each element of a destructuring one), what a reference is
     * taken of, an increment's, an unset's, and a foreach's loop variables or the array it iterates by reference.
     *
     * @return list<Node\Expr>
     */
    private static function writeTargets(Node $node): array
    {
        $targets = match (true) {
            $node instanceof Node\Expr\AssignRef => [$node->var, $node->expr],
            $node instanceof Node\Expr\Assign, $node instanceof Node\Expr\AssignOp,
            $node instanceof Node\Expr\PreInc, $node instanceof Node\Expr\PostInc,
            $node instanceof Node\Expr\PreDec, $node instanceof Node\Expr\PostDec => [$node->var],
            $node instanceof Node\Stmt\Unset_ => $node->vars,
            $node instanceof Node\Stmt\Foreach_ => array_values(array_filter([$node->keyVar, $node->valueVar, $node->byRef ? $node->expr : null])),
            default => [],
        };

        $written = [];
        while ($targets !== []) {
            $target = array_shift($targets);
            if ($target instanceof Node\Expr\List_ || $target instanceof Node\Expr\Array_) {
                foreach ($target->items as $item) {
                    if ($item !== null) {
                        $targets[] = $item->value;
                    }
                }

                continue;
            }
            $written[] = $target;
        }

        return $written;
    }

    /** The variable a property or offset chain is read off, or the expression itself. */
    private static function root(Node\Expr $expr): Node\Expr
    {
        while ($expr instanceof Node\Expr\PropertyFetch || $expr instanceof Node\Expr\NullsafePropertyFetch || $expr instanceof Node\Expr\ArrayDimFetch) {
            $expr = $expr->var;
        }

        return $expr;
    }

    private static function names(Node $node, string $variable): bool
    {
        return $node instanceof Node\Expr\Variable && $node->name === $variable;
    }
}
