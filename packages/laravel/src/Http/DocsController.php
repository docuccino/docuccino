<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Http;

use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\ViewerContext;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Runtime\DocumentCache;
use Docuccino\Laravel\Viewer\ScalarViewer;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The runtime viewer endpoints (design §Multiple documents / §5 serving): the Scalar HTML page,
 * the `.json` spec (served per `viewer.source`: generate | artifact | cache), and the locally
 * bundled Scalar asset. Every route is guarded by {@see authorize()} — a configured `viewer.gate`
 * ability, else local environment only.
 */
final class DocsController
{
    public function __construct(
        private readonly DocumentBuilder $builder,
        private readonly ScalarViewer $viewer,
    ) {}

    public function show(string $document): Response
    {
        $config = $this->config($document);
        $this->authorize($config);

        return new Response($this->viewer->render(new ViewerContext($config)), 200, ['Content-Type' => 'text/html']);
    }

    public function spec(string $document, TypeEngine $engine, DocumentCache $cache): Response
    {
        $config = $this->config($document);
        $this->authorize($config);

        $source = $config->viewer['source'] ?? 'generate';
        $json = match ($source) {
            'artifact' => $this->fromArtifact($config),
            'cache' => $cache->get($document) ?? '',
            default => (new OpenApi32Emitter)->emit($this->builder->build($document, $engine)->document),
        };

        return new Response($json, 200, ['Content-Type' => 'application/json']);
    }

    public function asset(): Response
    {
        $path = dirname(__DIR__, 2).'/resources/js/scalar.standalone.js';
        $contents = @file_get_contents($path);

        return new Response($contents === false ? '' : $contents, 200, ['Content-Type' => 'application/javascript']);
    }

    private function config(string $document): DocumentConfig
    {
        abort_unless($this->builder->hasDocument($document), 404);

        return $this->builder->config($document);
    }

    private function authorize(DocumentConfig $config): void
    {
        $gate = $config->viewer['gate'] ?? null;

        $allowed = is_string($gate) && $gate !== ''
            ? Gate::allows($gate)
            : app()->environment('local') === true;

        abort_unless($allowed, 403);
    }

    private function fromArtifact(DocumentConfig $config): string
    {
        $export = is_array($config->raw['export'] ?? null) ? $config->raw['export'] : [];
        $path = is_string($export['path'] ?? null) ? $export['path'] : 'docs/openapi.json';
        $absolute = str_starts_with($path, '/') ? $path : base_path($path);
        $contents = @file_get_contents($absolute);

        return $contents === false ? '' : $contents;
    }
}
