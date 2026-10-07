<?php

declare(strict_types=1);

namespace App\Problems;

use Symfony\Component\HttpFoundation\Response;

/** A rendered problem whose members may be overwritten by name from the attributes it is given. */
final class RefilledProblem extends RenderedProblem
{
    use FillsAttributes;

    /** @param  array<string, mixed>  $attributes */
    public function __construct(Response $rendered, array $attributes)
    {
        parent::__construct($rendered);

        $this->fill($attributes);
    }
}
