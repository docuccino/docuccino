<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * The `Response` facade hands every call to the response factory it resolves, so `Response::json($b, 422)`
 * is sent exactly as `response()->json($b, 422)` is: with that status and that body. Publishing the bare
 * class the facade's `@method` tag declares documents a 200 the endpoint never sends. Each call is read as
 * the factory's own ({@see ResponseHelperStatusTest}, {@see CaseStatusResponseTest}).
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** @return array<string, array<string, mixed>> */
function responseFacadeStatusAnalyses(): array
{
    static $analyses = null;

    return $analyses ??= FixtureRunner::analyzeMany(
        'app/Http/Controllers/ResponseFacadeStatusController.php',
        'App\\Http\\Controllers\\ResponseFacadeStatusController',
        ['json', 'jsonOk', 'noContent', 'make', 'view', 'alias', 'caseRead', 'helperStatus', 'helperCase', 'helperMade', 'download', 'file', 'stream', 'redirect'],
    );
}

function responseFacadeStatusReturn(string $method): DType
{
    $returns = ActionAnalysis::fromArray(responseFacadeStatusAnalyses()[$method])->returns;
    expect($returns)->toHaveCount(1);

    return $returns[0]->type;
}

it('sends a facade call with the body and status the factory sends it with', function (string $method, DType $payload, int $status): void {
    expect(responseFacadeStatusReturn($method))->toEqual(new ClassT('Illuminate\\Http\\JsonResponse', [$payload, new LiteralT($status)]));
})->with([
    'Response::json($body, 422)' => ['json', new ArrayShapeT([new ArrayShapeField('error', new LiteralT('locked'))]), 422],
    // The framework's own default, as `response()->json($body)` is.
    'Response::json($body)' => ['jsonOk', new ArrayShapeT([new ArrayShapeField('ok', new LiteralT(true))]), 200],
    'Response::noContent(205)' => ['noContent', new VoidT, 205],
    'Response::make(\'\', 410)' => ['make', new VoidT, 410],
    'Response::view($view, [], 410)' => ['view', new ClassT('Illuminate\\Contracts\\View\\View'), 410],
    'Response::json($body, Case->value)' => ['caseRead', new ArrayShapeT([new ArrayShapeField('error', new LiteralT('locked'))]), 423],
    // A helper's status, and a body member read off the case it is handed, are the call site's.
    '$this->failed(409), passing its status on' => ['helperStatus', new ArrayShapeT([new ArrayShapeField('error', new LiteralT('failed'))]), 409],
    '$this->faulted(Case), reading $fault->value' => ['helperCase', new ArrayShapeT([new ArrayShapeField('error', new LiteralT('Gone'))]), 410],
    '$this->made(Case), making a plain response' => ['helperMade', new VoidT, 423],
])->group('fixture');

it('depends on the enum a status was read off through the facade', function (string $method): void {
    $files = array_map(basename(...), responseFacadeStatusAnalyses()[$method]['dependencyFiles']);

    expect($files)->toContain('ExportFault.php');
})->with(['caseRead', 'helperCase', 'helperMade'])->group('fixture');

it('types the file, stream and redirect responses the facade makes as the factory\'s', function (string $method, string $class): void {
    // Their media type and disposition are read off the call site, whichever spelling made it
    // (`FileResponseVisitor`); the type only has to name the class.
    expect(responseFacadeStatusReturn($method))->toEqual(new ClassT($class));
})->with([
    'Response::download()' => ['download', 'Symfony\\Component\\HttpFoundation\\BinaryFileResponse'],
    'Response::file()' => ['file', 'Symfony\\Component\\HttpFoundation\\BinaryFileResponse'],
    'Response::stream()' => ['stream', 'Symfony\\Component\\HttpFoundation\\StreamedResponse'],
    'Response::redirectTo()' => ['redirect', 'Illuminate\\Http\\RedirectResponse'],
])->group('fixture');

it('claims nothing for the root-namespace alias, which the analyser cannot read', function (): void {
    // PHPStan resolves no class `\Response` here, so the call has no type to read: unknown, not a guess.
    expect(responseFacadeStatusReturn('alias'))->toBeInstanceOf(UnknownT::class);
})->group('fixture');
