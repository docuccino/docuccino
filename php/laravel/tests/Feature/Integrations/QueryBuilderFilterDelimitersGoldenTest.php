<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\QueryBuilder\FilterDelimitersController;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/*
 * Query Builder filters with a separator of their own, locked in emitted bytes: every recorded filter
 * split on the configured delimiter.
 */
it('emits Query Builder filters with their own delimiter byte-identical to their committed golden', function (): void {
    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        traceOverrides: [
            FilterDelimitersController::class.'::index' => TraceScript::forMethod(
                (string) (new ReflectionClass(FilterDelimitersController::class))->getFileName(),
                FilterDelimitersController::class,
                'index',
                receiverFqcn: 'Spatie\\QueryBuilder\\QueryBuilder',
            ),
        ],
    );

    $result = localityBuild(
        static fn (Router $router) => $router->get('api/zz-filter-delimiters', [FilterDelimitersController::class, 'index']),
        $engine,
    );

    assertGolden('qb-filter-delimiters.uir.json', (new UirEmitter)->emit($result->document));
});
