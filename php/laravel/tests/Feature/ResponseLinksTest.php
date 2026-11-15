<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\BranchingCrateResource;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Crate;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\CrateController;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\KeyedCrateResource;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Pallet;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\PalletResource;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Parcel;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\ParcelController;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\ParcelResource;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/**
 * Response links the code proves: a response that sends back a model's column, linked to the operations
 * whose path parameters are matched on that column. Each one is a call a client can make with what the
 * response handed it, so the rows state which proof makes it true, and the refusals which one is missing.
 */
beforeEach(function (): void {
    $this->routes = static function (Router $router): void {
        $router->bind('consignment', static fn (string $value): Parcel => new Parcel);

        $router->post('api/parcels', [ParcelController::class, 'store']);
        $router->get('api/parcels/{parcel}', [ParcelController::class, 'show']);
        $router->patch('api/parcels/{parcel}', [ParcelController::class, 'update']);
        $router->delete('api/parcels/{parcel}', [ParcelController::class, 'destroy']);
        $router->get('api/tracked-parcels/{parcel:tracking}', [ParcelController::class, 'track']);
        $router->post('api/raw-parcels', [ParcelController::class, 'storeRaw']);
        $router->get('api/consignments/{consignment}', [ParcelController::class, 'consigned']);
        $router->model('shipment', Parcel::class);
        $router->get('api/shipments/{shipment}', [ParcelController::class, 'shipped']);
        $router->post('api/pallets', [ParcelController::class, 'storePallet']);
        $router->get('api/pallets/{pallet}', [ParcelController::class, 'showPallet']);
    };

    $this->engine = static function (): TypeEngine {
        $returns = static fn (string $fqcn): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(new ClassT($fqcn), new SourceLocation(''))]);
        $controller = ParcelController::class.'::';

        return WorkbenchEngine::make(
            classOverrides: [
                Pallet::class => new ClassMetadata(Pallet::class, [
                    new PropertyMetadata('id', ScalarT::int()),
                    new PropertyMetadata('tag', ScalarT::string()),
                ]),
                Parcel::class => new ClassMetadata(Parcel::class, [
                    new PropertyMetadata('id', ScalarT::int()),
                    new PropertyMetadata('tracking', ScalarT::string()),
                    new PropertyMetadata('label', ScalarT::string()),
                ]),
            ],
            analysisOverrides: [
                $controller.'store' => $returns(ParcelResource::class),
                $controller.'show' => $returns(ParcelResource::class),
                $controller.'update' => $returns(ParcelResource::class),
                $controller.'track' => $returns(ParcelResource::class),
                $controller.'storeRaw' => $returns(Parcel::class),
                $controller.'consigned' => $returns(ParcelResource::class),
                $controller.'shipped' => $returns(ParcelResource::class),
                $controller.'storePallet' => $returns(PalletResource::class),
                $controller.'showPallet' => $returns(PalletResource::class),
                PalletResource::class.'::toArray' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                    new ArrayShapeField('id', ScalarT::int()),
                    new ArrayShapeField('tag', ScalarT::string()),
                ]), new SourceLocation(''))]),
                ParcelResource::class.'::toArray' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                    new ArrayShapeField('id', ScalarT::int()),
                    new ArrayShapeField('tracking', ScalarT::string()),
                    new ArrayShapeField('label', ScalarT::string()),
                ]), new SourceLocation(''))]),
            ],
        );
    };
});

it('publishes the links its routes prove, warm as cold', function (): void {
    $warm = assertWarmEqualsCold($this->routes, $this->routes, $this->engine);

    assertGolden('response-links.uir.json', (new UirEmitter)->emit($warm->document));
});

it('links a create endpoint to every route that binds the model it gives back', function (): void {
    $document = emittedArray(localityBuild($this->routes, $this->engine));
    $links = $document['paths']['/api/parcels']['post']['responses']['200']['links'];

    // Each segment is matched on a column the resource sends as it is stored: the key under `id`, the
    // tracking code under `tracking`. So the value handed back is the value each route looks up.
    expect(array_map(static fn (array $link): array => $link['parameters'], $links))->toBe([
        'delete.api.parcels.@parcel' => ['parcel' => '$response.body#/data/id'],
        'get.api.parcels.@parcel' => ['parcel' => '$response.body#/data/id'],
        // `Route::model('shipment', Parcel::class)` resolves on the parcel's route key, so the key fills it.
        'get.api.shipments.@shipment' => ['shipment' => '$response.body#/data/id'],
        'get.api.tracked-parcels.@parcel' => ['parcel' => '$response.body#/data/tracking'],
        'patch.api.parcels.@parcel' => ['parcel' => '$response.body#/data/id'],
    ])
        ->and($links['get.api.parcels.@parcel']['operationId'])->toBe('get.api.parcels.@parcel')
        ->and($links['get.api.parcels.@parcel']['x-docuccino']['provenance'])->toBe([['producer' => 'inference', 'layer' => 'inference']]);
});

it('links on the route key a model names for itself', function (): void {
    $document = emittedArray(localityBuild($this->routes, $this->engine));

    // `Pallet::getRouteKeyName()` returns `'tag'`, so the tag is what `{pallet}` is looked up by — the
    // key would be a value the route never matches.
    expect($document['paths']['/api/pallets']['post']['responses']['200']['links'])
        ->toHaveKey('get.api.pallets.@pallet')
        ->and($document['paths']['/api/pallets']['post']['responses']['200']['links']['get.api.pallets.@pallet']['parameters'])
        ->toBe(['pallet' => '$response.body#/data/tag']);
});

