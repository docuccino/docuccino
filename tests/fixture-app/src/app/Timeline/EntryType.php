<?php

declare(strict_types=1);

namespace App\Timeline;

enum EntryType: string
{
    case Comment = 'comment';
    case StatusChange = 'status_change';
}
