<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ListingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A FormRequest that writes some of a rule's values at the rule and spreads the rest in from a method —
 * what an app does as soon as one list of choices is shared between endpoints, or read off an enum. The
 * half that is written is the hazard: a build that reads only those publishes a SHORTER list of legal
 * values than the endpoint accepts, and a client generated from it rejects the rest. `visibility` states
 * everything at the rule and is the control. Only ever reflected.
 */
final class SpreadChoicesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'visibility' => ['required', Rule::in('public', 'private')],
            'status' => ['required', Rule::in('any', ...$this->statuses())],
            'priority' => ['nullable', Rule::enum(ListingStatus::class)->only(ListingStatus::Open, ...$this->alsoAllowed())],
        ];
    }

    /**
     * @return list<string>
     */
    private function statuses(): array
    {
        return array_map(static fn (ListingStatus $case): string => $case->value, ListingStatus::cases());
    }

    /**
     * @return list<ListingStatus>
     */
    private function alsoAllowed(): array
    {
        return [ListingStatus::Closed];
    }
}
