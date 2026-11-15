<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Support;

use Docuccino\Core\Inference\ArgumentSlots;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Where a plain response the factory makes takes its body, status and headers: `make($content, $status,
 * $headers)` and `view($view, $data, $status, $headers)`, called on the factory or through a facade proxying
 * it ({@see FacadeCall}), or as the `response($content, $status, $headers)` helper, which is `make()`. Every
 * spelling reads through here, so a status is read the same whichever one an application wrote.
 *
 * @internal
 */
final class MadeResponseCall
{
    private const HELPER = 'response';

    /** Each method's parameters, in order: the slots a named argument lands in. */
    private const PARAMETERS = [
        'make' => ['content', 'status', 'headers'],
        'view' => ['view', 'data', 'status', 'headers'],
    ];

    /** The body is the view the call renders. */
    public const VIEW = 'view';

    /** The content is provably nothing — omitted, `''` or `null` — so there is no body. */
    public const EMPTY = 'empty';

    /** Anything else: Laravel sends a string as it is rather than as JSON, so its shape is not read here. */
    public const UNREAD = 'unread';

    /**
     * The call's slots, where its status and headers sit, and what its body is; null for any other call. The
     * helper with no arguments is the factory itself, not a response.
     *
     * @return array{body: self::VIEW|self::EMPTY|self::UNREAD, slots: ArgumentSlots, status: int, headers: int}|null
     */
    public static function of(Node\Expr $call, Scope $scope, ReflectionProvider $reflection): ?array
    {
        if ($call instanceof Node\Expr\StaticCall) {
            $call = FacadeCall::onRoot($call, $scope, $reflection);
        }
        if (! $call instanceof Node\Expr\FuncCall && ! $call instanceof Node\Expr\MethodCall) {
            return null;
        }

        $method = $call instanceof Node\Expr\FuncCall
            ? self::helper($call, $scope, $reflection)
            : ((new ObjectType(ResponseFactoryCall::CONTRACT))->isSuperTypeOf($scope->getType($call->var))->yes() ? self::method($call->name) : null);
        if ($method === null || $call->isFirstClassCallable()) {
            return null;
        }

        // Both signatures end `$status, array $headers`.
        $status = count(self::PARAMETERS[$method]) - 2;
        $slots = ArgumentSlots::of($call->getArgs(), self::PARAMETERS[$method]);

        return [
            'body' => match (true) {
                $method === 'view' => self::VIEW,
                self::emptyContent($slots, $scope) => self::EMPTY,
                default => self::UNREAD,
            },
            'slots' => $slots,
            'status' => $status,
            'headers' => $status + 1,
        ];
    }

    private static function emptyContent(ArgumentSlots $slots, Scope $scope): bool
    {
        $content = $slots->at(0);
        if ($content === null) {
            return $slots->knows(0);
        }

        return self::isEmpty($scope->getType($content));
    }

    /** Whether content of this type is provably nothing — `''` or `null`, whichever it is. */
    public static function isEmpty(Type $type): bool
    {
        return $type->isConstantScalarValue()->yes()
            && array_filter($type->getConstantScalarValues(), static fn (mixed $value): bool => $value !== '' && $value !== null) === [];
    }

    private static function helper(Node\Expr\FuncCall $call, Scope $scope, ReflectionProvider $reflection): ?string
    {
        if (! $call->name instanceof Node\Name || $call->isFirstClassCallable() || $call->getArgs() === []) {
            return null;
        }

        return $reflection->resolveFunctionName($call->name, $scope) === self::HELPER ? 'make' : null;
    }

    private static function method(Node\Identifier|Node\Expr $name): ?string
    {
        // PHP method names are case-insensitive.
        return $name instanceof Node\Identifier && isset(self::PARAMETERS[$name->toLowerString()]) ? $name->toLowerString() : null;
    }
}
