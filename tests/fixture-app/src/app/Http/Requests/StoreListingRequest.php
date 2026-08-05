<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ListingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A FormRequest whose `rules()` mixes a pipe-string rule with a `Rule::enum(...)` factory descriptor,
 * so the real-engine proof shows the descriptor path recovering the enum's backing values + FQCN
 * inside a FormRequest — the case ShapeToRuleSet alone dropped silently (validation §1). The
 * `priority` field additionally chains `->only([...])` off the enum descriptor, proving the
 * chained-call fold narrows the recovered case list (validation §4 #10). It also carries a
 * closure-ruled field to prove the unrecoverable-field diagnostic path. Only ever reflected.
 */
final class StoreListingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:120',
            'status' => ['required', Rule::enum(ListingStatus::class)],
            'priority' => ['nullable', Rule::enum(ListingStatus::class)->only([ListingStatus::Open, ListingStatus::Closed])],
            'callback' => [function (): void {}],
        ];
    }
}
