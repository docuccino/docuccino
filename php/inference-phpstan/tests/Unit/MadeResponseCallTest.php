<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Support\MadeResponseCall;
use Docuccino\Inference\PhpStan\Support\ResponseFactoryCall;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Facades\ProbeFactoryFacade;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Facades\ProbeLedger;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Facades\ProbeLedgerFacade;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\Scope;
use PHPStan\Node\Expr\TypeExpr;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Reflection\ReflectionProviderStaticAccessor;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;

/**
 * Where a plain response the factory makes takes its status and headers, and what its body is: the
 * `response()` helper is `make()`, so `response('', 410)` and `response()->make('', 410)` must read alike.
 * Laravel sends a string content as it is rather than as JSON, so only an empty one says anything about the
 * body — that there is none.
 *
 * @return array{string, string|null, string|null}|null body kind, status, headers
 */
function madeResponseCallOf(string $code): ?array
{
    $call = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code.';')[0]->expr;

    $scope = test()->createStub(Scope::class);
    $scope->method('getType')->willReturnCallback(static fn (Node\Expr $expr): Type => match (true) {
        // `response()` with no arguments, or an injected factory.
        $expr instanceof Node\Expr\FuncCall, $expr instanceof Node\Expr\Variable && $expr->name === 'factory' => new ObjectType(ResponseFactoryCall::CONTRACT),
        // The object a facade resolves: a factory where it implements the contract.
        $expr instanceof TypeExpr => $expr->getExprType() instanceof ObjectType && is_a($expr->getExprType()->getClassName(), ResponseFactoryCall::CONTRACT, true)
            ? new ObjectType(ResponseFactoryCall::CONTRACT)
            : $expr->getExprType(),
        $expr instanceof Node\Expr\Variable && $expr->name === 'empty' => new UnionType([new ConstantStringType(''), new NullType]),
        $expr instanceof Node\Expr\Variable && $expr->name === 'body' => new StringType,
        $expr instanceof Node\Scalar\String_ => new ConstantStringType($expr->value),
        $expr instanceof Node\Expr\ConstFetch && $expr->name->toLowerString() === 'null' => new NullType,
        default => new MixedType,
    });
    $scope->method('resolveName')->willReturnCallback(static fn (Node\Name $name): string => $name->toString());

    // A namespaced `response()` an application declared itself is not the framework's helper.
    $reflection = test()->createStub(ReflectionProvider::class);
    $reflection->method('resolveFunctionName')->willReturnCallback(static fn (Node\Name $name): string => $name->toLowerString() === 'app\\response' ? 'app\\response' : $name->toLowerString());
    $reflection->method('hasClass')->willReturnCallback(static fn (string $class): bool => class_exists($class));

    // Comparing object types asks PHPStan's static reflection, which only a booted analyser registers; one
    // reading no class leaves any two distinct classes unrelated.
    $accessor = new ReflectionProperty(ReflectionProviderStaticAccessor::class, 'instance');
    $previous = $accessor->getValue();
    ReflectionProviderStaticAccessor::registerInstance(test()->createStub(ReflectionProvider::class));
    try {
        $made = MadeResponseCall::of($call, $scope, $reflection);
    } finally {
        $accessor->setValue(null, $previous);
    }

    $print = static fn (?Node\Expr $expr): ?string => $expr === null ? null : (new Standard)->prettyPrintExpr($expr);

    return $made === null ? null : [$made['body'], $print($made['slots']->at($made['status'])), $print($made['slots']->at($made['headers']))];
}

it('reads the status and headers wherever a plain response is made', function (string $code, ?array $expected): void {
    expect(madeResponseCallOf($code))->toBe($expected);
})->with([
    'the helper' => ['response($body, 410, $headers)', [MadeResponseCall::UNREAD, '410', '$headers']],
    'the helper, status named' => ['response(status: 410)', [MadeResponseCall::EMPTY, '410', null]],
    'make() on response()' => ['response()->make(\'\', 410)', [MadeResponseCall::EMPTY, '410', null]],
    'make() on an injected factory' => ['$factory->make($body, $code)', [MadeResponseCall::UNREAD, '$code', null]],
    'view() takes its status third' => ['response()->view(\'archived\', [], 410, $headers)', [MadeResponseCall::VIEW, '410', '$headers']],
    'any casing' => ['response()->MAKE(\'\', 410)', [MadeResponseCall::EMPTY, '410', null]],
    // The helper with no arguments hands back the factory, not a response.
    'the helper alone' => ['response()', null],
    'another factory method' => ['response()->download($path)', null],
    'make() on something else' => ['$other->make(\'\', 410)', null],
    'an application\'s own response()' => ['App\\response(\'\', 410)', null],
    'a first-class callable' => ['response()->make(...)', null],
    'the helper as a first-class callable' => ['response(...)', null],
    'a dynamic method' => ['response()->{$method}(\'\', 410)', null],
    // A facade hands the call to the factory it resolves.
    'make() through a facade' => ['\\'.ProbeFactoryFacade::class.'::make(\'\', 410)', [MadeResponseCall::EMPTY, '410', null]],
    'view() through a facade' => ['\\'.ProbeFactoryFacade::class.'::view(\'archived\', [], 410, $headers)', [MadeResponseCall::VIEW, '410', '$headers']],
    'make() through a facade proxying something else' => ['\\'.ProbeLedgerFacade::class.'::make(\'\', 410)', null],
    'make() on a class that is no facade' => ['\\'.ProbeLedger::class.'::make(\'\', 410)', null],
]);

it('reads no body only where the content is provably nothing', function (string $code, string $body): void {
    expect(madeResponseCallOf($code)[0] ?? null)->toBe($body);
})->with([
    'omitted' => ['response(status: 410)', MadeResponseCall::EMPTY],
    'an empty string' => ['response(\'\', 410)', MadeResponseCall::EMPTY],
    'null' => ['response(null, 410)', MadeResponseCall::EMPTY],
    'either of them' => ['response($empty, 410)', MadeResponseCall::EMPTY],
    'a string' => ['response(\'archived\', 410)', MadeResponseCall::UNREAD],
    'a string not known' => ['response($body, 410)', MadeResponseCall::UNREAD],
    // A spread may carry the content: nothing is known about it.
    'a spread nobody can read' => ['response(...$args)', MadeResponseCall::UNREAD],
]);
