<?php

declare(strict_types=1);

use Docuccino\Attributes\IgnoreParam;
use Docuccino\Attributes\QueryParameter;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Extensions\AttributeParametersExtension;
use Docuccino\Laravel\Extensions\IgnoredParametersExtension;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParametersExtension;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderUntypedFilterExtension;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\AppFilteredListController;
use Workbench\App\Http\Requests\FilterBoundsRequest;

/**
 * `query-builder.untyped-filter` says the document publishes a filter with no type at all, so what it
 * has to read is the parameter as it FINALLY stands — the kind alone is a claim about one producer,
 * made at the integration rung with a validation rule, a docblock and an attribute still to land on the
 * same parameter. A report the author cannot clear (the type the help asks for, already applied) is the
 * failure this guards: it teaches them to skip the channel, and the filters nothing typed go with it.
 */
function untypedFilterChain(): string
{
    // Two custom filters on a body nothing can reduce to a column, so the integration types neither.
    return 'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters(['
        ."AllowedFilter::custom('named', \\Workbench\\App\\Filters\\CompositeFilter::class), "
        ."AllowedFilter::custom('opaque', \\Workbench\\App\\Filters\\CompositeFilter::class),"
        .'])->paginate()';
}

/**
 * The parameters and the untyped-filter reports the QB passes leave behind, with the layers that sit
 * between them in their real order: the parameter attributes behind the integration, then the two
 * finalize passes — the subtractive one first, this report last.
 *
 * @param  list<object>  $attributes
 * @param  array<string, mixed>  $representation
 * @return array{0: array<string, array<string, mixed>>, 1: list<string>}
 */
function runUntypedFilterPasses(array $attributes = [], array $representation = []): array
{
    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/gadgets'),
        actionRef: new ActionRef('', 'App\\Gadgets', 'index'),
        attributes: new AttributeSet($attributes),
        engine: new StubTypeEngine(traces: ['App\\Gadgets::index' => TraceScript::forChain(untypedFilterChain())]),
        extensions: new ResolvedExtensions(typeToSchema: DefaultTypeMappers::all()),
        document: new DocumentConfig('default', [], representation: $representation),
    );

    $operation = new OperationDraft;
    (new QueryBuilderParametersExtension)->handle($operation, $context);
    (new AttributeParametersExtension)->handle($operation, $context);
    (new IgnoredParametersExtension)->handle($operation, $context);
    (new QueryBuilderUntypedFilterExtension)->handle($operation, $context);

    $byName = [];
    foreach ($operation->freeze()->parameters as $parameter) {
        $byName[$parameter->name] = $parameter->toArray();
    }

    $reported = array_map(
        static fn ($diagnostic): string => $diagnostic->message,
        diagnosticsCoded($context->components->diagnostics(), 'query-builder.untyped-filter'),
    );

    return [$byName, array_values($reported)];
}

it('reports the filter the document publishes untyped, and not the one an attribute typed', function (): void {
    [$byName, $reported] = runUntypedFilterPasses([
        new QueryParameter('filter[named]', type: 'string'),
    ]);

    // The attribute is the very fix the help asks for, on the same action — so a report naming this
    // filter would be one no edit could clear.
    expect($byName['filter[named]']['schema']['type'])->toBe('string')
        ->and($byName['filter[named]']['schema']['x-docuccino']['provenance'][0]['producer'])->toBe('attribute')
        ->and($reported)->toHaveCount(1)
        ->and($reported[0])->toContain('"opaque"')
        ->and($byName['filter[opaque]']['schema'])->toBe([]);
});

it('reads the deepObject property, where that representation publishes the filter', function (): void {
    [$byName, $reported] = runUntypedFilterPasses(
        [new QueryParameter('filter[named]', type: 'string')],
        ['filters' => 'deepObject'],
    );

    $properties = $byName['filter']['schema']['properties'];

    // Both properties carry the integration's prose, so "says nothing about the value" is the question
    // — an empty-schema test would answer no for both and report neither.
    expect($properties['named']['type'])->toBe('string')
        ->and($properties['opaque'])->not->toHaveKey('type')
        ->and($properties['opaque']['description'])->not->toBeEmpty()
        ->and($reported)->toHaveCount(1)
        ->and($reported[0])->toContain('"opaque"');
});

