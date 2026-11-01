<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ListingStatus;
use App\Rules\NoProfanity;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * A FormRequest whose fields each carry one rule the build cannot read beside rules it can. A rule object
 * or a closure can only refuse values, so `title` and `slug` keep the rest of their rules. A conditional
 * rule and a rule chosen at runtime can be `nullable` or `sometimes`, so `summary` and `tier` keep none.
 * `status` is a framework rule object narrowed by a chained call, which reads. Only ever reflected.
 */
final class UnreadRulesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120', new NoProfanity],
            'slug' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if ($value === 'admin') {
                    $fail('The :attribute is reserved.');
                }
            }],
            'status' => ['required', (new Enum(ListingStatus::class))->only([ListingStatus::Open, ListingStatus::Closed])],
            'summary' => ['string', Rule::when($this->isMethod('PATCH'), ['nullable'])],
            'tier' => [$this->isMethod('PATCH') ? 'sometimes' : 'required', 'string'],
        ];
    }
}
