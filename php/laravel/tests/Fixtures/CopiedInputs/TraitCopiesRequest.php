<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;

/** A hook written under a name of the trait's own, which a request takes as its `prepareForValidation`. */
trait CopiesIdempotencyKey
{
    protected function copyKey(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
    }
}

/** Takes its hook from a trait, under an alias. */
final class TraitCopiesRequest extends FormRequest
{
    use CopiesIdempotencyKey { copyKey as prepareForValidation; }
}
