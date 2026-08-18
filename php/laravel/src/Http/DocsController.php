<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Http;

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\ViewerContext;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Runtime\DocumentCache;
use Docuccino\Laravel\Support\Paths;
use Docuccino\Laravel\Viewer\ViewerDrivers;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * The runtime viewer endpoints: the driver's HTML page, the `.json` spec (per `viewer.source`:
 * generate | artifact | cache), and the driver's bundled asset. Which driver renders is
 * `viewer.driver` ({@see ViewerDrivers}); every one of them arrives here, and all three endpoints go
 * through {@see authorize()} first — a configured `viewer.gate` ability, otherwise local environment
 * only. That ordering is the gate's whole guarantee: no driver can be reached without passing it,
 * because nothing but this controller ever calls one.
 */
final class DocsController
{
    public function __construct(
        private readonly DocumentBuilder $builder,
        private readonly ViewerDrivers $drivers,
    ) {}

    public function show(string $document): Response
    {
        $config = $this->config($document);
        $this->authorize($config);

        return $this->response($config, $this->drivers->for($config)->render(new ViewerContext($config)));
    }

    public function spec(string $document, TypeEngine $engine, DocumentCache $cache): Response
    {
        $config = $this->config($document);
        $this->authorize($config);

        $source = $config->viewer['source'] ?? 'generate';
        $json = match ($source) {
            'artifact' => $this->fromArtifact($config, $engine),
            'cache' => $cache->get($document) ?? $this->coldCacheFallback($document, $engine),
            default => $this->generate($document, $engine),
        };

        return new Response($json, 200, ['Content-Type' => 'application/json']);
    }

    private function generate(string $document, TypeEngine $engine): string
    {
        return (new OpenApi32Emitter)->emit($this->builder->build($document, $engine)->document);
    }

    /**
     * A cold cache generates rather than serving an empty document, and warns so the missed
     * `docuccino:cache` warm-up is visible instead of silently degrading.
     */
    private function coldCacheFallback(string $document, TypeEngine $engine): string
    {
        Log::warning(sprintf(
            'Docuccino viewer "%s" is configured with source=cache but the cache is cold; generating on the fly. Run `docuccino:cache` to warm it.',
            $document,
        ));

        return $this->generate($document, $engine);
    }

    /**
     * One of the active driver's own assets. The driver publishes the name → file map, so a name that
     * driver does not publish is a 404 rather than a path this route resolves — switching drivers
     * closes the previous one's asset with it.
     */
    public function asset(Request $request): Response
    {
        // Read by NAME, not by signature position: this is the one viewer route with a URI parameter,
        // and Laravel appends a route's `defaults` after its URI parameters, so positional binding
        // would hand `$document` the asset name.
        $config = $this->config($this->routeParameter($request, 'document'));
        $this->authorize($config);

        $path = $this->drivers->asset($config, $this->routeParameter($request, 'asset'));
        if ($path === null) {
            abort(404);
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            // Serving a blank viewer silently makes "the docs page is empty" undiagnosable; log it.
            Log::warning(sprintf('Docuccino viewer asset could not be read at "%s"; serving an empty body.', $path));
            $contents = '';
        }

        return new Response($contents, 200, [
            'Content-Type' => 'application/javascript',
            // The bundle only changes on package upgrade, so cache it immutably and skip re-reading
            // megabytes on every viewer load.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * A driver renders `mixed` — the contract is framework-agnostic — so this adapter accepts the two
     * things it can serve: HTML, or a response the driver built itself. Anything else is a driver bug,
     * and one that would otherwise show up as a blank page with no explanation anywhere.
     */
    private function response(DocumentConfig $config, mixed $rendered): Response
    {
        if ($rendered instanceof Response) {
            return $rendered;
        }

        if (is_string($rendered)) {
            return new Response($rendered, 200, ['Content-Type' => 'text/html']);
        }

        Log::warning(sprintf(
            'Docuccino viewer "%s" rendered a %s; a driver must return HTML or an Illuminate response. Serving an empty page.',
            $config->key,
            get_debug_type($rendered),
        ));

        return new Response('', 200, ['Content-Type' => 'text/html']);
    }

    /** A route parameter by name, empty when it is absent or not a string. */
    private function routeParameter(Request $request, string $name): string
    {
        $value = $request->route($name);

        return is_string($value) ? $value : '';
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

    private function fromArtifact(DocumentConfig $config, TypeEngine $engine): string
    {
        $target = ViewerArtifact::of($config);
        if ($target === null) {
            // Every configured target is something the viewer cannot serve (a Postman collection, a
            // YAML file). Generating beats serving bytes the browser will choke on, and the warning
            // makes the mismatch visible instead of leaving an empty page to diagnose.
            Log::warning(sprintf(
                'Docuccino viewer "%s" is configured with source=artifact but no export target holds JSON it can serve; generating on the fly.',
                $config->key,
            ));

            return $this->generate($config->key, $engine);
        }

        $absolute = Paths::absolute($target->path, base_path());
        $contents = @file_get_contents($absolute);
        if ($contents === false) {
            // Unlike the branch above, this one does NOT generate: a target that could be served but
            // isn't there yet is the one case `artifact` exists to make impossible — the source is
            // chosen so no request ever re-analyses, and an unshipped file must not turn a viewer hit
            // into a build on a production box. So the body stays empty and the log carries the whole
            // diagnosis, since "the docs page is empty" is otherwise undiagnosable.
            Log::warning(sprintf(
                'Docuccino viewer "%s" is configured with source=artifact but "%s" could not be read; serving an empty body. Run `docuccino:export` and ship the file with your release.',
                $config->key,
                $absolute,
            ));

            return '';
        }

        // A UIR artifact (the `uir` field) is re-emitted as OAS — the viewer expects OAS, and a UIR's
        // internal x-docuccino provenance must never reach the browser. Plain OpenAPI streams through.
        $decoded = json_decode($contents, true);
        if (is_array($decoded) && isset($decoded['uir'])) {
            /** @var array<string, mixed> $decoded */
            return (new OpenApi32Emitter)->emit(UirDocument::fromArray($decoded));
        }

        return $contents;
    }
}
