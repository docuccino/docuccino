<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Adds the one field this problem carries and inherits everything else, response included — the shape an
 * app lands on once a second problem payload shares the first one's envelope. Only ever analysed.
 */
class InheritedProblemData extends BaseProblemData
{
    public function __construct(
        string $type,
        int $status,
        public string $detail,
    ) {
        parent::__construct($type, $status);
    }
}
