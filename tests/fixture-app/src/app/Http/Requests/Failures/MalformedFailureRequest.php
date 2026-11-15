<?php

declare(strict_types=1);

namespace App\Http\Requests\Failures;

use App\Exceptions\SubmissionMalformedException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/** Answers a failed validation with an exception of the application's own, which states a 400. */
final class MalformedFailureRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['title' => ['required', 'string']];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new SubmissionMalformedException($validator->errors()->first());
    }
}
