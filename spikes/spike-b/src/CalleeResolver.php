<?php

declare(strict_types=1);

namespace Docuccino\SpikeB;

use PhpParser\Node;
use ReflectionMethod;
use Throwable;

/**
 * Resolves a call node to the method it actually dispatches to, using reflection
 * (the plan's "resolve via reflection/ClassReflection → file+method"). Shared by
 * the visitor (which uses it to answer "descend?") and the Tracer (which uses it
 * to know *what* file+method to descend into).
 *
 * Magic/forwarded calls (e.g. Spatie QB forwards `paginate` to the Eloquent
 * builder via __call) have no ReflectionMethod → resolve() returns null, which
 * is exactly right: those are vendor terminals matched by NAME, never descended.
 */
final class Callee
{
    public function __construct(
        public readonly string $class,
        public readonly string $method,
        public readonly string $file,
        public readonly bool $isVendor,
    ) {}
}

final class CalleeResolver
{
    public static function resolve(Node $node, TypeScope $scope): ?Callee
    {
        if ($node instanceof Node\Expr\MethodCall) {
            if (! $node->name instanceof Node\Identifier) {
                return null;
            }
            $method = $node->name->toString();
            $class = $scope->objectClassOf($node->var);
        } elseif ($node instanceof Node\Expr\StaticCall) {
            if (! $node->name instanceof Node\Identifier || ! $node->class instanceof Node\Name) {
                return null;
            }
            $method = $node->name->toString();
            $class = $node->class->toString();
        } else {
            return null;
        }

        if ($class === null || ! class_exists($class)) {
            return null;
        }

        try {
            $rm = new ReflectionMethod($class, $method);
        } catch (Throwable) {
            return null; // magic/forwarded (e.g. QB::paginate) — vendor terminal, don't descend
        }

        $file = $rm->getFileName();
        if ($file === false) {
            return null; // internal/PHP-defined
        }

        return new Callee(
            class: $rm->getDeclaringClass()->getName(),
            method: $method,
            file: $file,
            isVendor: str_contains($file, '/vendor/'),
        );
    }
}
