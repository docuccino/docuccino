<?php

declare(strict_types=1);

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;

/**
 * A real lorisleiva/laravel-actions action for the fixture app. The real engine analyses its literal
 * rules() array into a constant array shape, which the laravel-actions integration recovers end-to-end
 * to a RuleSet via ShapeToRuleSet. Exercises the recovery half against the ACTUAL engine (not a stub)
 * — spike-d / Phase 5c M2.
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
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return ['id' => 1];
    }
}
