<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Attributes\ErrorComponent;
use Docuccino\Core\Inference\ComponentDeclaration;
use Docuccino\Core\Inference\SourceLocation;
use PHPStan\Reflection\ReflectionProvider;
use ReflectionMethod;
use Throwable;

/**
 * Reads the `#[ErrorComponent]` a method declares for the body it answers with.
 *
 * The attribute is never instantiated: an argument list is all this needs, and reflecting an app class
 * must not depend on an attribute class being loadable in the analysed process. PHP resolves an
 * unoverridden method to the parent that declared it, so inheritance is free here — unlike the class
 * anchor, which has to walk. Total, like everything the engine exposes: an unreflectable class or a
 * malformed argument is no declaration.
 *
 * @internal
 */
final readonly class ComponentDeclarations
{
    public function __construct(
        private ReflectionProvider $reflectionProvider,
    ) {}

    public function on(string $class, string $method): ?ComponentDeclaration
    {
        try {
            if (! $this->reflectionProvider->hasClass($class)) {
                return null;
            }

            $native = $this->reflectionProvider->getClass($class)->getNativeReflection();

            return $native->hasMethod($method) ? self::onMethod($native->getMethod($method)) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** The declaration a reflected method carries, reported against the class that really declares it. */
    public static function onMethod(ReflectionMethod $method): ?ComponentDeclaration
    {
        foreach ($method->getAttributes() as $attribute) {
            if ($attribute->getName() !== ErrorComponent::class) {
                continue;
            }

            $arguments = $attribute->getArguments();
            $name = $arguments[0] ?? $arguments['name'] ?? null;
            if (! is_string($name)) {
                continue;
            }

            $file = $method->getFileName();
            $line = $method->getStartLine();

            return new ComponentDeclaration(
                $name,
                $method->getDeclaringClass()->getName().'::'.$method->getName(),
                new SourceLocation($file === false ? '' : $file, $line === false ? null : $line),
            );
        }

        return null;
    }
}
