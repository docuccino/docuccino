<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Trace;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use ReflectionMethod;
use Throwable;

/**
 * Resolves a call node to the method it actually dispatches to (design §4 —
 * "resolve via reflection → file+method"). A magic/forwarded call (e.g. Spatie
 * QB forwards `paginate` to the Eloquent builder via `__call`) has no
 * `ReflectionMethod` → `resolve()` returns null, which is exactly the right
 * signal: those are vendor terminals matched by name, never descended (Spike B
 * trap #6). `ReflectionMethod` throwing IS the vendor-terminal boundary.
 */
final class CalleeResolver
{
    public static function resolve(Node $node, Scope $scope): ?Callee
    {
        if ($node instanceof Node\Expr\MethodCall) {
            if (! $node->name instanceof Node\Identifier) {
                return null;
            }
            $method = $node->name->toString();
            $classNames = $scope->getType($node->var)->getObjectClassNames();
            $class = count($classNames) === 1 ? $classNames[0] : null;
        } elseif ($node instanceof Node\Expr\StaticCall) {
            if (! $node->name instanceof Node\Identifier || ! $node->class instanceof Node\Name) {
                return null;
            }
            $method = $node->name->toString();
            $class = $scope->resolveName($node->class);
        } else {
            return null;
        }

        if ($class === null || ! class_exists($class)) {
            return null;
        }

        try {
            $reflection = new ReflectionMethod($class, $method);
        } catch (Throwable) {
            return null; // magic/forwarded — vendor terminal, don't descend
        }

        $file = $reflection->getFileName();
        if ($file === false) {
            return null; // internal / PHP-defined
        }

        return new Callee($reflection->getDeclaringClass()->getName(), $method, $file);
    }
}
