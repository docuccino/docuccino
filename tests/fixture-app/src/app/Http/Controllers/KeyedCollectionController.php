<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Ledger;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

/**
 * Collections returned bare and through `response()->json()`, keyed and ordered the ways an application
 * keys and orders them. No return types beyond the framework's own: each key type comes from Larastan's
 * collection stubs, and a model's own `newCollection()` is typed as the framework's collection.
 */
class KeyedCollectionController extends Controller
{
    public function listed()
    {
        return Product::all();
    }

    public function queried()
    {
        return Product::query()->where('id', '>', 1)->get();
    }

    public function plucked()
    {
        return Product::query()->pluck('sku');
    }

    public function mapped()
    {
        return Product::all()->map(fn (Product $product): string => $product->sku);
    }

    public function filtered()
    {
        return Product::all()->filter(fn (Product $product): bool => $product->id > 1);
    }

    public function filteredValues()
    {
        return Product::all()->filter(fn (Product $product): bool => $product->id > 1)->values();
    }

    public function sorted()
    {
        return Product::all()->sortBy('sku');
    }

    public function sortedValues()
    {
        return Product::all()->sortBy('sku')->values();
    }

    public function held(Collection $products)
    {
        return $products;
    }

    public function keyedBySku()
    {
        return Product::all()->keyBy(fn (Product $product): string => $product->sku);
    }

    public function mappedBySku()
    {
        return Product::all()->mapWithKeys(fn (Product $product): array => [$product->sku => $product->id]);
    }

    public function keyedByAttribute()
    {
        return Product::all()->keyBy('sku');
    }

    public function groupedByAttribute()
    {
        return Product::all()->groupBy('sku');
    }

    public function jsonKeyedBySku(): JsonResponse
    {
        return response()->json(Product::all()->keyBy(fn (Product $product): string => $product->sku));
    }

    public function keyedModel()
    {
        return Ledger::all();
    }
}
