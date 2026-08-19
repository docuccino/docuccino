<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ListingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A FormRequest that writes some of a rule's values at the rule and gets the rest from a call — the same
 * loss as a spread, in the shape an app reaches for first, and the one a reader watching only for spreads
 * walks straight past. `status` accepts two values and writes one of them; `priority` allows two enum
 * cases and names one. Publishing either half makes a generated client reject what the endpoint accepts.
 * `visibility` states everything at the rule and is the control. Only ever reflected.
 */
final class UnreadChoicesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'visibility' => ['required', Rule::in('public', 'private')],
            'status' => ['required', Rule::in('any', $this->fallbackStatus())],
            'priority' => ['nullable', Rule::enum(ListingStatus::class)->only([ListingStatus::Open, $this->alsoAllowed()])],
        ];
    }

    private function fallbackStatus(): string
    {
        return config()->string('listings.fallback_status');
    }

    private function alsoAllowed(): ListingStatus
    {
        return ListingStatus::Closed;
    }
}