it('reads a model returned bare as sending its columns under their own names', function (): void {
    $document = emittedArray(localityBuild($this->routes, $this->engine));
    $links = $document['paths']['/api/raw-parcels']['post']['responses']['200']['links'];

    expect($links['get.api.parcels.@parcel']['parameters'])->toBe(['parcel' => '$response.body#/id'])
        ->and($links['get.api.tracked-parcels.@parcel']['parameters'])->toBe(['parcel' => '$response.body#/tracking']);
});

it('writes no link to a segment whose binding it could not read', function (): void {
    $document = emittedArray(localityBuild($this->routes, $this->engine));
    $targets = [];
    foreach ($document['paths'] as $item) {
        foreach ($item as $operation) {
            foreach ($operation['responses'] as $response) {
                foreach ($response['links'] ?? [] as $link) {
                    $targets[$link['operationId']] = true;
                }
            }
        }
    }

    // The application's own binder decides what `{consignment}` holds, in a closure.
    expect($targets)->not->toHaveKey('get.api.consignments.@consignment')
        // …and the label is rewritten on its way out, so nothing is ever linked on it.
        ->and(json_encode($document['paths']))->not->toContain('/label');
});

/**
 * A route looks a record up by the value stored in the column, so only a member that reaches the wire as
 * stored can fill its segment. Each refusal below is a member whose value the server rewrote on the way
 * out: passing it back would look up something else, or nothing.
 */
it('links on a column only where the body sends it as it is stored', function (): void {
    $routes = static function (Router $router): void {
        $router->post('api/crates', [CrateController::class, 'store']);
        $router->post('api/keyed-crates', [CrateController::class, 'storeKeyed']);
        $router->post('api/branching-crates', [CrateController::class, 'storeBranching']);
        $router->get('api/crates/{crate}', [CrateController::class, 'show']);
        foreach (['code', 'label', 'secret', 'badge', 'created_at'] as $column) {
            $router->get('api/crates-by-'.$column.'/{crate:'.$column.'}', [CrateController::class, 'show']);
        }
    };
    $engine = static function (): TypeEngine {
        $returns = static fn (string $fqcn): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(new ClassT($fqcn), new SourceLocation(''))]);
        $shape = static fn (array $fields): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT($fields), new SourceLocation(''))]);
        $controller = CrateController::class.'::';

        return WorkbenchEngine::make(
            classOverrides: [Crate::class => new ClassMetadata(Crate::class, [
                new PropertyMetadata('id', ScalarT::int()),
                new PropertyMetadata('code', ScalarT::int()),
                new PropertyMetadata('label', ScalarT::string()),
                new PropertyMetadata('secret', ScalarT::string()),
            ])],
            analysisOverrides: [
                $controller.'store' => $returns(Crate::class),
                $controller.'show' => $returns(Crate::class),
                $controller.'storeKeyed' => $returns(KeyedCrateResource::class),
                $controller.'storeBranching' => $returns(BranchingCrateResource::class),
                KeyedCrateResource::class.'::toArray' => $shape([new ArrayShapeField('ref', ScalarT::int()), new ArrayShapeField('maybe', ScalarT::int())]),
                BranchingCrateResource::class.'::toArray' => $shape([new ArrayShapeField('id', ScalarT::int()), new ArrayShapeField('code', ScalarT::int())]),
            ],
        );
    };

    $paths = emittedArray(localityBuild($routes, $engine))['paths'];
    $linked = static fn (string $path): array => array_map(
        static fn (array $link): array => $link['parameters'],
        $paths[$path]['post']['responses']['200']['links'] ?? [],
    );

    // The key, and a code cast to `integer`, which writes the number it stores. Not the label an accessor
    // capitalises, the secret an encrypting cast hides, the badge appended from no column, or the
    // timestamp the framework writes in its own date format.
    expect($linked('/api/crates'))->toBe([
        'get.api.crates-by-code.@crate' => ['crate' => '$response.body#/code'],
        'get.api.crates.@crate' => ['crate' => '$response.body#/id'],
    ])
        // `getRouteKey()` is the route key; a member sent only `when()` something holds is not always there.
        ->and($linked('/api/keyed-crates'))->toBe(['get.api.crates.@crate' => ['crate' => '$response.body#/data/ref']])
        // Two returns agree on the key and not on the code, and a closure's return is not one of them.
        ->and($linked('/api/branching-crates'))->toBe(['get.api.crates.@crate' => ['crate' => '$response.body#/data/id']]);
});

/**
 * Which model a resource wraps is read where the action builds it, typed by the analyser — the half no
 * stub can stand in for. Anything but exactly one model class is no answer, because a link read off the
 * wrong model's columns sends a client a value no route matches.
 */
it('reads the model a resource wraps from where the action builds it, on the real engine', function (string $method, ?string $model): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $trace = FixtureRunner::traceResourceModels(
        'app/Http/Controllers/LinkedUserController.php',
        'App\\Http\\Controllers\\LinkedUserController',
        $method,
        'App\\Http\\Resources\\LinkedUserResource',
    );

    expect($trace['model'])->toBe($model);
})->with([
    'new Resource(Model::create())' => ['made', 'App\\Models\\User'],
    'Resource::make($bound)' => ['shown', 'App\\Models\\User'],
    'built in a helper the action calls' => ['helped', 'App\\Models\\User'],
    'built around a find() that may be null' => ['maybe', null],
])->group('fixture');
