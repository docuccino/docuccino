<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Exceptions\ExportLockedException;
use Illuminate\Foundation\Http\FormRequest;

/** A form request carrying a guard the action calls, written to return `never` and `void`. */
final class RefusableRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function refuseNever(): never
    {
        throw new ExportLockedException(423, 'The export is locked.');
    }

    public function refuseVoid(): void
    {
        throw new ExportLockedException(423, 'The export is locked.');
    }
}
