<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A FormRequest typing its authenticated user with a `@method` tag, the idiom for telling the analyser
 * which model `user()` returns. `user()` is a real method of the request, so PHP calls it and never
 * reaches `__call()`; `wantsCsv()` names no method of the request, so PHP forwards it.
 *
 * @method User user($guard = null)
 * @method bool wantsCsv() a macro, registered at boot
 */
final class AccountRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
