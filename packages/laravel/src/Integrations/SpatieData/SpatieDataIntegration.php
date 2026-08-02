<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

/**
 * The single entry point for the `spatie/laravel-data` integration (the documented template for a
 * conditional integration): it lists the extensions the integration contributes — the {@see DataSchema}
 * type mapper (Data classes → hoisted component schemas) and the {@see DataRequestExtension}
 * (Data action params → request body/query). The service provider spreads these into the default set
 * only when `Spatie\LaravelData\Data` exists (`class_exists` guard), so docuccino/laravel never
 * hard-requires the package.
 */
final class SpatieDataIntegration
{
    /**
     * Whether the host app has `spatie/laravel-data` installed.
     */
    public static function installed(): bool
    {
        return class_exists(DataClassReflector::DATA);
    }

    /**
     * @return list<class-string>
     */
    public static function extensions(): array
    {
        return [
            DataSchema::class,
            DataRequestExtension::class,
        ];
    }
}
