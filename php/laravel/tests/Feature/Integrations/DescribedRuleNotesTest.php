<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Tests\Fixtures\DescribedNotes\DescribedFilterController;
use Docuccino\Laravel\Tests\Fixtures\DescribedNotes\ListingController;
use Docuccino\Laravel\Tests\Fixtures\DescribedNotes\ListingRequest;
use Docuccino\Laravel\Tests\Fixtures\DescribedNotes\RangeData;
use Docuccino\Laravel\Tests\Fixtures\DescribedNotes\RangeDataController;
use Docuccino\Laravel\Tests\Fixtures\DescribedNotes\TypeDescribedListingRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/*
 * Fields whose validation states a fact no keyword can carry — a date bound, a set of prefixes, a
 * condition that requires or refuses the field — read as query parameters and as a body, from a Form
 * Request and from a Data class, with and without a description their author wrote: on the action, on
 * the request type, on a Data property's docblock or `#[Description]`, and on an enum. Beside them, a
 * Query Builder filter whose list and `null` its schema cannot state. No golden held a field that had
 * both.
 */
function describedRuleNotesBuild(): GenerationResult
{
    bootLaravelData(null);
    // An enum published inline, where its own description and the field's rules share the one field.
    setBuild('documents.default.representation.enums.components', false);

    $string = ScalarT::string();
    $optional = new ClassT(DataClassReflector::OPTIONAL);
    $returns = new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))]);
    $rules = static fn (string $class): Closure => TraceScript::forMethod((string) (new ReflectionClass($class))->getFileName(), $class, 'rules');

    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            RangeData::class => new ClassMetadata(RangeData::class, [
                new PropertyMetadata('from', $string),
                new PropertyMetadata('until', UnionT::of([$string, $optional]), summary: 'End of the range.'),
                new PropertyMetadata('kind', UnionT::of([$string, $optional])),
                new PropertyMetadata('nickname', UnionT::of([$string, $optional])),
            ]),
        ],
        analysisOverrides: [
            ListingController::class.'::index' => $returns,
            ListingController::class.'::search' => $returns,
            ListingController::class.'::store' => $returns,
            ListingController::class.'::create' => $returns,
            RangeDataController::class.'::index' => $returns,
            RangeDataController::class.'::store' => $returns,
        ],
        traceOverrides: [
            ListingRequest::class.'::rules' => $rules(ListingRequest::class),
            TypeDescribedListingRequest::class.'::rules' => $rules(TypeDescribedListingRequest::class),
            ...array_map(static fn (string $action): Closure => TraceScript::forMethod(
                (string) (new ReflectionClass(DescribedFilterController::class))->getFileName(),
                DescribedFilterController::class,
                $action,
                receiverFqcn: 'Spatie\\QueryBuilder\\QueryBuilder',
            ), [
                DescribedFilterController::class.'::index' => 'index',
                DescribedFilterController::class.'::browse' => 'browse',
            ]),
        ],
    );

    return localityBuild(static function (Router $router): void {
        $router->get('api/zz-listings', [ListingController::class, 'index']);
        $router->get('api/zz-listings/search', [ListingController::class, 'search']);
        $router->post('api/zz-listings', [ListingController::class, 'store']);
        $router->post('api/zz-listings/create', [ListingController::class, 'create']);
        $router->get('api/zz-ranges', [RangeDataController::class, 'index']);
        $router->post('api/zz-ranges', [RangeDataController::class, 'store']);
        $router->get('api/zz-gadgets', [DescribedFilterController::class, 'index']);
        $router->get('api/zz-gadgets/browse', [DescribedFilterController::class, 'browse']);
    }, $engine);
}

it('emits validated fields their author described byte-identical to their committed golden', function (): void {
    assertGolden('described-rule-notes.uir.json', (new UirEmitter)->emit(describedRuleNotesBuild()->document));
});
