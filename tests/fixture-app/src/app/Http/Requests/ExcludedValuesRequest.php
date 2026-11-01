<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ListingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\ArrayRule;
use Illuminate\Validation\Rules\NotIn;

/**
 * A FormRequest excluding the same values in every spelling Laravel accepts — the rule string, the
 * `Rule::notIn()` factory with one array or separate arguments, and the object it builds — beside
 * exclusions whose values are only known at runtime, and `Rule::array()` in both spellings. Only ever
 * reflected.
 */
final class ExcludedValuesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'handle' => ['required', 'string', 'not_in:admin,root'],
            'username' => ['required', 'string', Rule::notIn(['admin', 'root'])],
            'alias' => ['required', 'string', Rule::notIn('admin', 'root')],
            'nickname' => ['required', 'string', new NotIn(['admin', 'root'])],
            'status' => ['required', 'string', Rule::notIn(ListingStatus::cases())],
            'team' => ['required', 'string', Rule::notIn(collect(['admin', 'root']))],
            'settings' => ['required', Rule::array(['theme'])],
            'limits' => ['required', new ArrayRule(['daily'])],
        ];
    }
}
