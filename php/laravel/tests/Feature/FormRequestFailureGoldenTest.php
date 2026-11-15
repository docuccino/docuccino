<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Exceptions\SubmissionMalformedException;
use Workbench\App\Http\Controllers\FailureOverrideController;
use Workbench\App\Http\Requests\Failures\ApiRequest;
use Workbench\App\Http\Requests\Failures\BaggedFailureRequest;
use Workbench\App\Http\Requests\Failures\ConfiguredFailureRequest;
use Workbench\App\Http\Requests\Failures\EnvelopedFailureRequest;
use Workbench\App\Http\Requests\Failures\HiddenRefusalRequest;
use Workbench\App\Http\Requests\Failures\InheritedFailureRequest;
use Workbench\App\Http\Requests\Failures\MalformedFailureRequest;
use Workbench\App\Http\Requests\Failures\ParentFailureRequest;
use Workbench\App\Http\Requests\Failures\RefusalMessageRequest;

/**
 * The document an application gets for a form request that answers a failed validation or authorization its
 * own way: a response it throws for the framework to send, an exception of its own, the framework's
 * exception with messages of its own, the parent's answer, and one nothing can read; the override written on
 * the request and inherited from a base every request extends. Byte-locked, warm as well as cold; the scripted
 * analyses are what the real engine recovers from the fixture app's same requests, which a test below holds
 * them to.
 *
 * @return array<string, ActionAnalysis> `Class::method` → what the engine recovers from it
 */
function formRequestFailureAnalyses(): array
{
    $at = static fn (DType $type): ReturnSite => new ReturnSite($type, new SourceLocation(''));
    $json = static fn (array $fields, int $status): ClassT => new ClassT('Illuminate\\Http\\JsonResponse', [
        new ArrayShapeT(array_map(static fn (string $key, DType $type): ArrayShapeField => new ArrayShapeField($key, $type), array_keys($fields), $fields)),
        new LiteralT($status),
    ]);
    $thrown = static fn (string $class, int $status, ThrowConfidence $confidence = ThrowConfidence::Certain): ThrownException => new ThrownException($class, $status, [], $confidence, ThrowDisposition::Signal);
    $bag = new ClassT('Illuminate\\Support\\MessageBag');
    $validation = 'Illuminate\\Validation\\ValidationException';

    // Every request validates one field, so each route publishes the response a failed validation gets.
    $rules = [];
    foreach ([EnvelopedFailureRequest::class, InheritedFailureRequest::class, BaggedFailureRequest::class, MalformedFailureRequest::class, ParentFailureRequest::class, ConfiguredFailureRequest::class, RefusalMessageRequest::class, HiddenRefusalRequest::class] as $request) {
        $rules[$request.'::rules'] = new ActionAnalysis(returns: [$at(new ArrayShapeT([
            new ArrayShapeField('title', new ArrayShapeT([new ArrayShapeField(0, new LiteralT('required')), new ArrayShapeField(1, new LiteralT('string'))])),
        ]))]);
    }

    return [
        ...$rules,
        // A response thrown for the framework to send is what a failed validation is answered with.
        EnvelopedFailureRequest::class.'::failedValidation' => new ActionAnalysis(returns: [$at($json([
            'success' => new LiteralT(false),
            'message' => new LiteralT('The given data was invalid.'),
            'errors' => $bag,
        ], 422))]),
        // Read where it is written: on the base every request extends.
        ApiRequest::class.'::failedValidation' => new ActionAnalysis(returns: [$at($json([
            'success' => new LiteralT(false),
            'errors' => $bag,
        ], 422))]),
        // The framework's exception renders as the framework's body, whatever bag and redirect it names.
        BaggedFailureRequest::class.'::failedValidation' => new ActionAnalysis(throws: [$thrown($validation, 422)]),
        MalformedFailureRequest::class.'::failedValidation' => new ActionAnalysis(throws: [$thrown(SubmissionMalformedException::class, 400)]),
        // The parent's answer, read off the exception it declares.
        ParentFailureRequest::class.'::failedValidation' => new ActionAnalysis(throws: [$thrown($validation, 422, ThrowConfidence::Declared)]),
        // The exception is whichever class configuration names, so nothing is read.
        ConfiguredFailureRequest::class.'::failedValidation' => new ActionAnalysis,
        RefusalMessageRequest::class.'::authorize' => new ActionAnalysis(returns: [$at(ScalarT::bool())]),
        RefusalMessageRequest::class.'::failedAuthorization' => new ActionAnalysis(throws: [$thrown('Illuminate\\Auth\\Access\\AuthorizationException', 403)]),
        HiddenRefusalRequest::class.'::authorize' => new ActionAnalysis(returns: [$at(ScalarT::bool())]),
        HiddenRefusalRequest::class.'::failedAuthorization' => new ActionAnalysis(returns: [$at($json([
            'message' => new LiteralT('Not found.'),
        ], 404))]),
    ];
}

