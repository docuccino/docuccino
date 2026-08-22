<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ConstValue;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParametersExtension;
use Docuccino\Laravel\Tests\Support\StubTraceScope;
use Docuccino\Laravel\Tests\Support\TraceScript;
use PhpParser\Node;
use PhpParser\ParserFactory;

/**
 * End-to-end proof that a SHARED custom filter class can declare its schema once, class-level, for
 * every call site: a project factory's body is folded to the `AllowedFilter::custom` it wraps, so the
 * wrapped class's `#[QueryParameter]` (format included) lands on the entry; an unfoldable factory
 * falls back to its own class attribute; and nothing anywhere degrades to a guess. Scripted trace over
 * real workbench fixtures — the engine's own fold is proven in the fixture group.
 */
function runFactoryClassAttribute(string $chain, array $foldedReturns = []): array
{
    $engine = new StubTypeEngine(traces: [
        'App\\Gadgets::index' => TraceScript::forChain($chain, foldedReturns: $foldedReturns),
    ]);

    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/gadgets'),
        actionRef: new ActionRef('', 'App\\Gadgets', 'index'),
        attributes: new AttributeSet,
        engine: $engine,
        document: new DocumentConfig('default', []),
    );

    $operation = new OperationDraft;
    (new QueryBuilderParametersExtension)->handle($operation, $context);

    $byName = [];
    foreach ($operation->freeze()->parameters as $parameter) {
        $byName[$parameter->name] = $parameter->toArray();
    }

    return [$byName, $context->dependencyFiles()];
}

/**
 * The answer the engine's return fold gives for one factory body, folded through the same stub scope
 * the visitor sees. The real engine resolves `new self` to the concrete FQCN before answering, so the
 * scripted body writes the class out fully qualified.
 *
 * @return array{0: ?ConstValue, 1: ?Node\Expr}
 */
function factoryFoldOf(string $expression): array
{
    $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$expression.';') ?? [];
    $statement = $ast[0] ?? null;
    $expr = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;

    return $expr === null
        ? [null, null]
        : [(new StubTraceScope(new ClassT('Spatie\\QueryBuilder\\QueryBuilder')))->constantValueOf($expr), $expr];
}

it('applies the wrapped filter class attribute through its own static factory (the new-self idiom)', function (): void {
    [$byName, $dependencyFiles] = runFactoryClassAttribute(
        'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters(['
            ."\\Workbench\\App\\Filters\\PublicIdFilter::allowed('position_id'),"
            .'])->paginate()',
        foldedReturns: [
            'allowed' => factoryFoldOf("\\Spatie\\QueryBuilder\\AllowedFilter::custom('position_id', new \\Workbench\\App\\Filters\\PublicIdFilter, 'position_id')"),
        ],
    );

    // The class-level attribute is the one declaration: type + format + description, on every call site.
    expect($byName['filter[position_id]']['schema']['type'])->toBe('string')
        ->and($byName['filter[position_id]']['schema']['format'])->toBe('uuid')
        ->and($byName['filter[position_id]']['description'])->toBe('A uuid public identifier.');

    // Cache soundness: the filter class file joins the dependency set, so editing it re-documents.
    expect(array_map('basename', $dependencyFiles))->toContain('PublicIdFilter.php');
});

it('applies the wrapped filter class attribute through a wrapper factory on another class', function (): void {
    // Factory class ≠ filter class (`ListFilters::uuid(...)` shape): the attribute lives on the WRAPPED
    // class, reachable only through the body fold.
    [$byName] = runFactoryClassAttribute(
        'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters(['
            ."\\Workbench\\App\\Support\\FilterFactory::publicId('owner_id'),"
            .'])->paginate()',
        foldedReturns: [
            'publicId' => factoryFoldOf("\\Spatie\\QueryBuilder\\AllowedFilter::custom('owner_id', new \\Workbench\\App\\Filters\\PublicIdFilter, 'owner_id')"),
        ],
    );

    expect($byName['filter[owner_id]']['schema']['type'])->toBe('string')
        ->and($byName['filter[owner_id]']['schema']['format'])->toBe('uuid')
        ->and($byName['filter[owner_id]']['description'])->toBe('A uuid public identifier.');
});

it('falls back to the factory class own attribute when its body cannot fold, format riding the base string', function (): void {
    // No entry in foldedReturns: the engine declines a branching body. The attribute carries format
    // WITHOUT type, which must ride on the base string schema.
    [$byName, $dependencyFiles] = runFactoryClassAttribute(
        'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters(['
            ."\\Workbench\\App\\Filters\\LegacyKeyFilter::allowed('legacy_id'),"
            .'])->paginate()',
    );

    expect($byName['filter[legacy_id]']['schema']['type'])->toBe('string')
        ->and($byName['filter[legacy_id]']['schema']['format'])->toBe('ulid')
        ->and($byName['filter[legacy_id]']['description'])->toBe('A legacy ulid key.');

    expect(array_map('basename', $dependencyFiles))->toContain('LegacyKeyFilter.php');
});

it('keeps call-site column typing for a factory with no attribute anywhere', function (): void {
    // Unfoldable AND attribute-less → exactly the pre-existing behavior: the key column's boolean cast.
    [$byName] = runFactoryClassAttribute(
        'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters(['
            ."\\Workbench\\App\\Support\\FilterFactory::boolean('active'),"
            .'])->paginate()',
    );

    expect($byName['filter[active]']['schema']['type'])->toBe('boolean')
        ->and($byName['filter[active]']['description'])->toBe('Filter');
});

it('ignores a fold that answers with a non-custom factory kind', function (): void {
    // The body returns AllowedFilter::exact — new information the call site already typed better; the
    // upgrade only fires for a wrapped custom filter class.
    [$byName] = runFactoryClassAttribute(
        'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters(['
            ."\\Workbench\\App\\Support\\FilterFactory::boolean('active'),"
            .'])->paginate()',
        foldedReturns: [
            'boolean' => factoryFoldOf("\\Spatie\\QueryBuilder\\AllowedFilter::exact('active')"),
        ],
    );

    expect($byName['filter[active]']['schema']['type'])->toBe('boolean');
});

it('applies format from a class attribute on a directly registered custom filter', function (): void {
    // Fix (a) alone, no factory in sight: AllowedFilter::custom at the call site, format read off the
    // class attribute exactly as the route-level layer would read it.
    [$byName] = runFactoryClassAttribute(
        'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters(['
            ."AllowedFilter::custom('pub', \\Workbench\\App\\Filters\\PublicIdFilter::class),"
            .'])->paginate()',
    );

    expect($byName['filter[pub]']['schema']['type'])->toBe('string')
        ->and($byName['filter[pub]']['schema']['format'])->toBe('uuid')
        ->and($byName['filter[pub]']['description'])->toBe('A uuid public identifier.');
});
