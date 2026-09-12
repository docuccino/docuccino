<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * {@see Journal} without the `serializeDate()` override — the same `php artisan ide-helper:models`
 * tags typing every date column with the Carbon class the attribute holds, the same binding on one of
 * them — so what the two publish differs by the override and nothing else. Never queried.
 *
 * @property int $id
 * @property string $title
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon $recorded_on
 */
final class Almanac extends Model
{
    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected $dates = ['recorded_on'];
}
