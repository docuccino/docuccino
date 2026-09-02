<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Trace;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use ReflectionException;
use ReflectionMethod;

/**
 * The one call-resolution service for both the {@see Tracer} and the throw analyzer, on PHPStan's
 * `ReflectionProvider` — two reflection stacks would classify the same call differently.
 *
 * `resolve()` returns null for every "vendor terminal, don't descend" case: a non-method call, an unresolved
 * receiver, a magic/forwarded call (`__call`, e.g. Spatie QB forwarding `paginate`), or a PHP-internal/stub
 * method with no file. That null is the boundary signal both callers act on; each then applies its own
 * {@see ProjectFilter} gate.
 *
 * @internal
 */
final class CalleeResolver
{
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    /** The throw registry keys on this whether or not the call resolves to a concrete method. */
    public function name(Node $node): ?string
    {
        if ($node instanceof Node\Expr\FuncCall) {
            return $node->name instanceof Node\Name ? $node->name->toString() : null;
        }

        if (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall)
            && $node->name instanceof Node\Identifier
        ) {
            return $node->name->toString();
        }

        return null;
    }

    /** Null for the vendor-terminal cases above; the first resolvable receiver candidate wins. */
    public function resolve(Node $node, Scope $scope): ?Callee
    {
        if ($node instanceof Node\Expr\MethodCall) {
            if (! $node->name instanceof Node\Identifier) {
                return null;
            }
            $method = $node->name->toString();
            $classNames = $scope->getType($node->var)->getObjectClassNames();
        } elseif ($node instanceof Node\Expr\StaticCall) {
            if (! $node->name instanceof Node\Identifier || ! $node->class instanceof Node\Name) {
                return null;
            }
            $method = $node->name->toString();
            $classNames = [$scope->resolveName($node->class)];
        } else {
            return null;
        }

        // `getObjectClassNames()` preserves member order, so "first resolvable wins" is deterministic
        // across runs even for a union receiver.
        foreach ($classNames as $class) {
            if (! $this->reflectionProvider->hasClass($class)) {
                continue;
            }
            $classReflection = $this->reflectionProvider->getClass($class);
            if (! $classReflection->hasMethod($method)) {
                continue;
            }
            $declaring = $classReflection->getMethod($method, $scope)->getDeclaringClass();
            $file = $declaring->getFileName();
            if ($file === null) {
                return null; // PHP-internal / stub-only ⇒ vendor terminal
            }

            return new Callee($declaring->getName(), $method, $file, self::writtenIn($declaring->getName(), $method));
        }

        return null; // magic / forwarded / unresolvable ⇒ vendor terminal
    }

    /**
     * Where the method's own body is written, which for a TRAIT's method is not the declaring class's file:
     * PHP reports the member as the using class's, and only `ReflectionMethod` names the file it was copied
     * from. Native reflection rather than the provider's, because that is the one stack that answers this
     * question — and null wherever it cannot, leaving {@see Callee::writtenIn()} on the declaring class's.
     */
    private static function writtenIn(string $class, string $method): ?string
    {
        try {
            $file = (new ReflectionMethod($class, $method))->getFileName();
        } catch (ReflectionException) {
            return null; // a class or method only the provider knows: a stub, a magic forward
        }

        return $file === false ? null : $file;
    }
}