/** @return list<string> */
function formRequestFailureActions(): array
{
    return ['enveloped', 'inherited', 'bagged', 'malformed', 'parentFailure', 'configured', 'refusalMessage', 'hiddenRefusal'];
}

/** @return callable(): TypeEngine */
function formRequestFailureEngine(): callable
{
    $analyses = formRequestFailureAnalyses();
    foreach (formRequestFailureActions() as $action) {
        $analyses[FailureOverrideController::class.'::'.$action] = new ActionAnalysis(returns: [new ReturnSite(
            new ClassT('Illuminate\\Http\\JsonResponse', [
                new ArrayShapeT([new ArrayShapeField('filed', new LiteralT(true))]),
                new LiteralT(201),
            ]),
            new SourceLocation(''),
        )]);
    }

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: $analyses);
}

function formRequestFailureRoutes(): callable
{
    return static function (Router $router): void {
        foreach (formRequestFailureActions() as $action) {
            $router->post('api/failures/'.$action, [FailureOverrideController::class, $action]);
        }
    };
}

it('publishes what a form request answers a failed validation or authorization with, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(formRequestFailureRoutes(), formRequestFailureRoutes(), formRequestFailureEngine());

    assertGolden('workbench-form-request-failure.uir.json', (new UirEmitter)->emit($warm->document));
});

it('scripts what the real engine recovers from the fixture app’s same requests', function (): void {
    ensureFixtureAvailable(FixtureRunner::available());

    // What reaches the document: every return's type, and each throw it publishes. The fixture app's
    // classes are the workbench's under another namespace.
    $facts = static fn (ActionAnalysis $analysis): array => [
        array_map(static fn (ReturnSite $site): array => $site->type->toArray(), $analysis->returns),
        array_values(array_map(
            static fn (ThrownException $throw): string => str_replace('Workbench\\App\\', 'App\\', $throw->identityKey()).' '.$throw->confidence->value,
            array_filter($analysis->throws, static fn (ThrownException $throw): bool => $throw->disposition === ThrowDisposition::Signal),
        )),
    ];

    // One engine per request class, every method of it asked at once.
    $byClass = [];
    foreach (array_keys(formRequestFailureAnalyses()) as $symbol) {
        [$class, $method] = explode('::', $symbol);
        $byClass[str_replace('Workbench\\App\\', 'App\\', $class)][] = $method;
    }

    $real = [];
    foreach ($byClass as $class => $methods) {
        $file = 'app/'.str_replace('\\', '/', substr($class, strlen('App\\'))).'.php';
        foreach (FixtureRunner::analyzeMany($file, $class, $methods) as $method => $recovered) {
            $real[$class.'::'.$method] = $recovered;
        }
    }

    foreach (formRequestFailureAnalyses() as $symbol => $scripted) {
        /** @var array<string, mixed> $recovered */
        $recovered = $real[str_replace('Workbench\\App\\', 'App\\', $symbol)];

        expect($facts(ActionAnalysis::fromArray($recovered)))->toBe($facts($scripted), $symbol);
    }
})->group('fixture');
