<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\LaravelActions\LaravelAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\ArchiveArticleAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\PublishArticleAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\SimpleAction;
use Workbench\App\Http\Controllers\FormController;

/**
 * The action detection + route-method resolution — the one place laravel-actions changes how a route
 * maps to a method. Dataset-driven over the registration styles the package supports (invokable
 * action with asController, with only handle, with neither; an explicitly-registered method; a plain
 * non-action controller) so every branch of the asController > handle > __invoke precedence + the
 * non-action degradation is covered with real reflection.
 */
it('recognises an action by its AsController/AsAction trait', function (string $class, bool $expected): void {
    expect(LaravelAction::isAction($class))->toBe($expected);
})->with([
    'AsAction umbrella trait' => [PublishArticleAction::class, true],
    'AsController trait directly' => [ArchiveArticleAction::class, true],
    'plain controller (no trait)' => [FormController::class, false],
]);

it('resolves the dispatched controller method across registration styles', function (string $class, string $registered, string $resolved): void {
    expect(LaravelAction::controllerMethod($class, $registered))->toBe($resolved);
})->with([
    'invokable action with asController → asController' => [ArchiveArticleAction::class, '__invoke', 'asController'],
    'invokable action with only handle → handle' => [PublishArticleAction::class, '__invoke', 'handle'],
    'invokable minimal action → handle' => [SimpleAction::class, '__invoke', 'handle'],
    'explicit method registration is honoured verbatim' => [ArchiveArticleAction::class, 'handle', 'handle'],
    'non-action invokable controller is unchanged' => [FormController::class, '__invoke', '__invoke'],
]);
