<?php

declare(strict_types=1);

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;

/**
 * A real lorisleiva/laravel-actions action for the fixture app. The real engine analyses its literal
 * rules() array into a constant array shape, which the laravel-actions integration recovers end-to-end
 * to a RuleSet via ShapeToRuleSet. Exercises the recovery half against the ACTUAL engine (not a stub)
 * — spike-d / Phase 5c M2.
 *
 * It also defines jsonResponse() the way laravelactions.com documents it — a wrapper the package's
 * controller decorator calls INSTEAD of returning handle()'s value when the client expects JSON. Its
 * return type is therefore the true 200 wire shape (a `{data, meta}` envelope), distinct from handle()'s
 * bare `{id}`, so the fixture proves the success-body redirect against the real engine.
 */
final class PublishArticleAction
{
    use AsAction;

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:100',
            'body' => 'required|string',
        ];
    }

    /**
     * Publish an article.
     *
     * @return array{id: int}
     */
    public function handle(): array
    {
        return ['id' => 1];
    }

    /**
     * Wrap the action result in an envelope for JSON clients.
     *
     * @param  array{id: int}  $article
     * @return array{data: array{id: int}, meta: array{published: bool}}
     */
    public function jsonResponse(array $article): array
    {
        return ['data' => $article, 'meta' => ['published' => true]];
    }
}
