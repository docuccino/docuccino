<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AccountRequest;
use App\Http\Resources\ArchiveResource;
use App\Http\Resources\CatalogueResource;
use App\Http\Resources\DraftResource;
use App\Http\Resources\JournalResource;
use App\Http\Resources\LedgerCollection;
use App\Http\Resources\LedgerResource;
use App\Http\Resources\ShelfResource;
use App\Http\Resources\SketchResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Collections of resources whose family overrides `newCollection()`, reached every way Laravel builds
 * one, beside a family that keeps the framework's.
 */
class ListedCollectionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CatalogueResource::collection(User::all());
    }

    public function paginated(): AnonymousResourceCollection
    {
        return CatalogueResource::collection(User::query()->paginate());
    }

    public function transformed(): AnonymousResourceCollection
    {
        return User::all()->toResourceCollection(CatalogueResource::class);
    }

    public function transformedPage(): AnonymousResourceCollection
    {
        return User::query()->paginate()->toResourceCollection(CatalogueResource::class);
    }

    public function declared(): AnonymousResourceCollection
    {
        return ShelfResource::collection(User::all());
    }

    public function featured(): AnonymousResourceCollection
    {
        return ShelfResource::featured(User::all());
    }

    public function archived(): AnonymousResourceCollection
    {
        return ArchiveResource::collection(User::all());
    }

    public function ledger(): LedgerCollection
    {
        return LedgerResource::collection(User::all());
    }

    public function drafts(): AnonymousResourceCollection
    {
        return DraftResource::collection(User::all());
    }

    public function journal(): ResourceCollection
    {
        return JournalResource::collection(User::all());
    }

    public function sketches(): AnonymousResourceCollection
    {
        return SketchResource::collection(User::all());
    }

    public function plain(): AnonymousResourceCollection
    {
        return UserResource::collection(User::all());
    }

    public function account(AccountRequest $request): JsonResponse
    {
        return new JsonResponse(['id' => $request->user()->id]);
    }

    public function export(AccountRequest $request): JsonResponse
    {
        return new JsonResponse(['csv' => $request->wantsCsv()]);
    }
}
