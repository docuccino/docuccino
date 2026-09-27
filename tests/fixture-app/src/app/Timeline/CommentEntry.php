<?php

declare(strict_types=1);

namespace App\Timeline;

final readonly class CommentEntry implements TimelineEntry
{
    public EntryType $type;

    public function __construct(public string $author, public string $body)
    {
        $this->type = EntryType::Comment;
    }
}
