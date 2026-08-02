<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Laravel\Tests\Fixtures\ApiResources\ArticleJsonApiResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ArticleResource;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ArticleData;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Workbench routes exercising the Phase-4 integrations end-to-end through the pipeline (the golden
 * build reflects these; it never dispatches them, so the bodies are inert). The return/param shapes
 * are supplied by the stub {@see WorkbenchEngine}, standing in for
 * what the real PHPStan engine would recover.
 */
final class IntegrationsController
{
    /** Spatie Data request (body from the Data class) + response, under a folded 201. */
    public function storeArticle(ArticleData $data): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** An anonymous resource collection → array of the item schema. */
    public function listArticleResources(): AnonymousResourceCollection
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** A single API resource whose whenLoaded fields become optional. */
    public function showArticleResource(string $id): ArticleResource
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** A first-party JSON:API resource → JSON:API document + include/fields query params. */
    public function showJsonApiArticle(string $id): ArticleJsonApiResource
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** An Eloquent model → object schema from columns + casts + visible/hidden. */
    public function showWidget(string $id): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** A 204 No Content response (noContent()). */
    public function destroyWidget(string $id): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** Distinct return paths carrying distinct statuses (200 + 202). */
    public function storeReport(): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }
}
