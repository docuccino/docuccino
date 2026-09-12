<?php

declare(strict_types=1);

use Docuccino\Attributes\BodyParameter;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\FormRequest\RulesFromClass;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Docuccino\Laravel\Tests\Fixtures\FormRequest\CustomRuleRequest;
use Docuccino\Laravel\Tests\Fixtures\FormRequest\DeclaredRulesData;
use Docuccino\Laravel\Tests\Fixtures\FormRequest\SuppressibleRulesData;
use Docuccino\Laravel\Tests\Support\RulesTraceScript;
use Workbench\App\Http\Controllers\InlineValidationController;

/**
 * A rules recoverer says what became of a field it could not read. Both of those sentences are claims
 * about the document — "omitted from the request schema", "left off the request schema" — and a
 * `#[BodyParameter]` writes the field at a layer above the recovery, so a recoverer that speaks without
 * reading the declarations is asserting what a later layer has already decided.
 */

/**
 * The rules-recovery diagnostics a build raises for one route, in emission order.
 *
 * @return list<string>
 */
function recoveryNotices(RouteContext $context, string $code): array
{
    return array_values(array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->message,
        array_filter($context->components->diagnostics(), static fn (Diagnostic $diagnostic): bool => $diagnostic->code === $code),
    ));
}

/**
 * A route context whose action carries `$declarations`, with `$symbol`'s rules array scripted.
 *
 * @param  list<BodyParameter>  $declarations
 */
function declaredRulesContext(string $symbol, string $snippet, array $declarations, string $verb = 'POST'): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor([$verb], 'api/listings'),
        actionRef: new ActionRef('', 'App\\ListingController', 'store'),
        attributes: new AttributeSet($declarations),
        engine: new StubTypeEngine(traces: [$symbol => RulesTraceScript::forPhp($snippet)]),
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(
            typeToSchema: DefaultTypeMappers::all(),
            ruleTransformers: ValidationIntegration::transformers(),
        ),
    );
}

it('does not call an inline field omitted that a declaration puts in the body', function (): void {
    app('router')->post('api/inline-validated', [InlineValidationController::class, 'store']);

    app()->instance(TypeEngine::class, new StubTypeEngine(traces: [
        InlineValidationController::class.'::store' => RulesTraceScript::forPhp(
            "\$request->validate(['title' => 'required|string', 'payload' => [fn () => true], 'secret' => [fn () => true]]);",
        ),
    ]));

    $result = generateDocument();
    $document = $result->document->toArray();
    $schema = $document['paths']['/api/inline-validated']['post']['requestBody']['content']['application/json']['schema'];

    // The premise: the declaration publishes `payload`, so nothing about it was omitted.
    expect(array_keys($schema['properties']))->toBe(['title', 'payload']);

    $messages = array_values(array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->message,
        array_filter($result->diagnostics, static fn (Diagnostic $diagnostic): bool => $diagnostic->code === 'validation.rule-unrecoverable'),
    ));

    // …and `secret`, which nobody declared, is the one the note is still about.
    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('"secret"');
});

it('does not call a class-recovered field omitted that a declaration puts in the body', function (): void {
    $context = declaredRulesContext(
        SuppressibleRulesData::class.'::rules',
        "return ['file' => [fn () => true], 'secret' => [fn () => true]];",
        [new BodyParameter(name: 'file', type: 'object')],
    );

    (new RulesFromClass)->analyse($context, SuppressibleRulesData::class);

    $messages = recoveryNotices($context, 'validation.rule-unrecoverable');

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('"secret"');
});

it('reads the declaration the request type writes about itself, not only the action bag', function (): void {
    $context = declaredRulesContext(
        DeclaredRulesData::class.'::rules',
        "return ['file' => [fn () => true], 'secret' => [fn () => true]];",
        [],
    );

    (new RulesFromClass)->analyse($context, DeclaredRulesData::class);

    $messages = recoveryNotices($context, 'validation.rule-unrecoverable');

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('"secret"');
});

it('keeps asking where the declaration reaches a different field', function (): void {
    $context = declaredRulesContext(
        SuppressibleRulesData::class.'::rules',
        "return ['file' => [fn () => true], 'secret' => [fn () => true]];",
        [new BodyParameter(name: 'elsewhere', type: 'object')],
    );

    (new RulesFromClass)->analyse($context, SuppressibleRulesData::class);

    expect(recoveryNotices($context, 'validation.rule-unrecoverable'))->toHaveCount(2);
});

it('keeps asking at a read verb, where a body declaration reaches nothing', function (): void {
    $context = declaredRulesContext(
        SuppressibleRulesData::class.'::rules',
        "return ['file' => [fn () => true], 'secret' => [fn () => true]];",
        [new BodyParameter(name: 'file', type: 'object')],
        verb: 'GET',
    );

    (new RulesFromClass)->analyse($context, SuppressibleRulesData::class);

    expect(recoveryNotices($context, 'validation.rule-unrecoverable'))->toHaveCount(2);
});

it('does not call a constraint left off that a declaration overwrites anyway', function (): void {
    $context = declaredRulesContext(
        CustomRuleRequest::class.'::rules',
        <<<'PHP'
        return [
            'status' => ['required', \Illuminate\Validation\Rule::in('any', ...$this->statuses())],
            'visibility' => ['required', \Illuminate\Validation\Rule::in('draft', ...$this->hidden())],
        ];
        PHP,
        [new BodyParameter(name: 'status', type: 'string')],
    );

    (new RulesFromClass)->analyse($context, CustomRuleRequest::class);

    $messages = recoveryNotices($context, 'validation.rule-values-unread');

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('"visibility"');
});
