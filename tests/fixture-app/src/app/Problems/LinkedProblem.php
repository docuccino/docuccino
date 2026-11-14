<?php

declare(strict_types=1);

namespace App\Problems;

use JsonSerializable;

/** A problem that keeps the slug of its type and sends it as the address of the page describing it. */
class LinkedProblem implements JsonSerializable
{
    public readonly string $type;

    public function __construct(public readonly int $status, public readonly string $title)
    {
        $this->type = 'gone';
    }

    /** @return array{type: string, title: string, status: int} */
    public function jsonSerialize(): array
    {
        return ['type' => 'https://errors.example/'.$this->type, 'title' => $this->title, 'status' => $this->status];
    }
}
