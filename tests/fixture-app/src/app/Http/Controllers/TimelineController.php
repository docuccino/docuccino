<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Timeline\CommentEntry;
use App\Timeline\StatusChangeEntry;
use App\Timeline\TimelineEntry;
use Illuminate\Http\JsonResponse;

/** A timeline answered as a list typed by its sealed interface, and one entry chosen at runtime. */
final class TimelineController
{
    public function index(): JsonResponse
    {
        /** @var list<TimelineEntry> $entries */
        $entries = [new CommentEntry('ada', 'Looks good'), new StatusChangeEntry('open', 'closed')];

        return response()->json(['data' => $entries]);
    }

    public function latest(bool $commented): CommentEntry|StatusChangeEntry
    {
        return $commented ? new CommentEntry('ada', 'Looks good') : new StatusChangeEntry('open', 'closed');
    }
}
