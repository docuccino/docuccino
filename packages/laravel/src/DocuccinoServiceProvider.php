<?php

declare(strict_types=1);

namespace Docuccino\Laravel;

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Provenance\SourcePathResolver;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Console\CacheCommand;
use Docuccino\Laravel\Console\ClearCommand;
use Docuccino\Laravel\Console\DiffCommand;
use Docuccino\Laravel\Console\ExportCommand;
use Docuccino\Laravel\Console\ValidateCommand;
use Docuccino\Laravel\Engine\TypeEngineFactory;
use Docuccino\Laravel\Extensions\AttributeOverridesExtension;
use Docuccino\Laravel\Http\DocsController;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Pipeline\FragmentCache;
use Docuccino\Laravel\Provenance\LaravelSourcePathResolver;
use Docuccino\Laravel\Registry\ExtensionRegistry;
use Docuccino\Laravel\Runtime\DocumentCache;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * The Docuccino Laravel adapter service provider (spatie/laravel-package-tools): registers the
 * config, the export command, the late-bound {@see ExtensionRegistry} singleton (backing the
 * `Docuccino` facade), and the pipeline services with their filesystem-path contextual bindings.
 */
final class DocuccinoServiceProvider extends PackageServiceProvider
{
    public const string VERSION = '0.1.0';

    public function configurePackage(Package $package): void
    {
        $package
            ->name('docuccino')
            ->hasConfigFile()
            ->hasCommand(ExportCommand::class)
            ->hasCommand(ValidateCommand::class)
            ->hasCommand(DiffCommand::class)
            ->hasCommand(CacheCommand::class)
            ->hasCommand(ClearCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(ExtensionRegistry::class);

        // Provenance `source.file` paths are relativised against the app base path (design §4);
        // the resolver falls back to a composer-root walk for files outside it (the workbench).
        $this->app->bind(
            SourcePathResolver::class,
            fn (Application $app): SourcePathResolver => new LaravelSourcePathResolver($app->basePath()),
        );

        $this->app->when(DocumentConfigFactory::class)
            ->needs('$basePath')
            ->give(fn (): string => $this->app->basePath());

        $this->app->when(AttributeOverridesExtension::class)
            ->needs('$basePath')
            ->give(fn (): string => $this->app->basePath());

        $this->app->when(TypeEngineFactory::class)
            ->needs('$basePath')
            ->give(fn (): string => $this->app->basePath());

        $this->app->when(TypeEngineFactory::class)
            ->needs('$tmpDir')
            ->give(fn (): string => $this->app->storagePath('docuccino'));

        // The runtime document cache uses the configured Laravel cache store (null = default store).
        $this->app->when(DocumentCache::class)
            ->needs('$store')
            ->give(function (): ?string {
                $store = config('docuccino.cache.store');

                return is_string($store) ? $store : null;
            });

        $this->app->when(DocumentGenerator::class)
            ->needs('$generatorVersion')
            ->give(self::VERSION);

        // The OperationFragment cache (design §10): filesystem, off by default.
        $this->app->bind(FragmentCache::class, function (Application $app): FragmentCache {
            /** @var array<string, mixed> $cache */
            $cache = (array) config('docuccino.cache', []);
            $path = is_string($cache['path'] ?? null) ? $cache['path'] : $app->storagePath('docuccino/fragments');

            return new FragmentCache(
                enabled: (bool) ($cache['enabled'] ?? false),
                path: str_starts_with($path, '/') ? $path : $app->basePath($path),
                toolVersion: self::VERSION,
                specVersion: '1.0.0',
                identityVersion: 'v1',
            );
        });

        $this->app->when(DocumentBuilder::class)
            ->needs('$basePath')
            ->give(fn (): string => $this->app->basePath());

        // The engine is resolved from the container so tests (and users) can swap in a stub or the
        // NullTypeEngine; production builds it from config, degrading to null on boot failure.
        $this->app->bind(TypeEngine::class, static function (Application $app): TypeEngine {
            /** @var array<string, mixed> $config */
            $config = (array) config('docuccino.engine', []);

            return $app->make(TypeEngineFactory::class)->make($config);
        });
    }

    /**
     * Register the runtime viewer routes for each document that configures a `viewer.route`: the
     * Scalar HTML page, its `.json` spec, and the locally bundled Scalar asset. Guarding lives in
     * the controller (a `viewer.gate` ability, else local env only).
     */
    public function packageBooted(): void
    {
        // The master off-switch (security M3): when disabled, register no runtime endpoints at all.
        if (config('docuccino.enabled', true) === false) {
            return;
        }

        /** @var array<string, mixed> $documents */
        $documents = (array) config('docuccino.documents', []);

        foreach ($documents as $key => $document) {
            if (! is_array($document)) {
                continue;
            }

            $viewer = is_array($document['viewer'] ?? null) ? $document['viewer'] : [];
            $route = $viewer['route'] ?? null;
            if (! is_string($route) || $route === '') {
                continue;
            }

            $base = '/'.ltrim($route, '/');
            $middleware = self::viewerMiddleware($viewer);

            Route::get($base, [DocsController::class, 'show'])->middleware($middleware)->defaults('document', (string) $key);
            Route::get($base.'.json', [DocsController::class, 'spec'])->middleware($middleware)->defaults('document', (string) $key);
            Route::get($base.'/assets/scalar.js', [DocsController::class, 'asset'])->middleware($middleware);
        }
    }

    /**
     * The middleware stack for a document's viewer routes (security M1/M2): `viewer.middleware`,
     * defaulting to `['web', 'throttle:60,1']` so the spec endpoint is session-scoped and rate
     * limited out of the box.
     *
     * @param  array<array-key, mixed>  $viewer
     * @return list<string>
     */
    private static function viewerMiddleware(array $viewer): array
    {
        $configured = $viewer['middleware'] ?? null;
        if (! is_array($configured)) {
            return ['web', 'throttle:60,1'];
        }

        return array_values(array_filter($configured, 'is_string'));
    }
}
