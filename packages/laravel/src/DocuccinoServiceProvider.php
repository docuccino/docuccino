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
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Provenance\LaravelSourcePathResolver;
use Docuccino\Laravel\Registry\ExtensionRegistry;
use Docuccino\Laravel\Runtime\DocumentCache;
use Illuminate\Contracts\Foundation\Application;
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
}