it('says nothing about a filter #[IgnoreParam] dropped, which the document does not carry at all', function (): void {
    [$byName, $reported] = runUntypedFilterPasses([
        new IgnoreParam(name: 'filter[opaque]', in: 'query'),
    ]);

    // The one still published is still reported, so this is the removal doing it and not a pass that
    // stopped running.
    expect($byName)->not->toHaveKey('filter[opaque]')
        ->and($reported)->toHaveCount(1)
        ->and($reported[0])->toContain('"named"');
});

it('still reports every untyped filter when no other layer types any of them', function (): void {
    [, $reported] = runUntypedFilterPasses();

    expect($reported)->toHaveCount(2)
        ->and(implode(' ', $reported))->toContain('"named"')
        ->and(implode(' ', $reported))->toContain('"opaque"');
});

/**
 * The whole build over an ad-hoc route, so the phases run in the order the registry puts them in: the
 * filters are the application's own closures, one typed by the action's attribute, one by the
 * FormRequest recovered in the request phase, one by nothing at all.
 */
function untypedFilterDocument(): array
{
    $action = AppFilteredListController::class.'::index';

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [
            // rules() as the engine recovers it — the constant array the class really returns.
            FilterBoundsRequest::class.'::rules' => new ActionAnalysis(returns: [new ReturnSite(
                new ArrayShapeT([new ArrayShapeField('filter.min_days', new LiteralT('integer|min:1|max:90'))]),
                new SourceLocation(''),
            )]),
        ],
        traceOverrides: [$action => TraceScript::forChain(<<<'PHP'
            QueryBuilder::for(\Workbench\App\Models\Gadget::class)->allowedFilters([
                AllowedFilter::callback('search', static function (Builder $query, mixed $value): void {
                    $query->where(static function (Builder $inner) use ($value): void {
                        $inner->where('name', 'like', '%'.$value.'%')
                            ->orWhere('status', 'like', '%'.$value.'%');
                    });
                }),
                AllowedFilter::callback('label', static function (Builder $query, mixed $value): void {
                    $query->whereHas('maker', static function (Builder $maker) use ($value): void {
                        $maker->where('name', $value);
                    });
                }),
                AllowedFilter::callback('min_days', static function (Builder $query, mixed $value): void {
                    $query->whereDate('starts_at', '>=', now()->subDays((int) $value))
                        ->orderByDesc('starts_at');
                }),
            ])->paginate(20)
            PHP)],
    ));

    /** @var Router $router */
    $router = app('router');
    $router->get('api/app-filtered', [AppFilteredListController::class, 'index']);

    $result = generateDocument();
    /** @var list<array<string, mixed>> $parameters */
    $parameters = $result->document->toArray()['paths']['/api/app-filtered']['get']['parameters'] ?? [];

    $schemas = [];
    foreach ($parameters as $parameter) {
        $schemas[(string) $parameter['name']] = $parameter['schema'] ?? null;
    }

    // This route's own reports: the default workbench routes include another Query Builder list whose
    // free-text filter nothing types, and it is not what this is about.
    $reports = array_values(array_filter(
        diagnosticsCoded($result->diagnostics, 'query-builder.untyped-filter'),
        static fn ($diagnostic): bool => str_contains((string) $diagnostic->routeSignature, 'api/app-filtered'),
    ));

    return [$schemas, $reports];
}

it('reports only the filter the finished document leaves untyped, whichever layer typed the others', function (): void {
    [$schemas, $reports] = untypedFilterDocument();

    // Anti-vacuity: all three filters are published, and two of them carry a type that can only have
    // come from a layer running behind the integration.
    expect($schemas)->toHaveKeys(['filter[search]', 'filter[label]', 'filter[min_days]'])
        ->and($schemas['filter[label]']['type'])->toBe('string')
        ->and($schemas['filter[min_days]']['type'])->toBe('integer')
        ->and($schemas['filter[min_days]']['maximum'])->toBe(90)
        ->and($schemas['filter[search]'])->toBe([]);

    expect($reports)->toHaveCount(1)
        ->and($reports[0]->message)->toContain('"search"')
        ->and($reports[0]->severity)->toBe(Severity::Info)
        ->and($reports[0]->help)->toContain('#[QueryParameter(');
});
