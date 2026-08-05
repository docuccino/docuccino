<?php

declare(strict_types=1);

namespace App\Casts;

use App\Models\Product;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * A custom Eloquent caster the real-engine proof reflects: its `get()` return type (`float`) is what
 * the model's `price` attribute serialises as, recovered by the engine (audit eloquent #9).
 *
 * @implements CastsAttributes<float, float>
 */
final class Money implements CastsAttributes
{
    /**
     * @param  Product  $model
     * @param  array<string, mixed>  $attributes
     */
    public function get($model, string $key, $value, array $attributes): float
    {
        return (float) $value / 100;
    }

    /**
     * @param  Product  $model
     * @param  array<string, mixed>  $attributes
     */
    public function set($model, string $key, $value, array $attributes): int
    {
        return (int) round(((float) $value) * 100);
    }
}
