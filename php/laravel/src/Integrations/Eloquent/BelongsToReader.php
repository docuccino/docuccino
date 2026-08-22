<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Laravel\Integrations\Support\ParsedClassFile;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Reads a model's `belongsTo` relations statically: reflection finds the candidate methods, the
 * declaring file's AST supplies the call's LITERAL arguments. The model is never instantiated. A
 * relation whose arguments aren't literals is omitted rather than guessed — a guessed foreign key
 * would type the wrong column.
 *
 * @phpstan-type BelongsToRelation array{relation: string, related: class-string, foreignKey: ?string, ownerKey: ?string}
 */
final class BelongsToReader
{
    /** @var array<string, list<BelongsToRelation>> */
    private array $memo = [];

    /** Laravel's `belongsTo()` parameters, in signature order — positional args map onto these names. */
    private const PARAMETERS = ['related', 'foreignKey', 'ownerKey', 'relation'];

    /**
     * Every statically readable `belongsTo` relation the model declares, `relation` being the name that
     * feeds Laravel's default-foreign-key computation (the literal 4th argument, else the method name).
     *
     * @return list<BelongsToRelation>
     */
    public function relations(string $model): array
    {
        return $this->memo[$model] ??= $this->read($model);
    }

    /**
     * @return list<BelongsToRelation>
     */
    private function read(string $model): array
    {
        if (! class_exists($model)) {
            return [];
        }

        try {
            $methods = (new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC);
        } catch (Throwable) {
            return [];
        }

        // Candidates grouped by declaring file so each file parses once; a relation method is public,
        // non-static and callable with no arguments, and framework methods have nothing to declare.
        $byFile = [];
        foreach ($methods as $method) {
            if ($method->isStatic()
                || $method->getNumberOfRequiredParameters() !== 0
                || str_starts_with($method->getDeclaringClass()->getName(), 'Illuminate\\')
            ) {
                continue;
            }

            $file = $method->getFileName();
            if ($file !== false) {
                $byFile[$file][] = $method->getName();
            }
        }

        $relations = [];
        foreach ($byFile as $file => $methods) {
            $nodes = ParsedClassFile::methods($file);
            foreach ($methods as $method) {
                $node = $nodes[$method] ?? null;
                $relation = $node === null ? null : self::fromMethod($method, $node);
                if ($relation !== null) {
                    $relations[] = $relation;
                }
            }
        }

        return $relations;
    }

    /**
     * The relation one method body declares, or null. Exactly one `$this->belongsTo(...)` call may
     * appear (a chained `->withDefault()` still contains exactly one), and every argument must be a
     * literal.
     *
     * @return BelongsToRelation|null
     */
    private static function fromMethod(string $method, ClassMethod $node): ?array
    {
        $calls = array_filter(
            (new NodeFinder)->findInstanceOf($node->stmts ?? [], MethodCall::class),
            static fn (MethodCall $call): bool => $call->var instanceof Node\Expr\Variable
                && $call->var->name === 'this'
                && $call->name instanceof Node\Identifier
                && $call->name->toString() === 'belongsTo'
                && ! $call->isFirstClassCallable(),
        );
        if (count($calls) !== 1) {
            return null;
        }

        $arguments = self::arguments(array_values($calls)[0]);
        if ($arguments === null) {
            return null;
        }

        $related = $arguments['related'] ?? null;
        if (! is_string($related) || ! class_exists($related) || ! EloquentModelReflector::isModel($related)) {
            return null;
        }

        return [
            'relation' => $arguments['relation'] ?? $method,
            'related' => $related,
            'foreignKey' => $arguments['foreignKey'] ?? null,
            'ownerKey' => $arguments['ownerKey'] ?? null,
        ];
    }

    /**
     * The call's arguments mapped onto {@see self::PARAMETERS} (positional and named both), each value a
     * literal: a `X::class`/string class name for `related`, a string or an explicit `null` for the
     * rest. Anything else — an unpack, an unknown name, a computed value — refuses the whole call.
     *
     * @return array<string, string|null>|null
     */
    private static function arguments(MethodCall $call): ?array
    {
        $arguments = [];
        foreach ($call->getArgs() as $index => $arg) {
            if ($arg->unpack) {
                return null;
            }

            $name = $arg->name?->toString() ?? (self::PARAMETERS[$index] ?? null);
            if ($name === null || ! in_array($name, self::PARAMETERS, true) || array_key_exists($name, $arguments)) {
                return null;
            }

            $value = $name === 'related' ? self::className($arg->value) : self::literal($arg->value);
            if ($value === false) {
                return null;
            }

            $arguments[$name] = $value;
        }

        return $arguments;
    }

    /** A `X::class` fetch (already NameResolver-qualified) or a string literal FQCN, else false. */
    private static function className(Node\Expr $expr): string|false
    {
        if ($expr instanceof Node\Expr\ClassConstFetch
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
            && $expr->name->toString() === 'class'
        ) {
            return $expr->class->toString();
        }

        return $expr instanceof Node\Scalar\String_ ? $expr->value : false;
    }

    /** A string literal, or an explicit `null` (the parameter's own default, spelled out), else false. */
    private static function literal(Node\Expr $expr): string|null|false
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        if ($expr instanceof Node\Expr\ConstFetch && strtolower($expr->name->toString()) === 'null') {
            return null;
        }

        return false;
    }
}
