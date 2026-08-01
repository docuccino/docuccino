<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Extensions;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Type;

/**
 * Preserves the payload shape of `response()->json([...])` (design §7, proven in
 * Spike A). Out of the box the call infers as a bare `JsonResponse` and the
 * constant array shape is discarded; this extension re-attaches it as
 * `JsonResponse<TPayload>` (paired with the bundled `JsonResponse.stub`).
 *
 * It targets the ResponseFactory *contract*, not the concrete class, because
 * `response()` is typed as the contract at the call site — target the concrete
 * class and the extension silently never fires (Spike A observation).
 *
 * Laravel classes are referenced by FQCN string rather than imported: this
 * package carries no hard dependency on illuminate/*, and the extension only
 * ever executes inside a booted host app where those classes exist.
 */
final class ResponseJsonReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    private const RESPONSE_FACTORY_CONTRACT = 'Illuminate\\Contracts\\Routing\\ResponseFactory';

    private const JSON_RESPONSE = 'Illuminate\\Http\\JsonResponse';

    public function getClass(): string
    {
        // The contract is referenced by FQCN string (illuminate/* is not a root
        // dependency — see the package's static-analysis note), so the literal is
        // not provably class-string during analysis. It resolves at runtime inside
        // the host app, where the class exists.
        /** @phpstan-ignore return.type */
        return self::RESPONSE_FACTORY_CONTRACT;
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

        // No payload argument → fall back to the declared return type.
        if (! isset($args[0])) {
            return null;
        }

        $payloadType = $scope->getType($args[0]->value);

        return new GenericObjectType(self::JSON_RESPONSE, [$payloadType]);
    }
}
