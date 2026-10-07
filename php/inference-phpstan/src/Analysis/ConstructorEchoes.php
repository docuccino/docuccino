<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Closure;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\MethodDeclaration;
use Docuccino\Inference\PhpStan\Metadata\ReachedStatements;
use JsonSerializable;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;
use ReflectionProperty;
use Throwable;

/**
 * Which members of an object body are read off its constructor's parameters, the object-payload half of
 * the member provenance an array body carries inline: a property the constructor assigns `$param->x()` (or
 * `$param` itself), or the status-text table read at one (`Response::$statusTexts[$this->status] ?? 'Error'`).
 * Accessors come back in the constructor's own parameter names, for the construction site to re-home.
 *
 * Only what every instance holds is claimed: a public readonly property — so the first write is the value,
 * and the key is in the JSON — written by a top-level statement every completing path of the constructor
 * that runs reaches ({@see ReachedStatements}), on a class whose JSON is its public properties. A key read
 * through `$this->other` reads that property's own first write the same way, since a read before it throws.
 *
 * @internal
 */
final class ConstructorEchoes
{
    /** Interfaces through which a response serialises an object as something other than its public properties. */
    private const OWN_JSON = [
        JsonSerializable::class,
        'Illuminate\\Contracts\\Support\\Jsonable',
        'Illuminate\\Contracts\\Support\\Arrayable',
    ];

    /** @var array<string, list<Node\Stmt>> file → its name-resolved statements */
    private array $files = [];

    /**
     * @param  Closure(string): void  $touch  records a file the answer was read out of, each time it is read
     */
    public function __construct(private readonly Closure $touch) {}

    /**
     * Property → the parameter accessor it echoes, and for a table read the `??` fallback, in assignment
     * order. Empty where the class says nothing this can stand behind.
     *
     * @return array<string, array{accessor: ParamAccessor, text: bool, fallback: ?LiteralT}>
     */
    public function of(string $fqcn): array
    {
        if (! class_exists($fqcn)) {
            return [];
        }

        $class = new ReflectionClass($fqcn);
        foreach (self::OWN_JSON as $interface) {
            if (is_a($fqcn, $interface, true)) {
                return [];
            }
        }

        $constructor = $class->getConstructor();
        $file = $constructor?->getFileName();
        if ($constructor === null || $file === null || $file === false) {
            return [];
        }

        // The answer rests on the constructor's body and on what the hierarchy declares of each property.
        foreach ([...DeclarationFiles::of($fqcn), $file] as $read) {
            ($this->touch)($read);
        }
        $method = MethodDeclaration::in($this->statements($file), $constructor);
        if ($method === null) {
            return [];
        }

        $parameters = array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $constructor->getParameters());
        $declaring = $constructor->getDeclaringClass()->getName();
        $resolveName = static fn (Node\Name $name): string => match ($name->toLowerString()) {
            'self' => $declaring,
            'static' => $fqcn,
            'parent' => (string) get_parent_class($declaring),
            default => $name->toString(),
        };

        $first = [];
        foreach (ReachedStatements::of($method->stmts ?? []) as $statement) {
            $written = self::propertyWrite($statement);
            if ($written !== null && ! array_key_exists($written[0], $first)) {
                $first[$written[0]] = $written[1];
            }
        }

        $echoes = [];
        foreach ($first as $name => $expr) {
            if (! self::fixedOnceWritten($class, $name)) {
                continue;
            }

            $read = StatusTextRead::of($expr, $resolveName, self::literal(...));
            $accessor = self::accessor($read === null ? $expr : $read['key'], $parameters, $first, $class);
            if ($accessor !== null) {
                $echoes[$name] = ['accessor' => $accessor, 'text' => $read !== null, 'fallback' => $read['fallback'] ?? null];
            }
        }

        return $echoes;
    }

    /**
     * A parameter accessor, read through `$this->other` where that property's first write is one.
     *
     * @param  list<string>  $parameters
     * @param  array<string, Node\Expr>  $first
     * @param  ReflectionClass<object>  $class
     */
    private static function accessor(Node\Expr $expr, array $parameters, array $first, ReflectionClass $class): ?ParamAccessor
    {
        $property = self::thisProperty($expr);
        if ($property !== null) {
            return isset($first[$property]) && self::readonly($class, $property)
                ? AccessorExtractor::fromExpr($first[$property], $parameters)
                : null;
        }

        return AccessorExtractor::fromExpr($expr, $parameters);
    }

    /**
     * `[name, value]` where the statement is `$this->name = value;`.
     *
     * @return array{string, Node\Expr}|null
     */
    private static function propertyWrite(Node\Stmt $statement): ?array
    {
        if (! $statement instanceof Node\Stmt\Expression || ! $statement->expr instanceof Node\Expr\Assign) {
            return null;
        }

        $name = self::thisProperty($statement->expr->var);

        return $name === null ? null : [$name, $statement->expr->expr];
    }

    private static function thisProperty(Node\Expr $expr): ?string
    {
        return $expr instanceof Node\Expr\PropertyFetch
            && $expr->var instanceof Node\Expr\Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Node\Identifier
            ? $expr->name->toString()
            : null;
    }

    /**
     * A member of the JSON whose first write is its value: public, per instance, readonly, and not a
     * promoted parameter, which the argument initialised before the body ran.
     *
     * @param  ReflectionClass<object>  $class
     */
    private static function fixedOnceWritten(ReflectionClass $class, string $name): bool
    {
        $property = self::property($class, $name);

        return $property !== null && $property->isPublic() && ! $property->isStatic() && $property->isReadOnly() && ! $property->isPromoted();
    }

    /** @param  ReflectionClass<object>  $class */
    private static function readonly(ReflectionClass $class, string $name): bool
    {
        return self::property($class, $name)?->isReadOnly() === true;
    }

    /** @param  ReflectionClass<object>  $class */
    private static function property(ReflectionClass $class, string $name): ?ReflectionProperty
    {
        try {
            return $class->getProperty($name);
        } catch (ReflectionException) {
            return null;
        }
    }

    /** A literal written as one; anything else is not a fallback this can name. */
    private static function literal(Node\Expr $expr): ?LiteralT
    {
        return $expr instanceof Node\Scalar\String_ || $expr instanceof Node\Scalar\Int_ ? new LiteralT($expr->value) : null;
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
