<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A uuid-keyed model the real-engine foreign-key proof points at: `Shipment::depot()` defaults its
 * foreign key to `depot_id`, so an exact filter on that column types off THIS model's `HasUuids` key.
 * Only ever reflected — never queried.
 *
 * @property string $id  The uuid primary key.
 * @property string $name The depot's display name.
 */
class Depot extends Model
{
    use HasUuids;
}
