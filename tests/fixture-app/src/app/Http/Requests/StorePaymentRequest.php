<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\OpaqueSignature;
use App\Rules\SortCode;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A FormRequest validating with rule OBJECTS: `sort_code` by a rule carrying `#[RuleSchema]`, `signature`
 * by one that carries nothing. The first is documented from the attribute, the second stays
 * unrecoverable. Only ever reflected.
 */
final class StorePaymentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => 'required|integer|min:1',
            'sort_code' => ['required', new SortCode],
            'signature' => new OpaqueSignature,
        ];
    }
}
