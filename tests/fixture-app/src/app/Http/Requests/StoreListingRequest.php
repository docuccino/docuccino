<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ListingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A FormRequest whose `rules()` mixes a pipe-string rule with a `Rule::enum(...)` factory descriptor,
 * so the real-engine proof shows the descriptor path recovering the enum's backing values + FQCN
 * inside a FormRequest — the case ShapeToRuleSet alone dropped silently (validation §1). It also
 * carries a closure-ruled field to prove the unrecoverable-field diagnostic path. Only ever reflected.
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
            'callback' => [function (): void {}],
        ];
    }
}
