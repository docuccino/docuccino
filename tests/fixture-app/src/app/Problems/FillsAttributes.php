<?php

declare(strict_types=1);

namespace App\Problems;

/** Assigns each attribute to the property of the same name. */
trait FillsAttributes
{
    /** @param  array<string, mixed>  $attributes */
    protected function fill(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $this->{$key} = $value;
        }
    }
}
