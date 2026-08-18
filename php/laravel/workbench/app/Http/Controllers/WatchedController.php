<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\DescriptionFromFile;
use Illuminate\Http\JsonResponse;

/**
 * A controller whose #[DescriptionFromFile] names a file of its own, so the watch suite can rewrite
 * that file mid-run — proving a rebuild after an edit says what a cold build says — without racing
 * the confinement suite over DescribedController's.
 */
final class WatchedController
{
    #[DescriptionFromFile('docuccino-watched.md')]
    public function index(): JsonResponse
    {
        return response()->json([]);
    }
}
