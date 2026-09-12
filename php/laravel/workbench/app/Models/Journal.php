<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A model in the shape an application that picked its own date format leaves behind: a
 * `serializeDate()` override, and `php artisan ide-helper:models` tags typing every date column with
 * the Carbon class the attribute holds. It is bound on one of those columns, so the document gives a
 * `format` up in the response body and in the path parameter both. Never queried.
 *
 * @property int $id
 * @property string $title
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon $filed_on
 */
final class Journal extends Model
{
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'filed_on' => 'datetime',
    ];

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('d/m/Y');
    }
}
