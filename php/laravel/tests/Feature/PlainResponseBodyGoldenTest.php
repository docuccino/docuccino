<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\NoticeController;

/**
 * The document an application gets for plain responses handed a string, nothing or an array, in every
 * spelling that makes one: `new Response()`, the `response()` helper, `response()->make()`, the `Response`
 * facade, Symfony's own response and a subclass handing its parent the content, with and without a
 * `Content-Type` header. Byte-locked, warm as well as cold; the scripted returns are what the real engine
 * recovers from the fixture app's same calls, which the last test holds them to.
 *
 * @return array<string, DType> action → the response it returns
 */
function plainResponseBodyReturns(): array
{
    $json = static fn (DType $body, int $status, DType ...$mediaType): ClassT => new ClassT('Illuminate\\Http\\JsonResponse', [$body, new LiteralT($status), ...$mediaType]);
    $text = new LiteralT('The export has expired.');
    $locked = new ArrayShapeT([new ArrayShapeField('error', new LiteralT('locked'))]);
    $unread = new UnknownT('payload not folded');

    return [
        'constructed' => $json($text, 410),
        'constructedEmpty' => $json(new LiteralT(''), 410),
        'constructedArray' => $json($locked, 422),
        'constructedCsv' => $json(new LiteralT('id,name'), 200, new LiteralT('text/csv')),
        'constructedArrayLabelled' => $json($locked, 422, new LiteralT('text/plain')),
        'helper' => $json($unread, 410),
        'helperEmpty' => $json(new VoidT, 200),
        'helperArray' => new ClassT('Illuminate\\Http\\Response'),
        'helperPlain' => $json($unread, 410, new LiteralT('text/plain')),
        'helperMessage' => $json($unread, 503),
        'helperView' => $json($unread, 410),
        'facade' => $json($unread, 410),
        'factory' => $json($unread, 410),
        'symfony' => $json($text, 410),
        'subclass' => $json($unread, 410),
        'subclassArray' => $json($unread, 423),
    ];
}

/** @return callable(): TypeEngine */
function plainResponseBodyEngine(): callable
{
    $analyses = [];
    foreach (plainResponseBodyReturns() as $action => $type) {
        $analyses[NoticeController::class.'::'.$action] = new ActionAnalysis(returns: [new ReturnSite($type, new SourceLocation(''))]);
    }

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: $analyses);
}

function plainResponseBodyRoutes(): callable
{
    return static function (Router $router): void {
        foreach (array_keys(plainResponseBodyReturns()) as $action) {
            $router->get('api/notices/'.strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $action)), [NoticeController::class, $action]);
        }
    };
}

it('publishes what a plain response sends for the content it is handed, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(plainResponseBodyRoutes(), plainResponseBodyRoutes(), plainResponseBodyEngine());

    assertGolden('workbench-plain-response-body.uir.json', (new UirEmitter)->emit($warm->document));
});

it('scripts what the real engine recovers from the fixture app’s same calls', function (): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $returns = plainResponseBodyReturns();
    $real = FixtureRunner::analyzeMany(
        'app/Http/Controllers/PlainResponseBodyController.php',
        'App\\Http\\Controllers\\PlainResponseBodyController',
        array_keys($returns),
    );

    foreach ($returns as $action => $scripted) {
        expect(array_map(static fn (ReturnSite $site): DType => $site->type, ActionAnalysis::fromArray($real[$action])->returns))
            ->toEqual([$scripted], $action);
    }
})->group('fixture');
