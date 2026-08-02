<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A minimal Eloquent model used only for route-model binding in the workbench routes; it is never
 * queried (the pipeline reflects the bound type, it does not dispatch the route).
 */
final class Form extends Model
{
    protected $guarded = [];
}
