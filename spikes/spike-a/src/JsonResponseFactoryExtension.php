<?php

declare(strict_types=1);

namespace Docuccino\SpikeA;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Type;

/**
 * Sub-proof for Spike A pass-criterion (b).
 *
 * Out of the box `response()->json([...])` infers as a bare
 * `Illuminate\Http\JsonResponse` and the payload array shape is thrown away.
 * This extension fires on `ResponseFactory::json()` and, when the first
 * argument is a constant array, returns `JsonResponse<TPayload>` carrying that
 * exact `ConstantArrayType` (paired with the @template TPayload stub) so the
 * literal shape survives into the return type Docuccino harvests.
 *
 * It targets the *contract* because `response()` is typed as
 * `Illuminate\Contracts\Routing\ResponseFactory` at the call site.
 */
final class JsonResponseFactoryExtension implements DynamicMethodReturnTypeExtension
{
    public function getClass(): string
    {
        return \Illuminate\Contracts\Routing\ResponseFactory::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'json';
    }

    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        $args = $methodCall->getArgs();

        // No payload argument -> fall back to the declared return type.
        if (! isset($args[0])) {
            return null;
        }

        $payloadType = $scope->getType($args[0]->value);

        return new GenericObjectType(
            \Illuminate\Http\JsonResponse::class,
            [$payloadType],
        );
    }
}
