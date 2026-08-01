<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Inference\PhpStan\Throwing\KnownThrowers;

/**
 * The engine's analysis knobs. Descent bounds are separate on purpose: the
 * inference/trace side descends deeper (4) than exception flow (3) — domain
 * throws cluster within 2 hops and the vendor gate does most of the containment
 * (Spike C).
 */
final readonly class EngineConfig
{
    /**
     * @param  list<string>  $projectPaths  directories treated as descendable project code
     */
    public function __construct(
        public array $projectPaths,
        public KnownThrowers $knownThrowers,
        public int $traceDepth = 4,
        public int $throwDepth = 3,
        public int $fileBudget = 40,
    ) {}

    public static function forProject(string ...$projectPaths): self
    {
        return new self(array_values($projectPaths), KnownThrowers::default());
    }
}
