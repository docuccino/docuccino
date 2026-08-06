<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\ArticleData;
use App\Models\User;
use Spatie\LaravelData\PaginatedDataCollection;

/**
 * Real-engine/docblock proof for the spatie collection item-type fix (A1). Kept in its own controller
 * so the poison/determinism harness's SpikeController analysis footprint is untouched.
 */
class PaginatedCollectionController extends Controller
{
    /**
     * A spatie paginated collection. Its generics are `@template TKey of array-key, @template TValue`,
     * so the recovered type carries TWO args — [int, ArticleData] — and the collection ITEM is the
     * LAST one. Reading typeArgs[0] would type the collection items as the integer key (the A1 bug).
     *
     * @return PaginatedDataCollection<int, ArticleData>
     */
    public function index(): PaginatedDataCollection
    {
        /** @var PaginatedDataCollection<int, ArticleData> $collection */
        $collection = ArticleData::collect(User::query()->paginate());

        return $collection;
    }
}
