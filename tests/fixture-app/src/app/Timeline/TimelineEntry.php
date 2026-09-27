<?php

declare(strict_types=1);

namespace App\Timeline;

/**
 * One entry on a record's activity timeline.
 *
 * @phpstan-sealed CommentEntry|StatusChangeEntry
 */
interface TimelineEntry {}
