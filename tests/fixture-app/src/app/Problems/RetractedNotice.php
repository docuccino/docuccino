<?php

declare(strict_types=1);

namespace App\Problems;

/** A notice that drops the detail its own constructor assigned when there is nothing more to say. */
final class RetractedNotice
{
    public string $title;

    public string $detail;

    public function __construct(string $title, string $detail)
    {
        $this->title = $title;
        $this->detail = $detail;

        if ($detail === $title) {
            unset($this->detail);
        }
    }
}
