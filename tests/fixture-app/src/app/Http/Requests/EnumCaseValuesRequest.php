<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ListingPriority;
use App\Enums\ListingStatus;
use App\Enums\ListingVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\NotIn;

/**
 * A FormRequest naming enum cases in its choice rules. Laravel casts a backed case to its backing value
 * and a pure one to its name before comparing, so each rule accepts or excludes those values and never
 * the case names of a backed enum. Only ever reflected.
 */
final class EnumCaseValuesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([ListingStatus::Open, ListingStatus::Draft])],
            'state' => ['required', new In([ListingStatus::Open, ListingStatus::Closed])],
            'stage' => ['required', Rule::in(ListingStatus::Open, ListingStatus::Closed)],
            'phase' => ['required', 'string', Rule::notIn([ListingStatus::Draft])],
            'step' => ['required', 'string', new NotIn([ListingStatus::Draft])],
            'any' => ['required', Rule::in([ListingStatus::Open, ListingStatus::Closed, ListingStatus::Draft])],
            'written' => ['required', 'in:'.ListingStatus::Open->value.','.ListingStatus::Closed->value],
            'priority' => ['required', Rule::in([ListingPriority::Low, ListingPriority::High])],
            'fallback' => ['required', Rule::in([ListingPriority::DEFAULT])],
            'label' => ['required', Rule::in([ListingPriority::LABEL])],
            'visibility' => ['required', Rule::in([ListingVisibility::Public])],
            'mixed' => ['required', Rule::in([ListingStatus::Open, 'archived'])],
            'only' => ['required', Rule::enum(ListingStatus::class)->only([ListingStatus::Open, ListingStatus::Draft])],
            'except' => ['required', Rule::enum(ListingPriority::class)->except(ListingPriority::Low)],
        ];
    }
}
