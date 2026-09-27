<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reads request headers the way a FormRequest does: one in `prepareForValidation()`, which the framework runs
 * before the action, one in a method the action calls to build its input, and a credential in the gate.
 */
final class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->header('X-Service-Token') === config('services.orders.token');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['sku' => ['required', 'string']];
    }

    /**
     * @return array{sku: string, idempotency_key: string|null}
     */
    public function toInput(): array
    {
        return [
            'sku' => $this->string('sku')->value(),
            'idempotency_key' => $this->header('Idempotency-Key'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['channel' => $this->headers->get('X-Client-Channel', 'web')]);
    }
}
