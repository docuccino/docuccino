<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsExports;

/**
 * The export list endpoint. Every fact it publishes sits one hop further out than the last: the action in
 * the concern this controller imports, the default sort in the query class that action builds, the
 * allow-lists in the concern THAT class imports, and the sortable columns one file past those — five
 * files behind one route, with no annotation anywhere.
 */
class ExportListController extends Controller
{
    use ListsExports;
}
