<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\ArchiveArticleAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\ExplicitMethodAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\PublishArticleAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\SimpleAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\WithAttributesAction;
use Illuminate\Routing\Router;

/**
 * The laravel-actions integration end-to-end (Phase 5c). The route reflector's method remap is proven
 * with REAL reflection of real action fixtures (an invokable action → its handle()/asController());
 * the resolved method's body analysis is scripted by the stub engine (the engine's real method
 * analysis is already proven by the fixture-group suite — this integration only redirects WHICH
 * method it targets, which is reflection, not engine, work). Request body from rules() and the 403
 * from authorize() are exercised against the real container-registered extension set.
 */
function actionEngine(): StubTypeEngine
{
    $loc = new SourceLocation('');
    $literal = static fn (array $fields): ActionAnalysis => new ActionAnalysis(
        returns: [new ReturnSite(new ArrayShapeT($fields), $loc)],
    );

    return new StubTypeEngine(analyses: [
        PublishArticleAction::class.'::rules' => $literal([
            new ArrayShapeField('title', new LiteralT('required|string|max:100')),
            new ArrayShapeField('body', new LiteralT('required|string')),
        ]),
        PublishArticleAction::class.'::handle' => new ActionAnalysis(
            returns: [new ReturnSite(new ArrayShapeT([new ArrayShapeField('id', ScalarT::int())]), $loc)],
        ),
    ]);
}

/**
 * @param  string|array{0: class-string, 1: string}  $action
 * @return array<string, mixed>
 */
function actionOperation(string $verb, string $path, string|array $action): array
{
    /** @var Router $router */
    $router = app('router');
    $router->{$verb}($path, $action);

    app()->instance(TypeEngine::class, actionEngine());

    return generateDocument()->document->toArray()['paths']['/'.$path][$verb] ?? [];
}

it('resolves an invokable action to handle(), documenting its summary, rules() body and authorize() 403', function (): void {
    $operation = actionOperation('post', 'api/publish', PublishArticleAction::class);

    // Summary comes from the resolved handle() docblock (not the trait's __invoke forwarder).
    expect($operation['summary'])->toBe('Publish an article.');

    // rules() became the JSON request body.
    $properties = $operation['requestBody']['content']['application/json']['schema']['properties'] ?? [];
    expect($properties)->toHaveKeys(['title', 'body']);

    // authorize() became a 403.
    expect($operation['responses'])->toHaveKey('403');
});

it('resolves an action defining asController() to that method over handle()', function (): void {
    $operation = actionOperation('put', 'api/archive', ArchiveArticleAction::class);

    // The asController() docblock summary proves it won the precedence over handle().
    expect($operation['summary'])->toBe('Archive an article.');
});

it('adds no request body or 403 for a minimal action with neither rules() nor authorize()', function (): void {
    $operation = actionOperation('get', 'api/simple', SimpleAction::class);

    expect($operation)->not->toHaveKey('requestBody')
        ->and($operation['responses'] ?? [])->not->toHaveKey('403');
});

it('documents no rules() body or authorize() 403 for an explicitly-registered method', function (): void {
    // Registered as [ExplicitMethodAction::class, 'store']: the package never validates an explicit
    // method, so despite the action defining rules() + authorize(), neither is documented.
    $operation = actionOperation('post', 'api/explicit', [ExplicitMethodAction::class, 'store']);

    expect($operation['summary'])->toBe('Store an article.')
        ->and($operation)->not->toHaveKey('requestBody')
        ->and($operation['responses'] ?? [])->not->toHaveKey('403');
});

it('documents no rules() body or authorize() 403 for a WithAttributes action', function (): void {
    // The WithAttributes trait opts out of automatic request validation, so rules()/authorize() never
    // run even though the invokable route dispatches through handle().
    $operation = actionOperation('post', 'api/with-attributes', WithAttributesAction::class);

    expect($operation)->not->toHaveKey('requestBody')
        ->and($operation['responses'] ?? [])->not->toHaveKey('403');
});
