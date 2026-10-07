<?php

declare(strict_types=1);

namespace App\Problems;

/** A notice that drops the title its parent constructor assigned when it is raised anonymously. */
final class UntitledNotice extends NoticeProblem
{
    public function __construct(string $title, bool $anonymous)
    {
        parent::__construct($title);

        if ($anonymous) {
            unset($this->title);
        }
    }
}
