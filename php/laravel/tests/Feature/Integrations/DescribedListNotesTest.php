<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ArticleJsonApiResource;
use Docuccino\Laravel\Tests\Fixtures\DescribedNotes\DescribedListController;
use Docuccino\Laravel\Tests\Fixtures\DescribedNotes\DescribedResourceController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\WrappedData;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/*
 * Query parameters whose whole description is facts an integration recovered or a package's grammar
 * fixes — a Query Builder list's allowed values, separator and default, the separator of a JSON:API
 * `include` and `fields[TYPE]` and of a Data object's request partials, and what keeps a signed link's
 * signature valid — each with and without a description their author wrote. The Query Builder list is
 * read under the default comma, where its values are also an enum, and under another separator, where
 * its description is the only place that names them. No golden held one an author had described.
 */
function describedListNotesBuild(string $delimiter = ','): GenerationResult
{
    bootLaravelData(null);
    config()->set('query-builder.delimiter', $delimiter);

    $returns = static fn (string $class): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(new ClassT($class), new SourceLocation(''))]);
    $list = (string) (new ReflectionClass(DescribedListController::class))->getFileName();

    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        analysisOverrides: [
            DescribedResourceController::class.'::article' => $returns(ArticleJsonApiResource::class),
            DescribedResourceController::class.'::plainArticle' => $returns(ArticleJsonApiResource::class),
            DescribedResourceController::class.'::wrapped' => $returns(WrappedData::class),
            DescribedResourceController::class.'::plainWrapped' => $returns(WrappedData::class),
        ],
        traceOverrides: [
            DescribedListController::class.'::index' => TraceScript::forMethod($list, DescribedListController::class, 'index', receiverFqcn: 'Spatie\\QueryBuilder\\QueryBuilder'),
            DescribedListController::class.'::browse' => TraceScript::forMethod($list, DescribedListController::class, 'browse', receiverFqcn: 'Spatie\\QueryBuilder\\QueryBuilder'),
        ],
    );

    return localityBuild(static function (Router $router) use ($delimiter): void {
        $router->get('api/zz-gadget-lists', [DescribedListController::class, 'index']);
        $router->get('api/zz-gadget-lists/browse', [DescribedListController::class, 'browse']);
        if ($delimiter !== ',') {
            return;
        }

        $router->get('api/zz-articles/{id}', [DescribedResourceController::class, 'article']);
        $router->get('api/zz-articles/{id}/plain', [DescribedResourceController::class, 'plainArticle']);
        $router->get('api/zz-wrapped', [DescribedResourceController::class, 'wrapped']);
        $router->get('api/zz-wrapped/plain', [DescribedResourceController::class, 'plainWrapped']);
        $router->get('api/zz-links/{token}', [DescribedResourceController::class, 'confirm'])->middleware('signed');
        $router->get('api/zz-links/{token}/plain', [DescribedResourceController::class, 'plainConfirm'])->middleware('signed');
    }, $engine);
}

it('emits integration parameters their author described byte-identical to their committed golden', function (string $delimiter, string $golden): void {
    assertGolden($golden, (new UirEmitter)->emit(describedListNotesBuild($delimiter)->document));
})->with([
    'the comma' => [',', 'described-list-notes.uir.json'],
    'another separator' => ['|', 'described-list-notes-delimited.uir.json'],
]);
