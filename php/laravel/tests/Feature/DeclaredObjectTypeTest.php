<?php

declare(strict_types=1);

use Docuccino\Attributes\QueryParameter;
use Docuccino\Attributes\Response;
use Docuccino\Attributes\ResponseHeader;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Laravel\Extensions\AttributeParametersExtension;
use Docuccino\Laravel\Extensions\AttributeResponsesExtension;

/*
 * `object` written by hand in an attribute is the JSON word — an object whose keys are not enumerated —
 * rather than PHP's "an instance of something", because a declaration is a claim about the wire made by
 * the one person who knows. That vocabulary belongs to every reader of a hand-written type string, so
 * this states it once for all of them: the request-body reader is pinned beside its own behaviour in
 * AttributeRequestBodyTest, and the four here are the rest of that set.
 */

it('reads a declared object as the free-form map it is, in every reader of a hand-written type', function (object $attribute, OperationExtension $extension, Closure $read): void {
    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/things'),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet([$attribute]),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(typeToSchema: DefaultTypeMappers::all()),
    );

    $operation = new OperationDraft;
    $extension->handle($operation, $context);

    // The provenance a draft carries is not what this is about, and it rides on the schema at two of
    // the three sites.
    $schema = $read($operation->freeze());
    unset($schema['x-docuccino']);

    expect($schema)->toBe(['type' => 'object', 'additionalProperties' => []]);
})->with([
    'a #[Response] body' => [
        new Response(status: 200, type: 'object'),
        new AttributeResponsesExtension,
        static fn (object $operation): array => $operation->responses['200']->content['application/json']['schema'],
    ],
    'a #[QueryParameter] schema' => [
        new QueryParameter(name: 'filter', type: 'object'),
        new AttributeParametersExtension,
        static fn (object $operation): array => $operation->parameters[0]->toArray()['schema'],
    ],
    'a #[ResponseHeader] schema' => [
        new ResponseHeader(name: 'X-Meta', type: 'object'),
        new AttributeResponsesExtension,
        static fn (object $operation): array => $operation->responses['200']->headers['X-Meta']['schema'],
    ],
]);

/**
 * The fourth reader, end to end because that is the only way a webhook payload is read. It carries the
 * other half of the same change: `webhook.payload-unresolved` is what a payload resolving to no shape
 * raises, and a declared `object` now resolves — so the warning stops firing here and keeps firing for
 * `mixed` next door, which is the diagnostic's own row in the reference.
 */
it('reads a declared object webhook payload as a free-form map, and stops calling it unresolved', function (): void {
    app()->setBasePath(dirname(__DIR__, 2));
    bindStubEngine();

    $result = generateDocument(static function (array $raw): array {
        $raw['webhooks'] = ['dir' => 'tests/Fixtures/Webhooks/Declared'];

        return $raw;
    });

    $codes = array_map(static fn (Diagnostic $d): string => $d->code, $result->diagnostics);
    $body = $result->document->toArray()['webhooks']['declared.map']['post']['requestBody'];

    expect($body['content']['application/json']['schema'])->toBe(['type' => 'object', 'additionalProperties' => []])
        ->and($codes)->not->toContain('webhook.payload-unresolved');
});

/**
 * And the guard over the set itself, because the four above are a hand-written list: every reader of an
 * author's type string in the adapter asks for the declared reading. One that asks `parse()` instead is
 * back to the analyser's vocabulary for a word only the author can answer, and the row-per-reader tests
 * above cannot see a reader nobody added a row for.
 */
it('reads every hand-written type string in the adapter as a declaration', function (): void {
    $readers = [];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        dirname(__DIR__, 2).'/src',
        FilesystemIterator::SKIP_DOTS,
    ));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        if (! str_contains($source, 'TypeStringParser')) {
            continue;
        }

        $readers[$file->getFilename()] = substr_count($source, '->parse(');
    }

    // A scan that matched nothing would pass forever, so the count is asserted beside the zeroes: the
    // four readers, plus the notice that has to ask the same parser what a declared type resolves to.
    expect($readers)->toHaveCount(5)
        ->and(array_values(array_unique(array_values($readers))))->toBe([0]);
});
