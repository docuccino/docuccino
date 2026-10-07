<?php

declare(strict_types=1);

namespace App\Problems;

/** A problem other problems extend, filled from whatever attributes it is given. */
class AttributedProblem
{
    use FillsAttributes;

    public string $title;

    public string $detail;

    /** @param  array<string, mixed>  $attributes */
    public function __construct(array $attributes)
    {
        $this->fill($attributes);
    }
}
