<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Watch;

/**
 * One rebuild for `docuccino:watch`. An interface so the loop can be driven without spawning
 * anything; {@see ArtisanBuildRunner} is what a real session runs.
 *
 * @internal
 */
interface BuildRunner
{
    /**
     * Run one build, streaming its output to the terminal, and answer its exit code.
     *
     * @param  string|null  $document  the `{document?}` argument as given, or null for every document
     * @param  string|null  $memoryLimit  the `--memory-limit` value as given, or null
     */
    public function build(?string $document, ?string $memoryLimit): int;
}
