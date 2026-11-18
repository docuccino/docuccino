<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ListingPriority;
use Illuminate\Database\Eloquent\Model;

/**
 * A model that stores its priority as the integer an enum is backed by and reads it back through `from()`,
 * as a column predating the enum cast is read. Only ever reflected — never queried.
 *
 * @property int $id
 * @property int $priority_code The stored priority, one of ListingPriority's values.
 */
class Consignment extends Model
{
    public function priority(): ListingPriority
    {
        return ListingPriority::from($this->priority_code);
    }
}
