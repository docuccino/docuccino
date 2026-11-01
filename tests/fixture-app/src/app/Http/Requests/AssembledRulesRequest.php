<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\NoProfanity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The same rules assembled in a variable before they are returned, which is the shape the literal
 * reading answers alone. Only ever reflected.
 */
final class AssembledRulesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:120', new NoProfanity],
            'summary' => ['string', Rule::when($this->isMethod('PATCH'), ['nullable'])],
        ];

        return $rules;
    }
}
