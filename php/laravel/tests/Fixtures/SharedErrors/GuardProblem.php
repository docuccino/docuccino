<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

/** The one RFC 9457 problem document every arm of {@see GuardProblemRenderer} answers with. */
final readonly class GuardProblem
{
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public string $detail,
    ) {}
}
