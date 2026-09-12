<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * {@see Journal} without the `serializeDate()` override — the same `php artisan ide-helper:models`
 * tags typing every date column with the Carbon class the attribute holds, the same binding on one of
 * them — so what the two publish differs by the override and nothing else.
 *
 * `observed_on` is the column bound on a `date` cast: the response sends the date-time the framework
 * writes for it and the segment carries the date it is stored as, so the document shows both answers
 * for one column. Never queried.
 *
 * @property int $id
 * @property string $title
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon $recorded_on
 * @property Carbon $observed_on
 */
final class Almanac extends Model
{
    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected $dates = ['recorded_on'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'observed_on' => 'date',
    ];
}
