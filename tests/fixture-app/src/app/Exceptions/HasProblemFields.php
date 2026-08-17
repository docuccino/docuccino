<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Marks a portal error that can say which submitted fields it rejected. */
interface HasProblemFields
{
    /** @return list<string> */
    public function fields(): array;
}
