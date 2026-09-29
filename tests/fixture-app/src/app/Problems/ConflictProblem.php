<?php

declare(strict_types=1);

namespace App\Problems;

/** A problem with a constructor of its own, handing the inherited member to the parent's. */
final class ConflictProblem extends BaseProblem
{
    public string $conflictsWith;

    public function __construct(string $conflictsWith)
    {
        parent::__construct('Conflict');
        $this->conflictsWith = $conflictsWith;
    }
}
