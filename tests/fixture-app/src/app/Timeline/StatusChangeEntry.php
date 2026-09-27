<?php

declare(strict_types=1);

namespace App\Timeline;

final readonly class StatusChangeEntry implements TimelineEntry
{
    public EntryType $type;

    public function __construct(public string $from, public string $to)
    {
        $this->type = EntryType::StatusChange;
    }
}
