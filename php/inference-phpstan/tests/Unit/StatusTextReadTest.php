<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Inference\PhpStan\Analysis\StatusTextRead;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Echoes\OwnTableResponse;
use PhpParser\Node;

/*
 * A read of the status-text table the response is sent with, however the class holding it is named. A
 * class answering `$statusTexts` with a table of its own is a different table, and so is no read of it.
 */

/** `$class::$statusTexts[$response->getStatusCode()]`. */
function statusTextFetch(string $class, string $property = 'statusTexts'): Node\Expr\ArrayDimFetch
{
    return new Node\Expr\ArrayDimFetch(
        new Node\Expr\StaticPropertyFetch(new Node\Name($class), new Node\VarLikeIdentifier($property)),
        new Node\Expr\MethodCall(new Node\Expr\Variable('response'), new Node\Identifier('getStatusCode')),
    );
}

/** @return array{key: Node\Expr, fallback: ?LiteralT}|null */
function readStatusText(Node\Expr $expr): ?array
{
    return StatusTextRead::of(
        $expr,
        static fn (Node\Name $name): string => $name->toString(),
        static fn (Node\Expr $fallback): ?LiteralT => $fallback instanceof Node\Scalar\String_ ? new LiteralT($fallback->value) : null,
    );
}

it('recognises the table through every class that inherits it', function (string $class): void {
    $read = readStatusText(statusTextFetch($class));

    expect($read)->not->toBeNull()
        ->and($read['key'] ?? null)->toBeInstanceOf(Node\Expr\MethodCall::class)
        ->and($read['fallback'] ?? null)->toBeNull();
})->with([
    'Symfony\\Component\\HttpFoundation\\Response',
    'Symfony\\Component\\HttpFoundation\\JsonResponse',
    'Illuminate\\Http\\Response',
    'Illuminate\\Http\\JsonResponse',
]);

it('carries a literal `??` fallback, and nothing for one it cannot fold', function (Node\Expr $fallback, ?LiteralT $expected): void {
    $read = readStatusText(new Node\Expr\BinaryOp\Coalesce(statusTextFetch('Symfony\\Component\\HttpFoundation\\Response'), $fallback));

    expect($read)->not->toBeNull()
        ->and($read['fallback'] ?? null)->toEqual($expected);
})->with([
    'a literal' => [new Node\Scalar\String_('Error'), new LiteralT('Error')],
    'an expression' => [new Node\Expr\Variable('message'), null],
]);

it('reads nothing that is not that table', function (Node\Expr $expr): void {
    expect(readStatusText($expr))->toBeNull();
})->with(fn (): array => [
    'a table of its own' => [statusTextFetch(OwnTableResponse::class)],
    'another static property' => [statusTextFetch('Symfony\\Component\\HttpFoundation\\Response', 'formats')],
    'a class with no such table' => [statusTextFetch('stdClass')],
    'a class that is not there' => [statusTextFetch('App\\Missing\\Response')],
    'the whole table' => [new Node\Expr\StaticPropertyFetch(new Node\Name('Symfony\\Component\\HttpFoundation\\Response'), new Node\VarLikeIdentifier('statusTexts'))],
    'an append' => [new Node\Expr\ArrayDimFetch(new Node\Expr\StaticPropertyFetch(new Node\Name('Symfony\\Component\\HttpFoundation\\Response'), new Node\VarLikeIdentifier('statusTexts')))],
    'a fallback alone' => [new Node\Scalar\String_('Error')],
]);
